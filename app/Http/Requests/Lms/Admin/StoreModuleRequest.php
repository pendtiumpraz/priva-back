<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // Slug uniqueness scoped to (course_id, slug). Course id comes from
        // the route binding (apiResource('courses.modules', ...) shallow).
        $courseId = $this->route('course');
        if (is_object($courseId)) {
            $courseId = $courseId->id ?? null;
        }

        return [
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'sort_order'  => ['nullable', 'integer', 'min:0'],
            'status'      => ['required', 'in:draft,published'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('lms_modules', 'slug')
                    ->where(fn ($q) => $q->where('course_id', $courseId)),
            ],
        ];
    }
}
