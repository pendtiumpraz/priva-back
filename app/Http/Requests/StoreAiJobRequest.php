<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an AI job dispatch request from the frontend useAiJobs hook.
 *
 * - `type` — must be a known supported MVP type. `deep_scan` is intentionally
 *   excluded; ProcessAiJob throws NotImplemented for it so we reject earlier.
 * - `subject_id` — string ID of the record we're acting on (e.g. RoPA UUID).
 *   Used by the dedup check to prevent two concurrent jobs on the same row.
 * - `payload` — opaque to the controller; ProcessAiJob owns the shape.
 */
class StoreAiJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'type' => 'required|string|in:autofill,analyzer,summary',
            'module' => 'nullable|string|max:32',
            'subject_id' => 'nullable|string|max:64',
            'label' => 'required|string|max:191',
            'payload' => 'required|array',
        ];
    }
}
