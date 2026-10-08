<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Support\Facades\Log;

class MeetingParticipantService
{
    public function __construct(
        protected LiveKitService $liveKitService
    ) {}

    /**
     * Resolve a meeting by code, formatted code, room name, or ID.
     */
    public function findMeetingByCode(string $rawInput): ?Meeting
    {
        $rawInput = trim($rawInput);

        // If it's a URL or contains slashes, extract the last segment of the path
        if (str_contains($rawInput, '/')) {
            $path = parse_url($rawInput, PHP_URL_PATH) ?? $rawInput;
            $segments = array_values(array_filter(explode('/', $path)));
            $rawInput = ! empty($segments) ? end($segments) : $rawInput;
        }

        // Strip query string or hashes if present
        $rawInput = trim(explode('?', explode('#', $rawInput)[0])[0]);

        // Clean digits
        $cleanDigits = preg_replace('/\D/', '', $rawInput);

        // 6-digit formatted code (XXX-XXX) or legacy 9-digit (XXX-XXX-XXX)
        $formattedCode = (strlen($cleanDigits) === 6)
            ? substr($cleanDigits, 0, 3).'-'.substr($cleanDigits, 3, 3)
            : ((strlen($cleanDigits) === 9)
                ? substr($cleanDigits, 0, 3).'-'.substr($cleanDigits, 3, 3).'-'.substr($cleanDigits, 6, 3)
                : $rawInput);

        return Meeting::where('meeting_code', $rawInput)
            ->orWhere('meeting_code', $formattedCode)
            ->orWhere('meeting_code', $cleanDigits)
            ->orWhere('room_name', $rawInput)
            ->orWhere('room_name', strtolower($rawInput))
            ->when(is_numeric($rawInput), function ($query) use ($rawInput) {
                $query->orWhere('id', (int) $rawInput);
            })
            ->first();
    }

    /**
     * Terminate meeting, mark participants departed, and delete LiveKit room.
     */
    public function terminateMeeting(Meeting $meeting): void
    {
        $meeting->update([
            'is_active' => false,
            'is_host_online' => false,
            'ended_at' => now(),
        ]);

        MeetingParticipant::where('meeting_id', $meeting->id)
            ->whereNull('left_at')
            ->update(['left_at' => now()]);

        try {
            $this->liveKitService->deleteRoom($meeting->room_name);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete LiveKit room on termination: '.$e->getMessage());
        }
    }

    /**
     * Remove a participant from the meeting record and SFU room.
     */
    public function removeParticipant(Meeting $meeting, string $identity): void
    {
        if (preg_match('/^(?:user_|guest_)?(\d+)$/', $identity, $matches)) {
            $targetUserId = (int) $matches[1];
            MeetingParticipant::where('meeting_id', $meeting->id)
                ->where('user_id', $targetUserId)
                ->whereNull('left_at')
                ->update(['left_at' => now()]);
        }

        try {
            $this->liveKitService->removeParticipant($meeting->room_name, $identity);
        } catch (\Throwable $e) {
            Log::warning('Failed to remove participant on LiveKit SFU: '.$e->getMessage());
        }
    }
}
