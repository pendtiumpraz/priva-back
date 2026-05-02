<?php

namespace App\Http\Requests\DataDiscoveryScan;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the body of POST /api/data-discovery/scan/generate.
 *
 * Email + name are mandatory (the strategy matrix needs at least one strong
 * identifier; email alone qualifies, but name is also required as a
 * sanity-check the user is requesting a real person not a wildcard).
 *
 * NIK / phone / dob are optional and unlock additional strategies — see
 * DATA_DISCOVERY_SEARCH_PLAN.md §2.
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
            'email' => ['required', 'email', 'max:191'],
            'name' => ['required', 'string', 'min:3', 'max:191'],
            'nik' => ['nullable', 'digits:16'],
            'phone' => ['nullable', 'string', 'max:20'],
            'dob' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
