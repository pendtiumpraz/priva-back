<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'document_type' => 'nullable|string|max:64',
            'audience' => 'nullable|string|in:customer,employee,job_applicant,external',
            'language' => 'nullable|string|in:id,en',
            'title' => 'required|string|max:255',
            'wizard_inputs' => 'required|array',
            // Legal-safety gate: user MUST acknowledge legal review before generating.
            'legal_acknowledgement' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'legal_acknowledgement.accepted' => 'Anda harus menyetujui untuk mereview hasil bersama tim legal sebelum dipublikasikan.',
            'legal_acknowledgement.required' => 'Persetujuan review legal wajib dicentang.',
        ];
    }
}
