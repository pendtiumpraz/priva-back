<?php

namespace App\Http\Requests\DataDiscoveryScan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body of POST /api/data-discovery/scan/generate.
 *
 * Hanya `name` yang wajib — AI generator pakai fuzzy/LIKE matching pada
 * kolom nama. Email/NIK/phone/dob optional, dipakai sebagai filter di
 * frontend results (untuk narrow down kandidat dengan nama mirip).
 */
class GenerateScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'nik' => ['nullable', 'digits:16'],
            'phone' => ['nullable', 'string', 'max:20'],
            'dob' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
