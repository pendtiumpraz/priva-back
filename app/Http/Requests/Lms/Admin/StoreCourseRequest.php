<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $user = $this->user();
        $isRoot = $user !== null && in_array($user->role ?? null, ['root', 'superadmin'], true);

        // Resolve the org_id that the slug uniqueness check should bind to.
        // - Root may pass any org_id (or null for global)
        // - Tenant admins are forced to their own org
        $resolvedOrgId = $isRoot
            ? ($this->input('org_id', $user->org_id ?? null))
            : ($user->org_id ?? null);

        return [
            'title'         => ['required', 'string', 'max:255'],
            'description'   => ['nullable', 'string', 'max:5000'],
            'thumbnail_url' => ['nullable', 'url', 'max:500'],
            'status'        => ['required', 'in:draft,published'],
            'org_id'        => [
                $isRoot ? 'nullable' : 'prohibited',
                'uuid',
                Rule::exists('organizations', 'id'),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('lms_courses', 'slug')->where(fn ($q) => $q->where('org_id', $resolvedOrgId)->whereNull('deleted_at')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'org_id.prohibited' => 'You may not assign a course to another organization.',
        ];
    }
}
