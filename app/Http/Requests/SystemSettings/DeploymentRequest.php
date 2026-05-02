<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Deployment mode toggle (saas | onprem).
 *
 * Switching to onprem unlocks AI tools that read raw data — see
 * INFRASTRUCTURE_PLAN.md §9. Per-table allowlist is fase 2.
 */
class DeploymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mode' => 'required|string|in:saas,onprem',
        ];
    }
}
