<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * lms_videos has no `title` column (5.1-BE-3 reviewer note); intentionally
 * omitted here. The video picker UI keys off (source, external_id).
 */
class StoreVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'source'           => ['required', 'in:youtube,mux'],
            'external_id'      => ['required', 'string', 'max:255'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
