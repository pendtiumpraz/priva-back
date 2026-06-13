<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update rules are deliberately less strict than store: criteria_json
 * structure is validated in the controller (after the badge is loaded) so we
 * can resolve the *effective* criteria_type — the incoming value if present,
 * otherwise the existing badge's value. Loading the model inside FormRequest
 * `rules()` would re-introduce the BE-2 antipattern.
 */
class UpdateBadgeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $user = $this->user();
        $isRoot = $user !== null && in_array($user->role ?? null, ['root', 'superadmin'], true);

        return [
            'slug'          => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash'],
            'name'          => ['sometimes', 'required', 'string', 'max:255'],
            'description'   => ['sometimes', 'required', 'string', 'max:2000'],
            'icon'          => ['sometimes', 'required', 'string', 'max:100'],
            'criteria_type' => ['sometimes', 'required', 'string', Rule::in(StoreBadgeRequest::ALLOWED_CRITERIA_TYPES)],
            // Structure validated in controller via BadgeCriteriaJsonRule once
            // the effective criteria_type is known.
            'criteria_json' => ['sometimes', 'required', 'array'],
            'org_id'        => [
                'sometimes',
                $isRoot ? 'nullable' : 'prohibited',
                'uuid',
                Rule::exists('organizations', 'id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'org_id.prohibited' => 'You may not reassign this badge to another organization.',
            'criteria_type.in'  => 'The selected criteria type is invalid.',
        ];
    }
}
