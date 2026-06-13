<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Update is sometimes-only. Owner reassignment is intentionally NOT allowed:
 * a quiz's owner_type/owner_key are part of its identity (and used for
 * progression/lock checks). To re-attach a quiz to a different parent,
 * delete + recreate.
 */
class UpdateQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'nullable', 'string', 'max:255'],
            'passing_score'    => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'time_limit_mins'  => ['sometimes', 'nullable', 'integer', 'min:1', 'max:180'],
            'max_attempts'     => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
        ];
    }
}
