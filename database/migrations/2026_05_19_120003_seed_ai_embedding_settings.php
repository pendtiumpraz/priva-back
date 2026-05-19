<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default RAG (Retrieval-Augmented Generation) settings.
 *
 * Master toggle = false (off). Admin harus explicit enable lewat
 * /platform-admin/system-settings → AI Embedding tab. Saat enabled=false:
 *   - Observer skip dispatch EmbedRecordJob saat RoPA/DPIA/dll di-save
 *   - VectorSearchService throw exception
 *   - AI Agent tools search_similar_* return error "RAG nonaktif"
 *   - System prompt rules 16-17 (RAG-first + cite) omitted
 *
 * Default provider = 'tei' (on-prem TEI bge-m3, mengarah ke ai-onprem stack).
 * Untuk SaaS: admin ganti provider ke 'openai' atau 'cohere' + set api_key.
 *
 * Validation: kalau admin coba enable=true di DB non-Postgres,
 * SystemSettingsController reject dengan 422 RAG_REQUIRES_POSTGRES.
 */
return new class extends Migration
{
    private const DEFAULTS = [
        'ai_embedding.enabled' => false,
        'ai_embedding.provider' => 'tei',
        'ai_embedding.tei_base_url' => 'http://privasimu-embeddings:80',
        'ai_embedding.tei_model' => 'bge-m3',
        'ai_embedding.openai_api_key' => null,
        'ai_embedding.openai_base_url' => 'https://api.openai.com/v1',
        'ai_embedding.openai_model' => 'text-embedding-3-small',
        'ai_embedding.cohere_api_key' => null,
        'ai_embedding.cohere_model' => 'embed-multilingual-v3.0',
        'ai_embedding.cache_ttl_seconds' => 2592000, // 30 days
        'ai_embedding.chunk_size_chars' => 1000,
        'ai_embedding.chunk_overlap_chars' => 200,
        'ai_embedding.rate_limit_per_minute' => 100,
    ];

    private const ENCRYPTED_KEYS = [
        'ai_embedding.openai_api_key',
        'ai_embedding.cohere_api_key',
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::DEFAULTS as $key => $value) {
            $exists = DB::table('system_settings')->where('key', $key)->exists();
            if ($exists) continue;

            DB::table('system_settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'is_encrypted' => in_array($key, self::ENCRYPTED_KEYS, true),
                'section' => 'ai_embedding',
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', array_keys(self::DEFAULTS))->delete();
    }
};
