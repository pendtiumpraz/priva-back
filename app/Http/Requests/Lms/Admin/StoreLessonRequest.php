<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // Slug uniqueness scoped to (module_id, slug) is enforced manually in
        // the controller (mirrors the BE-2 Module pattern). Keeping rules()
        // free of DB queries avoids the antipattern fixed in BE-2 — the DB
        // UNIQUE index on (module_id, slug) is the hard guard.
        return [
            'title'             => ['required', 'string', 'max:255'],
            'body'              => ['nullable', 'string'],
            'sort_order'        => ['nullable', 'integer', 'min:0'],
            'status'            => ['required', 'in:draft,published'],
            'video_id'          => ['nullable', 'integer', 'exists:lms_videos,id'],
            'estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'slug'              => ['nullable', 'string', 'max:255', 'alpha_dash'],
        ];
    }
}
