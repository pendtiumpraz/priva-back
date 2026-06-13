<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // Slug uniqueness handled in the controller after the model is loaded
        // (mirrors UpdateModuleRequest / BE-2 pattern). Keeping rules() free of
        // DB queries avoids the antipattern fixed in BE-2.
        return [
            'title'             => ['sometimes', 'required', 'string', 'max:255'],
            'body'              => ['sometimes', 'nullable', 'string'],
            'sort_order'        => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status'            => ['sometimes', 'required', 'in:draft,published'],
            'video_id'          => ['sometimes', 'nullable', 'integer', 'exists:lms_videos,id'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:480'],
            'slug'              => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash'],
        ];
    }
}
