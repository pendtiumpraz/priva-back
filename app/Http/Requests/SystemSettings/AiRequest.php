<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * AI operational toggles. Provider credentials (API key, base URL) are NOT
 * here — they live in the `ai_providers` table and are managed on the
 * /settings/ai-providers page. This section only stores knobs that are not
 * tied to a single provider record.
 *
 * `local_llm_url` is OnPrem-only (Ollama/vLLM endpoint) and is also operational
 * rather than a credentialed provider record, so it stays here.
 */
class AiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'jobs_enabled' => 'required|boolean',
            'local_llm_url' => 'nullable|string|url|max:255',
            'max_concurrent_per_user' => 'required|integer|min:1|max:50',
            'history_retention_days' => 'required|integer|min:1|max:365',
        ];
    }
}
