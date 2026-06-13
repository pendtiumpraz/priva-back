<?php

namespace App\Http\Requests\Lms\Admin;

use App\Lms\Rules\BadgeCriteriaJsonRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBadgeRequest extends FormRequest
{
    /**
     * Spec §3.9 criteria types plus the legacy values still present in the
     * seed data (`completion`, `quiz_score`, `xp_total`) so seeded rows
     * remain editable.
     */
    public const ALLOWED_CRITERIA_TYPES = [
        'lesson_complete',
        'quiz_pass',
        'quiz_perfect',
        'course_complete',
        'streak',
        'xp_threshold',
        'custom',
        // legacy seed values
        'completion',
        'quiz_score',
        'xp_total',
    ];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $user = $this->user();
        $isRoot = $user !== null && in_array($user->role ?? null, ['root', 'superadmin'], true);

        // Slug uniqueness binds to the resolved org_id (root may target any
        // org_id including null; tenant admins are forced to their own org).
        return [
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                // Controller-side check enforces (org_id, slug) uniqueness
                // ignoring soft-deleted rows. We keep a basic uniqueness
                // check here as a fast-fail for collisions across all orgs,
                // matching the BE-1/BE-2/BE-3 pattern.
            ],
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'icon'        => ['required', 'string', 'max:100'],
            'criteria_type' => ['required', 'string', Rule::in(self::ALLOWED_CRITERIA_TYPES)],
            'criteria_json' => [
                'required',
                'array',
                new BadgeCriteriaJsonRule((string) $this->input('criteria_type', '')),
            ],
            'org_id' => [
                $isRoot ? 'nullable' : 'prohibited',
                'uuid',
                Rule::exists('organizations', 'id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'org_id.prohibited' => 'You may not assign a badge to another organization.',
            'criteria_type.in'  => 'The selected criteria type is invalid.',
        ];
    }
}
