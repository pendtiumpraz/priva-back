<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $user = $this->user();
        $isRoot = $user !== null && in_array($user->role ?? null, ['root', 'superadmin'], true);

        // Slug uniqueness handled in the controller after the model is loaded
        // (mirrors UpdateModuleRequest / UpdateLessonRequest / BE-2 pattern).
        // Keeping rules() free of DB queries avoids the antipattern fixed in BE-2.
        return [
            'title'         => ['sometimes', 'required', 'string', 'max:255'],
            'description'   => ['sometimes', 'nullable', 'string', 'max:5000'],
            'thumbnail_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'status'        => ['sometimes', 'required', 'in:draft,published'],
            'org_id'        => [
                'sometimes',
                $isRoot ? 'nullable' : 'prohibited',
                'uuid',
                Rule::exists('organizations', 'id'),
            ],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash'],
        ];
    }

    public function messages(): array
    {
        return [
            'org_id.prohibited' => 'You may not reassign this course to another organization.',
        ];
    }
}
