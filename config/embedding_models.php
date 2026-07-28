<?php

/*
 * Katalog model embedding lokal + konfigurasi penyimpanannya.
 *
 * Melengkapi config/ai_embedding.php: berkas itu mengatur PROVIDER mana yang
 * dipakai untuk menghitung vektor, berkas ini mengatur ARTEFAK model —
 * dari mana diunduh, disimpan di mana, dan model mana yang sedang aktif.
 *
 * Tiga sumber penyajian model ("mode"):
 *   - local : berkas ada di disk VPS, dibaca sidecar ONNX di host yang sama.
 *             Tanpa egress, tanpa biaya per panggilan.
 *   - blob  : berkas ada di Vercel Blob; sidecar mengunduhnya saat start.
 *             Satu salinan dipakai bersama banyak instans — ini DEFAULT.
 *   - api   : tidak memakai artefak lokal sama sekali; pakai provider cloud
 *             (openai/cohere) atau TEI yang sudah ada di config/ai_embedding.
 *
 * Nilai runtime (mode + model aktif) disimpan di app_settings lewat
 * EmbeddingModelManager, jadi bisa diubah root tanpa deploy ulang. Nilai di
 * sini hanya default awal.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Mode & Model Aktif (default awal)
    |--------------------------------------------------------------------------
    |
    | Dibaca hanya bila app_settings belum punya nilainya. Setelah root
    | mengubah lewat UI, app_settings yang menang.
    |
    */

    'mode' => env('AI_EMBEDDING_MODE', 'blob'),

    'active' => env('AI_EMBEDDING_ACTIVE_MODEL', 'minilm-l6-v2'),

    /*
    |--------------------------------------------------------------------------
    | Penyimpanan Lokal (VPS)
    |--------------------------------------------------------------------------
    |
    | `dir` harus sama dengan direktori yang di-mount ke /models pada sidecar
    | ONNX (docker/embed-onnx). Pada docker/docker-compose.onprem.yml keduanya
    | sudah disatukan lewat volume `embed_models`. Kalau berbeda, unduhan mode
    | `local` tampak berhasil tetapi sidecar tidak menemukan berkasnya.
    |
    */

    'local' => [
        'dir' => env('AI_EMBEDDING_MODELS_DIR', storage_path('app/embedding-models')),
        'runtime_url' => env('AI_EMBEDDING_LOCAL_URL', 'http://127.0.0.1:8091'),
        'timeout' => (int) env('AI_EMBEDDING_LOCAL_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Vercel Blob
    |--------------------------------------------------------------------------
    |
    | Token dibuat Vercel dengan awalan nama store — pada deployment ini
    | `priva_READ_WRITE_TOKEN`. Nama baku `BLOB_READ_WRITE_TOKEN` dipakai
    | sebagai cadangan supaya store yang dibuat ulang tidak memutus fitur.
    |
    | `api_version` mengikuti header x-api-version yang diminta REST API
    | Vercel Blob. Naikkan hanya bila Vercel menerbitkan versi baru.
    |
    */

    'blob' => [
        'token' => env('priva_READ_WRITE_TOKEN', env('BLOB_READ_WRITE_TOKEN')),
        'base_url' => env('AI_EMBEDDING_BLOB_URL', 'https://blob.vercel-storage.com'),
        'prefix' => env('AI_EMBEDDING_BLOB_PREFIX', 'embedding-models'),
        'api_version' => '7',
        'timeout' => (int) env('AI_EMBEDDING_BLOB_TIMEOUT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sumber Unduhan
    |--------------------------------------------------------------------------
    */

    'hf_base_url' => env('AI_EMBEDDING_HF_URL', 'https://huggingface.co'),

    'download_timeout' => (int) env('AI_EMBEDDING_DOWNLOAD_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Katalog Model
    |--------------------------------------------------------------------------
    |
    | Sengaja hanya model kecil ber-format ONNX int8 (repo Xenova), supaya
    | muat di VPS tanpa GPU dan murah disimpan di blob. Semuanya menghasilkan
    | vektor 384 dimensi — berpindah antar model tidak mengubah skema kolom
    | vector, tetapi TETAP WAJIB re-embed karena ruang vektornya berbeda.
    |
    | Field penting:
    |   - pooling  : cara meringkas token menjadi satu vektor. Salah pooling
    |                = kualitas anjlok diam-diam, jadi ikut disimpan di sini
    |                dan dikirim ke sidecar, bukan ditebak sidecar.
    |   - prefix   : imbuhan wajib untuk keluarga E5 ("query:" / "passage:").
    |                Tanpa ini skor kemiripan E5 melenceng.
    |   - languages: penanda untuk UI; produk ini berbahasa Indonesia, jadi
    |                model English-only diberi peringatan di antarmuka.
    |
    */

    'catalog' => [

        [
            'id' => 'minilm-l6-v2',
            'label' => 'MiniLM L6 v2 (int8)',
            'repo' => 'Xenova/all-MiniLM-L6-v2',
            'revision' => 'main',
            'dimension' => 384,
            'max_tokens' => 256,
            'size_bytes' => 23_000_000,
            'size_label' => '~23 MB',
            'pooling' => 'mean',
            'normalize' => true,
            'prefix' => ['query' => '', 'passage' => ''],
            'languages' => ['en'],
            'multilingual' => false,
            'recommended' => true,
            'summary' => 'Paling kecil dan paling cepat. Cocok sebagai default dan untuk VPS tanpa GPU.',
            'caveat' => 'Dilatih untuk teks Inggris. Untuk dokumen berbahasa Indonesia kualitasnya di bawah E5 multilingual.',
            'files' => [
                'config.json',
                'tokenizer.json',
                'tokenizer_config.json',
                'special_tokens_map.json',
                'onnx/model_quantized.onnx',
            ],
        ],

        [
            'id' => 'bge-small-en-v15',
            'label' => 'BGE Small EN v1.5 (int8)',
            'repo' => 'Xenova/bge-small-en-v1.5',
            'revision' => 'main',
            'dimension' => 384,
            'max_tokens' => 512,
            'size_bytes' => 33_000_000,
            'size_label' => '~33 MB',
            'pooling' => 'cls',
            'normalize' => true,
            'prefix' => ['query' => 'Represent this sentence for searching relevant passages: ', 'passage' => ''],
            'languages' => ['en'],
            'multilingual' => false,
            'recommended' => false,
            'summary' => 'Kualitas retrieval Inggris lebih baik dari MiniLM dengan tambahan ukuran yang kecil.',
            'caveat' => 'Masih English-only. Memakai pooling CLS dan imbuhan khusus pada sisi query.',
            'files' => [
                'config.json',
                'tokenizer.json',
                'tokenizer_config.json',
                'special_tokens_map.json',
                'onnx/model_quantized.onnx',
            ],
        ],

        [
            'id' => 'multilingual-e5-small',
            'label' => 'Multilingual E5 Small (int8)',
            'repo' => 'Xenova/multilingual-e5-small',
            'revision' => 'main',
            'dimension' => 384,
            'max_tokens' => 512,
            'size_bytes' => 118_000_000,
            'size_label' => '~118 MB',
            'pooling' => 'mean',
            'normalize' => true,
            'prefix' => ['query' => 'query: ', 'passage' => 'passage: '],
            'languages' => ['id', 'en', '+100'],
            'multilingual' => true,
            'recommended' => true,
            'summary' => 'Mendukung 100+ bahasa termasuk Bahasa Indonesia. Pilihan terbaik untuk dokumen kepatuhan berbahasa Indonesia.',
            'caveat' => 'Lima kali lebih besar dari MiniLM dan lebih lambat di CPU. Wajib memakai imbuhan query:/passage:.',
            'files' => [
                'config.json',
                'tokenizer.json',
                'tokenizer_config.json',
                'special_tokens_map.json',
                'onnx/model_quantized.onnx',
            ],
        ],

    ],

];
