<?php

namespace App\Services;

use App\Http\Controllers\Api\AiProviderController;
use App\Models\DpiaRiskEventTemplate;
use App\Models\Ropa;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiFieldMappingService
{
    /**
     * Cache: library risk events for current call (loaded once when targetModule=dpia,
     * used both for prompt injection AND post-AI validation).
     *
     * Shape: ['Category Label' => ['Risk event A', 'Risk event B', ...], ...]
     */
    private array $riskLibraryByCategory = [];

    /**
     * Cache: flat allowlist of risk_event strings (set semantics — keys are the events).
     */
    private array $riskLibraryAllowlist = [];

    /**
     * Map extracted document data to RoPA fields using AI.
     *
     * @param  array  $extractedData  Output from DocumentParserService
     * @param  string  $targetModule  'ropa' or 'dpia'
     * @param  string|null  $orgId  Tenant org ID for AI provider resolution
     * @return array ['mapped_fields' => [...], 'confidence_scores' => [...], 'raw_response' => '...']
     */
    public function map(array $extractedData, string $targetModule, ?string $orgId): array
    {
        // Use 'document' mode AI provider (falls back to 'chat' if not set)
        $config = AiProviderController::getActiveConfig($orgId, 'document');

        if (! $config) {
            throw new \RuntimeException('AI provider belum dikonfigurasi. Silakan setting AI provider terlebih dahulu di Settings.');
        }

        // Load risk template library once per call (DPIA only).
        // 1 query, scoped by org_id (system templates org_id=null + custom org-scoped).
        if ($targetModule === 'dpia') {
            $this->loadRiskLibrary($orgId);
        }

        $prompt = $this->buildPrompt($extractedData, $targetModule);

        $response = $this->callAi($config, $prompt);

        return $this->parseAiResponse($response, $targetModule);
    }

    /**
     * Load DPIA risk event template library, grouped by category_label.
     *
     * Tenant-scoped: includes system templates (org_id=null) + custom templates
     * for current org. Single query, ordered by category_key + sequence so the
     * prompt sections are stable across calls (better for AI caching).
     */
    private function loadRiskLibrary(?string $orgId): void
    {
        $templates = DpiaRiskEventTemplate::query()
            ->where('is_active', true)
            ->where(function ($q) use ($orgId) {
                $q->whereNull('org_id');
                if ($orgId) {
                    $q->orWhere('org_id', $orgId);
                }
            })
            ->orderBy('category_key')
            ->orderBy('sequence')
            ->get(['category_key', 'category_label', 'risk_event']);

        $this->riskLibraryByCategory = $templates
            ->groupBy('category_label')
            ->map(fn ($group) => $group->pluck('risk_event')->unique()->values()->toArray())
            ->toArray();

        // Flat allowlist for O(1) validation lookup
        $allowlist = [];
        foreach ($this->riskLibraryByCategory as $events) {
            foreach ($events as $ev) {
                $allowlist[$ev] = true;
            }
        }
        $this->riskLibraryAllowlist = $allowlist;
    }

    /**
     * Build the extraction prompt.
     */
    private function buildPrompt(array $extractedData, string $targetModule): string
    {
        $rawText = $extractedData['raw_text'] ?? '';
        // Truncate to ~12000 chars to stay within context limits
        if (mb_strlen($rawText) > 12000) {
            $rawText = mb_substr($rawText, 0, 12000)."\n\n[...dokumen terpotong karena terlalu panjang...]";
        }

        if ($targetModule === 'ropa') {
            $targetFields = $this->getRopaFieldSchema();
        } else {
            $targetFields = $this->getDpiaFieldSchema();
        }

        // For DPIA, append the risk event library as a constrained vocabulary.
        // AI must MATCH risk events from this library — not invent new ones.
        $riskLibrarySection = '';
        if ($targetModule === 'dpia' && ! empty($this->riskLibraryByCategory)) {
            $riskLibrarySection = "\n\n=== RISK EVENT LIBRARY ===\n"
                ."Gunakan library berikut untuk MATCH konten dokumen ke risk event template yang paling sesuai.\n"
                ."JANGAN invent risk event baru — pilih PERSIS dari library di bawah (text harus exact match).\n"
                ."Hanya tambahkan risk event ke 'risk_events' kalau dokumen mengindikasikan risiko tersebut relevan.\n\n";
            foreach ($this->riskLibraryByCategory as $categoryLabel => $events) {
                $riskLibrarySection .= "[{$categoryLabel}]\n";
                foreach ($events as $ev) {
                    $riskLibrarySection .= "- {$ev}\n";
                }
                $riskLibrarySection .= "\n";
            }
        }

        // DPIA gets a different output format (potensi_risiko per category w/ library-matched events)
        if ($targetModule === 'dpia') {
            $outputFormat = $this->getDpiaOutputFormat();
        } else {
            $outputFormat = $this->getRopaOutputFormat();
        }

        return <<<PROMPT
Kamu adalah AI compliance analyst spesialis UU Pelindungan Data Pribadi (PDP) Indonesia.

TUGAS: Ekstrak dan mapping informasi dari dokumen berikut ke field-field yang ditentukan.

=== DOKUMEN ===
{$rawText}

=== TARGET FIELDS (JSON Schema) ===
{$targetFields}{$riskLibrarySection}

=== INSTRUKSI ===
1. Untuk setiap field, cari informasi yang relevan dari dokumen.
2. Jika informasi tidak ditemukan, isi dengan null.
3. Berikan confidence score (0.0 - 1.0) untuk setiap field yang berhasil di-mapping.
4. Untuk field array (data_categories, data_subjects, recipients), berikan sebagai array string.
5. Untuk risk_level, tentukan berdasarkan konteks: "low", "medium", atau "high".
6. Jawab HANYA dalam format JSON yang valid, tanpa markdown code blocks.

FORMAT RESPONS (JSON):
{$outputFormat}
PROMPT;
    }

    private function getRopaOutputFormat(): string
    {
        return <<<'JSON'
{
  "detail_pemrosesan": {
    "processing_activity": {"value": "...", "confidence": 0.95},
    "entity": {"value": "...", "confidence": 0.9},
    "division": {"value": "...", "confidence": 0.8},
    "work_unit": {"value": "...", "confidence": 0.7},
    "description": {"value": "...", "confidence": 0.85},
    "risk_level": {"value": "medium", "confidence": 0.75}
  },
  "dpo_team": {
    "kategori_pemrosesan": {"value": "...", "confidence": 0.8},
    "dpo_name": {"value": "...", "confidence": 0.9},
    "dpo_email": {"value": "...", "confidence": 0.9},
    "dpo_phone": {"value": "...", "confidence": 0.7}
  },
  "informasi_pemrosesan": {
    "purpose": {"value": "...", "confidence": 0.9},
    "jenis_pemrosesan": {"value": "...", "confidence": 0.8},
    "sistem_terkait": {"value": "...", "confidence": 0.7},
    "legal_basis": {"value": "...", "confidence": 0.85}
  },
  "pengumpulan_data": {
    "sumber_data": {"value": "...", "confidence": 0.8},
    "kategori_subjek": {"value": ["..."], "confidence": 0.75},
    "jenis_data": {"value": ["..."], "confidence": 0.8}
  },
  "penggunaan_penyimpanan": {
    "cara_pemrosesan": {"value": "...", "confidence": 0.7},
    "lokasi_penyimpanan": {"value": "...", "confidence": 0.8}
  },
  "pengiriman_data": {
    "transfer_domestik": {"value": "...", "confidence": 0.6},
    "transfer_internasional": {"value": "...", "confidence": 0.5},
    "negara_tujuan": {"value": "...", "confidence": 0.5},
    "safeguards": {"value": "...", "confidence": 0.6}
  },
  "retensi_keamanan": {
    "retention_period": {"value": "...", "confidence": 0.8},
    "prosedur_pemusnahan": {"value": "...", "confidence": 0.6},
    "langkah_keamanan": {"value": "...", "confidence": 0.7}
  }
}
JSON;
    }

    private function getDpiaOutputFormat(): string
    {
        return <<<'JSON'
{
  "description": {"value": "...", "confidence": 0.9},
  "risk_level": {"value": "low|medium|high|critical", "confidence": 0.8},
  "potensi_risiko": {
    "<Category Label dari library — gunakan EXACT label, mis. 'Legal Basis'>": {
      "answer": "ya|sebagian|tidak",
      "description": "ringkasan konten dokumen yang relevan dengan kategori ini",
      "risk_events": [
        {
          "risk_event": "<persis text dari library, JANGAN ubah satu karakter pun>",
          "dampak": 1,
          "probabilitas": 1,
          "penanganan": "mitigate|accept|transfer|terminate",
          "notes": "konteks dari dokumen yang menjustifikasi risk ini",
          "confidence": 0.85
        }
      ]
    }
  }
}
JSON;
    }

    private function getRopaFieldSchema(): string
    {
        return <<<'SCHEMA'
Section 1 - Detail Pemrosesan:
  - processing_activity: Nama aktivitas pemrosesan data
  - entity: Nama entitas/organisasi yang memproses
  - division: Divisi/departemen terkait
  - work_unit: Unit kerja
  - description: Deskripsi aktivitas pemrosesan
  - risk_level: Tingkat risiko (low/medium/high)

Section 2 - Data Protection Team:
  - kategori_pemrosesan: Kategori pemrosesan data
  - dpo_name: Nama Data Protection Officer
  - dpo_email: Email DPO
  - dpo_phone: No. telepon DPO

Section 3 - Informasi Pemrosesan:
  - purpose: Tujuan pemrosesan data
  - jenis_pemrosesan: Jenis pemrosesan (e.g. collection, storage, processing)
  - sistem_terkait: Sistem informasi terkait
  - legal_basis: Dasar hukum pemrosesan

Section 4 - Pengumpulan Data:
  - sumber_data: Sumber data (langsung/tidak langsung)
  - kategori_subjek: Kategori subjek data (Array: karyawan, pelanggan, etc.)
  - jenis_data: Jenis data pribadi yang dikumpulkan (Array)

Section 5 - Penggunaan & Penyimpanan:
  - cara_pemrosesan: Cara data diproses
  - lokasi_penyimpanan: Lokasi penyimpanan data

Section 6 - Pengiriman Data:
  - transfer_domestik: Info transfer data domestik
  - transfer_internasional: Info transfer data internasional
  - negara_tujuan: Negara tujuan transfer (jika ada)
  - safeguards: Langkah pengamanan transfer

Section 7 - Retensi & Keamanan:
  - retention_period: Periode retensi data
  - prosedur_pemusnahan: Prosedur pemusnahan data
  - langkah_keamanan: Langkah keamanan yang diterapkan
SCHEMA;
    }

    private function getDpiaFieldSchema(): string
    {
        return <<<'SCHEMA'
- description: Deskripsi kegiatan pemrosesan data
- risk_level: Tingkat risiko (low/medium/high/critical)
- potensi_risiko: Object keyed by Category Label dari Risk Event Library di bawah.
    Setiap kategori berisi:
      - answer: 'ya' | 'sebagian' | 'tidak' (apakah kategori ini relevan/teridentifikasi di dokumen)
      - description: Ringkasan konten dokumen relevan
      - risk_events: Array of risk events. Tiap event berisi:
          - risk_event: HARUS pilih dari Risk Event Library (text exact match)
          - dampak: skor 1-5
          - probabilitas: skor 1-5
          - penanganan: 'mitigate' | 'accept' | 'transfer' | 'terminate'
          - notes: konteks dari dokumen
          - confidence: 0.0-1.0
SCHEMA;
    }

    /**
     * Call the AI provider API.
     */
    private function callAi(array $config, string $prompt): string
    {
        $provider = $config['provider'];
        $model = $config['model'];
        $apiKey = $config['api_key'];
        $baseUrl = rtrim($config['base_url'], '/');

        $headers = ['Content-Type' => 'application/json'];
        $authH = ($config['auth_header'] ?? '') ?: 'Authorization';
        $authP = ($config['auth_header'] && ! ($config['auth_prefix'] ?? '')) ? '' : (($config['auth_prefix'] ?? '') ?: 'Bearer');
        if ($authP) {
            $headers[$authH] = $authP.' '.$apiKey;
        } else {
            $headers[$authH] = $apiKey;
        }

        // DPIA mapping carries 22 buckets × ~5 events × ~80 chars of library plus
        // potentially per-category responses → bump ceiling so output isn't truncated.
        $maxTokens = ! empty($this->riskLibraryByCategory) ? 6000 : 4096;

        $response = Http::timeout(120)
            ->withoutVerifying()
            ->withHeaders($headers)
            ->post($baseUrl.'/chat/completions', [
                'model' => $model->model_id,
                'messages' => [
                    ['role' => 'system', 'content' => 'Kamu adalah AI compliance analyst spesialis UU PDP Indonesia. Jawab HANYA dalam JSON yang valid.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => $maxTokens,
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            $err = $response->json()['error']['message'] ?? $response->body();
            throw new \RuntimeException('AI API Error: '.substr($err, 0, 300));
        }

        return $response->json()['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Parse the AI response into structured mapped fields.
     */
    private function parseAiResponse(string $response, string $targetModule): array
    {
        // Clean up markdown code blocks if present
        $cleaned = preg_replace('/^```(?:json)?\s*/m', '', $response);
        $cleaned = preg_replace('/```\s*$/m', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);

        if (! $parsed || ! is_array($parsed)) {
            return [
                'mapped_fields' => [],
                'confidence_scores' => [],
                'raw_response' => $response,
                'parse_error' => 'AI response bukan JSON valid.',
            ];
        }

        // Extract values and confidence scores separately
        $mappedFields = [];
        $confidenceScores = [];
        $validationWarnings = [];

        foreach ($parsed as $sectionKey => $sectionFields) {
            // DPIA potensi_risiko: special-case category-keyed structure with
            // risk_events array that needs library validation.
            if ($targetModule === 'dpia' && $sectionKey === 'potensi_risiko' && is_array($sectionFields)) {
                $cleanCategories = $this->validatePotensiRisiko($sectionFields, $validationWarnings);
                $mappedFields[$sectionKey] = $cleanCategories;
                $confidenceScores[$sectionKey] = []; // confidence stored inline per event

                continue;
            }

            if (! is_array($sectionFields)) {
                // top-level scalar field (e.g. DPIA description shorthand)
                $mappedFields[$sectionKey] = $sectionFields;

                continue;
            }

            // Heuristic: is this a "section" (nested {value, confidence} fields)
            // OR a single field at top level with shape {value, confidence}?
            if (isset($sectionFields['value'])) {
                $mappedFields[$sectionKey] = $sectionFields['value'];
                $confidenceScores[$sectionKey] = $sectionFields['confidence'] ?? 0.5;

                continue;
            }

            $mappedFields[$sectionKey] = [];
            $confidenceScores[$sectionKey] = [];

            foreach ($sectionFields as $fieldKey => $fieldData) {
                if (is_array($fieldData) && isset($fieldData['value'])) {
                    $mappedFields[$sectionKey][$fieldKey] = $fieldData['value'];
                    $confidenceScores[$sectionKey][$fieldKey] = $fieldData['confidence'] ?? 0.5;
                } else {
                    // Direct value (no confidence wrapper)
                    $mappedFields[$sectionKey][$fieldKey] = $fieldData;
                    $confidenceScores[$sectionKey][$fieldKey] = 0.5;
                }
            }
        }

        // Calculate average confidence (recursive — ignore non-numeric leaves)
        $totalConf = 0;
        $fieldCount = 0;
        $walker = function ($val) use (&$walker, &$totalConf, &$fieldCount) {
            if (is_numeric($val)) {
                $totalConf += (float) $val;
                $fieldCount++;
            } elseif (is_array($val)) {
                foreach ($val as $v) {
                    $walker($v);
                }
            }
        };
        $walker($confidenceScores);
        $avgConfidence = $fieldCount > 0 ? round($totalConf / $fieldCount, 3) : 0;

        $result = [
            'mapped_fields' => $mappedFields,
            'confidence_scores' => $confidenceScores,
            'average_confidence' => $avgConfidence,
            'raw_response' => $response,
        ];
        if (! empty($validationWarnings)) {
            $result['validation_warnings'] = $validationWarnings;
        }

        return $result;
    }

    /**
     * Validate AI-returned potensi_risiko object against the loaded risk library.
     *
     * Rule: every risk_events[].risk_event MUST match an entry in the library
     * allowlist (exact text). Mismatches are tagged `_unverified=true` (kept for
     * manual review rather than silently dropped — DPO can decide if AI output
     * is a useful new template seed). Logs a warning summary.
     */
    private function validatePotensiRisiko(array $potensiRisiko, array &$warnings): array
    {
        $clean = [];
        $unverifiedCount = 0;
        $totalCount = 0;
        $allowlistEmpty = empty($this->riskLibraryAllowlist);

        foreach ($potensiRisiko as $categoryLabel => $categoryData) {
            if (! is_array($categoryData)) {
                continue;
            }

            $eventsOut = [];
            $eventsIn = $categoryData['risk_events'] ?? [];
            if (is_array($eventsIn)) {
                foreach ($eventsIn as $ev) {
                    if (! is_array($ev)) {
                        continue;
                    }
                    $totalCount++;
                    $text = trim((string) ($ev['risk_event'] ?? ''));
                    if ($text === '') {
                        continue;
                    }

                    if (! $allowlistEmpty && ! isset($this->riskLibraryAllowlist[$text])) {
                        $ev['_unverified'] = true;
                        $unverifiedCount++;
                        $warnings[] = "Risk event tidak match library — kategori='{$categoryLabel}', event='{$text}'";
                    }
                    $eventsOut[] = $ev;
                }
            }

            $clean[$categoryLabel] = [
                'answer' => $categoryData['answer'] ?? null,
                'description' => $categoryData['description'] ?? null,
                'risk_events' => $eventsOut,
            ];
        }

        if ($unverifiedCount > 0) {
            Log::warning('AiFieldMappingService: AI returned risk events not in library', [
                'unverified' => $unverifiedCount,
                'total' => $totalCount,
            ]);
        }

        return $clean;
    }
}
