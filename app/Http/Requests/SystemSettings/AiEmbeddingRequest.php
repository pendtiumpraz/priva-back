<?php

namespace App\Http\Requests\SystemSettings;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RAG (Retrieval-Augmented Generation) embedding configuration.
 *
 * Master toggle untuk RAG feature. Saat enabled=false: observer skip
 * dispatch, AI Agent search_similar_* tools return error "disabled",
 * EmbeddingService throw exception. Default false — admin harus explicit
 * enable.
 *
 * Provider switchable: tei (on-prem TEI bge-m3), openai (cloud), cohere
 * (cloud). API keys per provider disimpan encrypted.
 *
 * Constraint: RAG butuh Postgres + pgvector extension. Kalau driver DB
 * bukan pgsql, controller akan reject enable=true dengan pesan.
 */
class AiEmbeddingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
            'provider' => 'required|in:tei,openai,cohere',

            // TEI (on-prem) — base URL dan model name
            'tei_base_url' => 'nullable|string|url|max:255',
            'tei_model' => 'nullable|string|max:100',

            // OpenAI cloud
            'openai_api_key' => 'nullable|string|max:200',
            'openai_base_url' => 'nullable|string|url|max:255',
            'openai_model' => 'nullable|string|max:100',

            // Cohere cloud
            'cohere_api_key' => 'nullable|string|max:200',
            'cohere_model' => 'nullable|string|max:100',

            // Operational
            'cache_ttl_seconds' => 'nullable|integer|min:60|max:31536000',
            'chunk_size_chars' => 'nullable|integer|min:200|max:8000',
            'chunk_overlap_chars' => 'nullable|integer|min:0|max:2000',
            'rate_limit_per_minute' => 'nullable|integer|min:1|max:10000',
        ];
    }
}
