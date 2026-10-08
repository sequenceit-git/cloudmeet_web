<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\HostAlreadyInMeetingException;
use App\Exceptions\HostSessionInvalidException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Meeting\CreateMeetingRequest;
use App\Http\Requests\Meeting\JoinMeetingRequest;
use App\Http\Requests\Meeting\ScheduleMeetingRequest;
use App\Http\Requests\Meeting\ValidateMeetingRequest;
use App\Models\Meeting;
use App\Models\MeetingHostSession;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\HostSessionService;
use App\Services\LiveKitService;
use App\Services\MeetingParticipantService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MeetingController extends Controller
{
    public function __construct(
        protected LiveKitService $liveKitService,
        protected HostSessionService $hostSessionService,
        protected MeetingParticipantService $participantService
    ) {}

    /**
     * Display a listing of meetings for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isGuest() && ! in_array($user->role, ['host', 'admin'], true)) {
            return $this->successResponse([], 'Guests do not have meeting dashboard access');
        }

        $meetings = Meeting::with('host:id,name,avatar_url')
            ->withCount('activeParticipants')
            ->where('host_id', $user->id)
            ->latest()
            ->paginate(15);

        return $this->successResponse($meetings, 'Meetings retrieved successfully');
    }

    /**
     * Create a new video meeting room.
     */
    public function store(CreateMeetingRequest $request): JsonResponse
    {
        $user = $request->user();

        if (in_array($user->role, ['host', 'admin'], true) || $user->isHost()) {
            if ($user->is_guest) {
                $user->is_guest = false;
                $user->save();
            }
        } elseif ($user->isGuest()) {
            return $this->errorResponse('Guests are not permitted to create or host meetings. Please log in.', 403);
        }

        $validated = $request->validated();

        $meetingCode = null;
        if (! empty($validated['meeting_code'])) {
            $rawCode = trim($validated['meeting_code']);
            $cleanDigits = preg_replace('/\D/', '', $rawCode);
            $formattedCode = (strlen($cleanDigits) === 6)
                ? substr($cleanDigits, 0, 3).'-'.substr($cleanDigits, 3, 3)
                : $rawCode;

            // Ensure no conflict with other hosts
            $isConflict = Meeting::where('meeting_code', $formattedCode)
                ->where('host_id', '!=', $user->id)
                ->exists();

            if (! $isConflict) {
                $meetingCode = $formattedCode;
            }
        }

        $meeting = null;
        $hostSession = null;

        try {
            DB::transaction(function () use ($user, $validated, $meetingCode, $request, &$meeting, &$hostSession) {
                User::where('id', $user->id)->lockForUpdate()->first();

                $existingMeeting = $meetingCode
                    ? Meeting::where('meeting_code', $meetingCode)->where('host_id', $user->id)->first()
                    : null;

                if ($existingMeeting) {
                    $meeting = $existingMeeting;
                    $meeting->update([
                        'title' => $validated['title'],
                        'passcode' => $validated['passcode'] ?? $meeting->passcode,
                        'is_active' => true,
                        'is_locked' => false,
                        'is_host_online' => true,
                        'started_at' => now(),
                        'ended_at' => null,
                    ]);
                } else {
                    $roomName = Meeting::generateRoomName();
                    $code = $meetingCode ?: Meeting::generateMeetingCode();

                    $meeting = Meeting::create([
                        'host_id' => $user->id,
                        'room_name' => $roomName,
                        'meeting_code' => $code,
                        'title' => $validated['title'],
                        'passcode' => $validated['passcode'] ?? null,
                        'is_active' => true,
                        'is_locked' => false,
                        'is_host_online' => true,
                        'max_participants' => $validated['max_participants'] ?? 12,
                        'started_at' => now(),
                    ]);
                }

                // Acquire host session lock (will throw HostAlreadyInMeetingException if invalid)
                $hostSession = $this->hostSessionService->acquireHostLock(
                    $user,
                    $meeting,
                    $request->input('host_session_token')
                );

                // Record or update host as participant
                MeetingParticipant::updateOrCreate(
                    [
                        'meeting_id' => $meeting->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'role' => 'host',
                        'joined_at' => now(),
                        'left_at' => null,
                    ]
                );
            });
        } catch (HostAlreadyInMeetingException $e) {
            return response()->json([
                'success' => false,
                'code' => 'HOST_ALREADY_IN_MEETING',
                'message' => $e->getMessage(),
                'data' => [
                    'active_meeting_code' => $e->getActiveMeetingCode(),
                ],
            ], 409);
        }

        if (! $meeting || ! $hostSession) {
            return $this->errorResponse('Failed to initialize meeting session', 500);
        }

        /** @var \App\Models\Meeting $meeting */
        /** @var \App\Models\MeetingHostSession $hostSession */

        // Explicitly pre-create room on LiveKit SFU
        try {
            $this->liveKitService->createRoom($meeting->room_name, [
                'empty_timeout' => 86400,
                'max_participants' => $meeting->max_participants,
            ]);
        } catch (\Throwable $e) {
            // LiveKit SFU room might already exist or SFU offline in test
        }

        // Generate LiveKit Host JWT Token
        $identity = $user->is_guest ? "guest_{$user->id}" : "user_{$user->id}";
        $token = $this->liveKitService->generateJoinToken(
            roomName: $meeting->room_name,
            identity: $identity,
            name: $user->name,
            isHost: true,
            role: 'host',
            metadata: [
                'role' => 'host',
                'is_host' => true,
                'user_id' => $user->id,
            ]
        );

        $meetingData = $meeting->toArray();
        $meetingData['passcode'] = $meeting->passcode;
        $meetingData['host'] = [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
        ];

        return $this->successResponse([
            'meeting' => $meetingData,
            'token' => $token,
            'livekit_token' => $token,
            'host_session_token' => $hostSession?->session_token,
            'room_name' => $meeting->room_name,
            'meeting_code' => $meeting->meeting_code,
            'identity' => $identity,
            'name' => $user->name,
            'role' => 'host',
            'is_host' => true,
            'livekit_url' => $this->liveKitService->getHost(),
        ], 'Meeting created successfully', 201);
    }

    /**
     * Schedule a future meeting.
     */
    public function schedule(ScheduleMeetingRequest $request): JsonResponse
    {
        $user = $request->user();

        if (in_array($user->role, ['host', 'admin'], true) || $user->isHost()) {
            if ($user->is_guest) {
                $user->is_guest = false;
                $user->save();
            }
        } elseif ($user->isGuest()) {
            return $this->errorResponse('Guests are not permitted to schedule meetings. Please log in.', 403);
        }

        $validated = $request->validated();

        $scheduledAt = null;
        if (! empty($validated['scheduled_at'])) {
            $scheduledAt = rescue(fn () => Carbon::parse($validated['scheduled_at']), null, false);
        } elseif (! empty($validated['start_time'])) {
            $scheduledAt = rescue(fn () => Carbon::parse($validated['start_time']), null, false);
        } elseif (! empty($validated['date'])) {
            $dateStr = $validated['date'].' '.($validated['time'] ?? '00:00:00');
            $scheduledAt = rescue(fn () => Carbon::parse($dateStr), null, false);
        }

        $roomName = Meeting::generateRoomName();
        $meetingCode = Meeting::generateMeetingCode();

        $meeting = Meeting::create([
            'host_id' => $user->id,
            'room_name' => $roomName,
            'meeting_code' => $meetingCode,
            'title' => $validated['title'],
            'passcode' => ! empty($validated['passcode']) ? $validated['passcode'] : null,
            'is_active' => true,
            'is_locked' => false,
            'is_host_online' => false,
            'waiting_room' => $request->boolean('waiting_room'),
            'max_participants' => $validated['max_participants'] ?? 12,
            'scheduled_at' => $scheduledAt ?? now()->addDay(),
            'started_at' => null,
        ]);

        // Explicitly pre-create room on LiveKit SFU
        try {
            $this->liveKitService->createRoom($roomName, [
                'empty_timeout' => 86400,
                'max_participants' => $meeting->max_participants,
            ]);
        } catch (\Throwable $e) {
            // LiveKit SFU room might already exist or SFU offline in test
        }

        $meetingData = $meeting->toArray();
        $meetingData['passcode'] = $meeting->passcode;
        $meetingData['requires_passcode'] = $meeting->hasPasscode();
        $meetingData['is_host'] = true;
        $meetingData['waiting_room'] = (bool) $meeting->waiting_room;
        $meetingData['status'] = 'upcoming';
        $meetingData['host'] = [
            'id' => $user->id,
            'name' => $user->name,
            'avatar_url' => $user->avatar_url,
        ];

        return $this->successResponse([
            'meeting' => $meetingData,
        ], 'Meeting scheduled successfully', 201);
    }

    /**
     * Get scheduled meetings for the authenticated user.
     */
    public function getScheduled(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isGuest() && ! in_array($user->role, ['host', 'admin'], true)) {
            return $this->successResponse([], 'Guests do not have meeting dashboard access');
        }

        $meetings = Meeting::with('host:id,name,avatar_url')
            ->withCount('activeParticipants')
            ->where('host_id', $user->id)
            ->where('is_active', true)
            ->whereNull('ended_at')
            ->orderByRaw('CASE WHEN scheduled_at IS NOT NULL THEN scheduled_at ELSE created_at END ASC')
            ->get()
            ->map(function ($meeting) use ($user) {
                $meetingArray = $meeting->toArray();
                $isHost = ($user->id === $meeting->host_id);
                $meetingArray['is_host'] = $isHost;
                $meetingArray['waiting_room'] = (bool) ($meeting->waiting_room ?? false);
                $meetingArray['requires_passcode'] = $meeting->hasPasscode();

                // Compute status: 'upcoming' | 'ongoing'
                // Persistent meetings never expire until deleted!
                $scheduledTs = $meeting->scheduled_at ? $meeting->scheduled_at->timestamp : null;
                $isUpcoming = $scheduledTs && ($scheduledTs > (now()->timestamp + 900));
                $status = $isUpcoming ? 'upcoming' : 'ongoing';

                $meetingArray['status'] = $status;

                if ($isHost) {
                    $meetingArray['passcode'] = $meeting->passcode;
                } else {
                    unset($meetingArray['passcode']);
                }

                return $meetingArray;
            });

        return $this->successResponse($meetings, 'Scheduled meetings retrieved successfully');
    }

    /**
     * Show meeting summary and public/participant status.
     */
    public function show(Request $request, string $meetingCode): JsonResponse
    {
        $meeting = $this->findMeetingByCode($meetingCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found', 404);
        }

        // Keep persistent meeting active
        if (! $meeting->is_active) {
            $meeting->update([
                'is_active' => true,
                'ended_at' => null,
            ]);
        }

        $meeting->loadCount('activeParticipants');
        $meeting->load('host:id,name,avatar_url');

        $user = $request->user();
        $isHost = $user && ($user->id === $meeting->host_id);

        $data = [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'meeting_code' => $meeting->meeting_code,
            'room_name' => $meeting->room_name,
            'is_active' => true,
            'is_locked' => $meeting->is_locked,
            'waiting_room' => (bool) $meeting->waiting_room,
            'max_participants' => $meeting->max_participants,
            'active_participants_count' => $meeting->active_participants_count,
            'started_at' => $meeting->started_at,
            'ended_at' => $meeting->ended_at,
            'requires_passcode' => $meeting->hasPasscode(),
            'is_host' => $isHost,
            'host' => $meeting->host,
        ];

        // Reveal passcode only if requester is host
        if ($isHost) {
            $data['passcode'] = $meeting->passcode;
        }

        return $this->successResponse($data, 'Meeting details retrieved successfully');
    }

    /**
     * Validate meeting existence, status, and passcode before joining.
     */
    public function validateMeeting(ValidateMeetingRequest $request): JsonResponse
    {
        $rawCode = $request->validated('code') ?? $request->validated('meeting_code') ?? '';
        $meeting = $this->findMeetingByCode($rawCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found. Please check the code or link.', 404);
        }

        // Keep persistent meeting active
        if (! $meeting->is_active) {
            $meeting->update([
                'is_active' => true,
                'ended_at' => null,
            ]);
        }

        if ($meeting->is_locked) {
            return $this->errorResponse('This meeting is currently locked by the host', 422, [
                'is_locked' => true,
            ]);
        }

        if ($meeting->hasPasscode()) {
            $passcode = $request->validated('passcode');
            if (empty($passcode)) {
                return $this->errorResponse('Passcode is required to join this meeting', 422, [
                    'requires_passcode' => true,
                    'meeting_code' => $meeting->meeting_code,
                    'title' => $meeting->title,
                ]);
            }

            if (! $meeting->verifyPasscode($passcode)) {
                return $this->errorResponse('Invalid meeting passcode', 422, [
                    'invalid_passcode' => true,
                    'requires_passcode' => true,
                    'meeting_code' => $meeting->meeting_code,
                    'title' => $meeting->title,
                ]);
            }
        }

        if ($meeting->activeParticipants()->count() >= $meeting->max_participants) {
            return $this->errorResponse('Meeting has reached maximum capacity', 422, [
                'room_full' => true,
            ]);
        }

        $isHostActive = $this->hostSessionService->isHostActive($meeting);

        return $this->successResponse([
            'valid' => true,
            'meeting_code' => $meeting->meeting_code,
            'room_name' => $meeting->room_name,
            'title' => $meeting->title,
            'is_active' => true,
            'is_locked' => $meeting->is_locked,
            'is_host_online' => $isHostActive,
            'requires_passcode' => $meeting->hasPasscode(),
        ], 'Meeting is valid and ready to join');
    }

    /**
     * Join an existing meeting room and receive a LiveKit token.
     */
    public function join(JoinMeetingRequest $request, string $meetingCode): JsonResponse
    {
        $meeting = $this->findMeetingByCode($meetingCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found. Please check the code or link.', 404);
        }

        $user = $request->user();
        $isHost = (! $user->isGuest() && $user->id === $meeting->host_id);
        $hostSession = null;

        if ($isHost) {
            try {
                DB::transaction(function () use ($user, $meeting, $request, &$hostSession) {
                    User::where('id', $user->id)->lockForUpdate()->first();

                    $hostSession = $this->hostSessionService->acquireHostLock(
                        $user,
                        $meeting,
                        $request->input('host_session_token')
                    );

                    $meeting->update([
                        'is_active' => true,
                        'is_host_online' => true,
                        'started_at' => $meeting->started_at ?? now(),
                        'ended_at' => null,
                    ]);
                });
            } catch (HostAlreadyInMeetingException $e) {
                return response()->json([
                    'success' => false,
                    'code' => 'HOST_ALREADY_IN_MEETING',
                    'message' => $e->getMessage(),
                    'data' => [
                        'active_meeting_code' => $e->getActiveMeetingCode(),
                    ],
                ], 409);
            }

            // Explicitly ensure room is pre-created on LiveKit SFU
            try {
                $this->liveKitService->createRoom($meeting->room_name, [
                    'empty_timeout' => 86400,
                    'max_participants' => $meeting->max_participants,
                ]);
            } catch (\Throwable $e) {
                // Room may already exist or SFU offline in testing
            }
        } else {
            if ($meeting->is_locked) {
                return $this->errorResponse('This meeting has been locked by the host', 403);
            }

            // Passcode verification for non-hosts
            if ($meeting->hasPasscode()) {
                $passcode = $request->validated('passcode');
                if (empty($passcode) || ! $meeting->verifyPasscode($passcode)) {
                    return $this->errorResponse('Invalid or missing meeting passcode', 403);
                }
            }

            // Capacity check (allow existing active participant to re-join/reconnect)
            $existingActive = MeetingParticipant::where('meeting_id', $meeting->id)
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->first();

            if (! $existingActive && $meeting->activeParticipants()->count() >= $meeting->max_participants) {
                return $this->errorResponse('Meeting has reached maximum participant limit', 422);
            }

            // Guest verification: check whether the host has an active, unexpired host session
            $isHostActive = $this->hostSessionService->isHostActive($meeting);

            if ($meeting->is_host_online !== $isHostActive) {
                $meeting->update(['is_host_online' => $isHostActive]);
            }

            // If host is NOT actively present, do NOT issue LiveKit token
            if (! $isHostActive) {
                return response()->json([
                    'success' => false,
                    'status' => 'waiting_for_host',
                    'code' => 'WAITING_FOR_HOST',
                    'message' => 'The host has not started the meeting yet. Please wait...',
                    'data' => [
                        'meeting_code' => $meeting->meeting_code,
                        'title' => $meeting->title,
                        'waiting_for_host' => true,
                    ],
                ], 200);
            }

            // Host is present: ensure meeting is active
            if (! $meeting->is_active || $meeting->ended_at !== null) {
                $meeting->update([
                    'is_active' => true,
                    'ended_at' => null,
                ]);
            }
        }

        $existingActive = MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->first();

        $role = $isHost ? 'host' : 'participant';

        // Record participant entry if not already logged
        if (! $existingActive) {
            MeetingParticipant::create([
                'meeting_id' => $meeting->id,
                'user_id' => $user->id,
                'role' => $role,
                'joined_at' => now(),
            ]);
        }

        // Determine participant display name (supports Chinese/non-ASCII and whitespace)
        $requestedName = $request->input('participant_name') ?: $request->input('name');
        if (! empty($requestedName)) {
            $displayName = trim($requestedName);
            if ($user->is_guest) {
                $user->update(['name' => $displayName]);
            }
        } else {
            $displayName = $user->name;
        }

        // LiveKit identity MUST be a URL-safe ASCII string.
        // Never put raw Unicode/Chinese characters into identity!
        $identity = $user->is_guest ? "guest_{$user->id}" : "user_{$user->id}";

        // Generate LiveKit JWT
        $token = $this->liveKitService->generateJoinToken(
            roomName: $meeting->room_name,
            identity: $identity,
            name: $displayName,
            isHost: $isHost,
            role: $role,
            metadata: [
                'role' => $role,
                'is_host' => $isHost,
                'user_id' => $user->id,
                'is_guest' => (bool) $user->is_guest,
                'display_name' => $displayName,
            ]
        );

        $responseData = [
            'token' => $token,
            'livekit_token' => $token,
            'room_name' => $meeting->room_name,
            'meeting_code' => $meeting->meeting_code,
            'title' => $meeting->title,
            'identity' => $identity,
            'name' => $displayName,
            'role' => $role,
            'is_host' => $isHost,
            'livekit_url' => $this->liveKitService->getHost(),
        ];

        if ($isHost && $hostSession) {
            $responseData['host_session_token'] = $hostSession->session_token;
        }

        return $this->successResponse($responseData, 'Joined meeting successfully');
    }

    /**
     * Terminate meeting (Host only).
     */
    public function end(Request $request, string $meetingCode): JsonResponse
    {
        $meeting = $this->findMeetingByCode($meetingCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found', 404);
        }

        $user = $request->user();

        if ($user->isGuest() || $meeting->host_id !== $user->id) {
            return $this->errorResponse('Only the host can end this meeting', 403);
        }

        $hostSession = MeetingHostSession::where('user_id', $user->id)->first();
        if ($hostSession) {
            $token = $request->input('host_session_token');
            if (empty($token)) {
                return $this->errorResponse('Host session token is required to end meeting', 422, [
                    'code' => 'HOST_SESSION_TOKEN_REQUIRED',
                ]);
            }

            $released = $this->hostSessionService->releaseHostLock($user, $meeting, $token);
            if (! $released) {
                return $this->errorResponse('Invalid or expired host session token', 403, [
                    'code' => 'HOST_SESSION_INVALID',
                ]);
            }
        }

        $this->participantService->terminateMeeting($meeting);

        return $this->successResponse(null, 'Meeting ended successfully');
    }

    /**
     * Leave meeting (Participant).
     */
    public function leave(Request $request, string $meetingCode): JsonResponse
    {
        $meeting = $this->findMeetingByCode($meetingCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found', 404);
        }

        $user = $request->user();

        // Mark active participant record as left
        MeetingParticipant::where('meeting_id', $meeting->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);

        // If host leaves, automatically end meeting for everyone and delete SFU room
        if (! $user->isGuest() && $meeting->host_id === $user->id) {
            $hostSession = MeetingHostSession::where('user_id', $user->id)->first();
            if ($hostSession) {
                $token = $request->input('host_session_token');
                if (empty($token)) {
                    return $this->errorResponse('Host session token is required to leave as host', 422, [
                        'code' => 'HOST_SESSION_TOKEN_REQUIRED',
                    ]);
                }

                $released = $this->hostSessionService->releaseHostLock($user, $meeting, $token);
                if (! $released) {
                    return $this->errorResponse('Invalid or expired host session token', 403, [
                        'code' => 'HOST_SESSION_INVALID',
                    ]);
                }
            }

            $this->participantService->terminateMeeting($meeting);

            return $this->successResponse(['meeting_ended' => true], 'Host left, meeting ended successfully');
        }

        return $this->successResponse(null, 'Left meeting successfully');
    }

    /**
     * Extend host session lease via heartbeat.
     */
    public function heartbeat(Request $request, string $meetingCode): JsonResponse
    {
        $meeting = $this->findMeetingByCode($meetingCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found', 404);
        }

        $user = $request->user();

        if ($user->isGuest() || $meeting->host_id !== $user->id) {
            return $this->errorResponse('Only the meeting host can send heartbeats', 403);
        }

        $token = $request->input('host_session_token');
        if (empty($token)) {
            return $this->errorResponse('Host session token is required', 422, [
                'code' => 'HOST_SESSION_TOKEN_REQUIRED',
            ]);
        }

        try {
            $session = $this->hostSessionService->heartbeat($user, $meeting, $token);

            return $this->successResponse([
                'expires_at' => $session->expires_at->toISOString(),
                'last_seen_at' => $session->last_seen_at->toISOString(),
            ], 'Host heartbeat acknowledged');
        } catch (HostSessionInvalidException $e) {
            return response()->json([
                'success' => false,
                'code' => 'HOST_SESSION_INVALID',
                'message' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Controlled Host Session Re-acquisition after background/PiP recovery or lease expiration.
     */
    public function reacquireHostSession(Request $request, string $meetingCode): JsonResponse
    {
        $meeting = $this->findMeetingByCode($meetingCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found', 404);
        }

        $user = $request->user();

        // Must be the actual host of this meeting and not a guest
        if ($user->isGuest() || $meeting->host_id !== $user->id) {
            return $this->errorResponse('Only the meeting host can re-acquire host sessions', 403);
        }

        // Meeting must still be active and not ended
        if (! $meeting->is_active || $meeting->ended_at !== null) {
            return response()->json([
                'success' => false,
                'code' => 'MEETING_ENDED',
                'message' => 'This meeting has already ended.',
            ], 410);
        }

        $token = $request->input('host_session_token');

        try {
            $session = $this->hostSessionService->acquireHostLock($user, $meeting, $token);

            $meeting->update([
                'is_active' => true,
                'is_host_online' => true,
            ]);

            return $this->successResponse([
                'host_session_token' => $session->session_token,
                'expires_at' => $session->expires_at->toISOString(),
                'last_seen_at' => $session->last_seen_at->toISOString(),
            ], 'Host session re-acquired successfully');
        } catch (HostAlreadyInMeetingException $e) {
            return response()->json([
                'success' => false,
                'code' => 'HOST_ALREADY_IN_MEETING',
                'message' => $e->getMessage(),
                'data' => [
                    'active_meeting_code' => $e->getActiveMeetingCode(),
                ],
            ], 409);
        }
    }

    /**
     * Remove a participant from the meeting (Host only).
     */
    public function removeParticipant(Request $request, string $meetingCode): JsonResponse
    {
        $meeting = $this->findMeetingByCode($meetingCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found', 404);
        }

        $user = $request->user();

        if ($user->isGuest() || $meeting->host_id !== $user->id) {
            return $this->errorResponse('Only the host can remove participants', 403);
        }

        $validated = $request->validate([
            'identity' => 'required|string',
        ]);

        $identity = $validated['identity'];

        // Prevent removing the host
        if (preg_match('/^(?:user_|guest_)?(\d+)$/', $identity, $matches)) {
            $targetUserId = (int) $matches[1];
            if ($targetUserId === $meeting->host_id) {
                return $this->errorResponse('Cannot remove the meeting host', 422);
            }
        }

        $this->participantService->removeParticipant($meeting, $identity);

        return $this->successResponse(null, 'Participant removed successfully');
    }

    /**
     * Delete a meeting and clean up SFU resources (Host only).
     */
    public function destroy(Request $request, string $meetingCode): JsonResponse
    {
        $meeting = $this->findMeetingByCode($meetingCode);

        if (! $meeting) {
            return $this->errorResponse('Meeting not found', 404);
        }

        $user = $request->user();

        if ($user->isGuest() || $meeting->host_id !== $user->id) {
            return $this->errorResponse('Only the host can delete this meeting', 403);
        }

        // Clean up LiveKit SFU room if active or pre-created
        try {
            $this->liveKitService->deleteRoom($meeting->room_name);
        } catch (\Throwable $e) {
            // Room may already be closed or not found on SFU
        }

        // Clean up any lingering host session lock for this meeting
        MeetingHostSession::where('meeting_id', $meeting->id)->delete();

        // Delete participant records and meeting record
        $meeting->participants()->delete();
        $meeting->delete();

        return $this->successResponse(null, 'Meeting deleted successfully');
    }

    /**
     * Find a meeting by flexible code, room name, ID, or shared link.
     */
    protected function findMeetingByCode(string $rawInput): ?Meeting
    {
        return $this->participantService->findMeetingByCode($rawInput);
    }
}
