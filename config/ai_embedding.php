<?php

/*
 * AI Embedding configuration.
 *
 * Provider switchable: 'tei' (local Hugging Face TEI di ai-onprem stack),
 * 'openai' (cloud), atau 'cohere' (cloud). System settings UI bisa
 * override config ini per-deployment.
 *
 * Untuk on-prem deployment: pakai 'tei' dengan base_url ke service
 * embeddings di docker-compose ai-onprem.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Master Toggle
    |--------------------------------------------------------------------------
    |
    | Master switch untuk seluruh fitur embedding + RAG (semantic search,
    | vector indexing, similar-record lookup). Default OFF supaya boot
    | environment yang belum siap (driver bukan pgsql, TEI service belum
    | up, API key cloud belum di-set) tidak crash.
    |
    | Saat OFF: VectorSearchService me-return empty result, indexing job
    | skip enqueue, tool AI search_similar_* graceful-degrade ke list_*.
    |
    */

    'enabled' => env('AI_EMBEDDING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Provider Selection
    |--------------------------------------------------------------------------
    |
    | Trade-off:
    | - 'tei'    : on-prem, no egress, data residency aman untuk BUMN /
    |              regulated tenants. Butuh GPU/CPU resource di host.
    |              Model bge-m3 multilingual (incl. Bahasa Indonesia).
    | - 'openai' : termurah per-token untuk volume kecil-menengah, kualitas
    |              tinggi. TIDAK COCOK untuk data sensitif/PII tanpa DPA.
    | - 'cohere' : alternatif cloud, model multilingual v3 kuat di Bahasa
    |              Indonesia. Mid-range cost.
    |
    | Switch di runtime via system_settings → tidak perlu re-deploy.
    |
    */

    'provider' => env('AI_EMBEDDING_PROVIDER', 'tei'),

    /*
    |--------------------------------------------------------------------------
    | TEI (Hugging Face Text Embeddings Inference) — On-Prem Default
    |--------------------------------------------------------------------------
    |
    | Self-hosted di docker-compose ai-onprem stack (service name:
    | privasimu-embeddings). Model bge-m3 menghasilkan 1024-dim vector,
    | multilingual, support input ~8192 token. Pilihan untuk tenant yang
    | data tidak boleh keluar infrastruktur (BUMN, healthcare, gov).
    |
    | Cost: nol per-call, biaya hosting (GPU disarankan untuk throughput
    | tinggi; CPU OK untuk <1k chunk/hari).
    |
    */

    'tei' => [
        'base_url' => env('AI_EMBEDDING_TEI_URL', 'http://privasimu-embeddings:80'),
        'model' => 'bge-m3',
        'dimension' => 1024,
        'timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI Embeddings — Cloud Default
    |--------------------------------------------------------------------------
    |
    | text-embedding-3-small: 1536-dim, $0.02 / 1M token, kualitas baik
    | untuk EN; untuk Bahasa Indonesia sedikit di bawah bge-m3 / cohere v3
    | tapi acceptable. Cocok untuk tenant non-sensitif / pilot.
    |
    | Data residency: traffic keluar ke OpenAI US/EU endpoint. WAJIB review
    | DPA + tenant agreement sebelum enable untuk data PII.
    |
    */

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('AI_EMBEDDING_OPENAI_MODEL', 'text-embedding-3-small'),
        'dimension' => 1536,
        'timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cohere Embeddings — Cloud Alternative
    |--------------------------------------------------------------------------
    |
    | embed-multilingual-v3.0: 1024-dim, kuat di 100+ bahasa termasuk
    | Bahasa Indonesia formal/legal (compliance docs, kontrak). Cost
    | mid-range. Dimensi sama dengan TEI bge-m3 — migrasi antar dua
    | provider ini tidak perlu re-index (asal index_version di-bump).
    |
    | Data residency: cloud (US/EU). Sama dengan OpenAI dari sisi
    | compliance — butuh DPA.
    |
    */

    'cohere' => [
        'api_key' => env('COHERE_API_KEY'),
        'base_url' => 'https://api.cohere.ai/v1',
        'model' => 'embed-multilingual-v3.0',
        'dimension' => 1024,
        'timeout' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache TTL
    |--------------------------------------------------------------------------
    |
    | Hasil embed di-cache keyed by SHA-256(text + provider + model).
    | Default 30 hari — text yang sama (mis. boilerplate klausa kontrak,
    | template kebijakan) tidak di-embed berulang. Hemat cost cloud +
    | latency TEI.
    |
    */

    'cache_ttl_seconds' => 86400 * 30,

    /*
    |--------------------------------------------------------------------------
    | Chunking Parameters
    |--------------------------------------------------------------------------
    |
    | Dokumen di-split jadi chunk sebelum di-embed. Trade-off:
    | - chunk_size besar  → context lebih lengkap per-vector, tapi recall
    |                       per-query turun (vector "encer").
    | - chunk_size kecil  → recall tinggi, tapi banyak chunk → cost +
    |                       latency naik.
    | - overlap           → menjaga continuity antar chunk (kalimat yang
    |                       kebagi tidak hilang konteks).
    |
    | Default 1000/200 char (~250/50 token) cocok untuk dokumen ROPA,
    | DPIA, breach report. Tune via system_settings jika tenant punya
    | dokumen non-standar (sangat panjang / sangat pendek).
    |
    */

    'chunk_size_chars' => 1000,
    'chunk_overlap_chars' => 200,

    /*
    |--------------------------------------------------------------------------
    | Batch Size
    |--------------------------------------------------------------------------
    |
    | Jumlah chunk per request ke provider. TEI + OpenAI + Cohere semua
    | support batch. 32 adalah sweet-spot: cukup besar untuk hemat
    | round-trip, cukup kecil untuk hindari payload size limit (terutama
    | OpenAI 8192 token total per request).
    |
    */

    'batch_size' => 32,

    /*
    |--------------------------------------------------------------------------
    | Rate Limit (per Minute, per Org)
    |--------------------------------------------------------------------------
    |
    | Throttle embedding request per-org untuk:
    | 1. Cegah satu tenant flush quota cloud provider.
    | 2. Cegah satu tenant bottleneck TEI service untuk tenant lain
    |    (multi-tenant fairness).
    |
    | Env override untuk tenant enterprise yang butuh burst lebih tinggi.
    |
    */

    'rate_limit_per_minute' => env('AI_EMBEDDING_RATE_LIMIT', 100),

];
