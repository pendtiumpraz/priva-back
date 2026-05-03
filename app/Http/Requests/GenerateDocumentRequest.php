<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'document_type' => 'required|string|max:64',
            'title' => 'required|string|max:255',
            'wizard_inputs' => 'required|array',
        ];
    }
}
