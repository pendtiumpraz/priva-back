<?php

namespace App\Http\Requests\Lms\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        // Slug uniqueness is enforced manually in the controller (mirroring
        // the `store()` pattern) to avoid a redundant Module::find() call here
        // when the controller already loads the module via findOrFail.
        return [
            'title'       => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sort_order'  => ['sometimes', 'nullable', 'integer', 'min:0'],
            'status'      => ['sometimes', 'required', 'in:draft,published'],
            'slug'        => ['sometimes', 'required', 'string', 'max:255', 'alpha_dash'],
        ];
    }
}
