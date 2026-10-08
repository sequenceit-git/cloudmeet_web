<?php

namespace App\Http\Requests\Meeting;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleMeetingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'scheduled_at' => 'nullable|string',
            'start_time' => 'nullable|string',
            'date' => 'nullable|string',
            'time' => 'nullable|string',
            'passcode' => 'nullable|string|max:32',
            'max_participants' => 'nullable|integer|min:2|max:100',
            'waiting_room' => 'nullable|boolean',
        ];
    }
}
