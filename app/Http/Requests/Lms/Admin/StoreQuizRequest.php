<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Spec §3.6 talks about lesson_id, status, slug, description.
 * Real schema (lms_quizzes) has none of those — the table is keyed by
 * (owner_type, owner_key) where owner_type ∈ {module, course, feature_doc}.
 * We map at the boundary:
 *   - external `module_id` → owner_type='module', owner_key=<module_id>
 *   - external `course_id` → owner_type='course', owner_key=<course_id>
 *   - external `owner_type` + `owner_key` accepted directly (covers feature_doc)
 *   - external `time_limit_mins` → DB `time_limit_seconds` (× 60)
 * No slug/description/status — column doesn't exist; FE drops those fields.
 */
class StoreQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title'            => ['nullable', 'string', 'max:255'],
            'passing_score'    => ['required', 'integer', 'min:0', 'max:100'],
            'time_limit_mins'  => ['nullable', 'integer', 'min:1', 'max:180'],
            'max_attempts'     => ['nullable', 'integer', 'min:1', 'max:99'],

            // Owner — accept either the convenience pair or the canonical pair.
            'module_id'        => ['nullable', 'integer', 'exists:lms_modules,id'],
            'course_id'        => ['nullable', 'integer', 'exists:lms_courses,id'],
            'owner_type'       => ['nullable', 'in:module,course,feature_doc'],
            'owner_key'        => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $hasModule  = $this->filled('module_id');
            $hasCourse  = $this->filled('course_id');
            $hasOwner   = $this->filled('owner_type') && $this->filled('owner_key');

            $count = (int) $hasModule + (int) $hasCourse + (int) $hasOwner;
            if ($count === 0) {
                $v->errors()->add('owner_type', 'Provide module_id, course_id, or owner_type+owner_key.');
            } elseif ($count > 1) {
                $v->errors()->add('owner_type', 'Provide only one of module_id, course_id, or owner_type+owner_key.');
            }
        });
    }
}
