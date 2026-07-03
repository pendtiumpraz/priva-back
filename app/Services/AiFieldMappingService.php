<?php

namespace App\Services;

use App\Http\Controllers\Api\AiProviderController;
use App\Models\DpiaRiskEventTemplate;
use App\Models\Ropa;
use App\Support\OutboundHttp;
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
     * Cache: org-custom wizard sections for current call (loaded when targetModule
     * is ropa/dpia and orgId is provided). Used for prompt injection so AI knows
     * to extract custom field values from the document.
     *
     * Shape: array<int, ['section_key', 'label', 'description', 'fields' => [...]]>
     */
    private array $customSchema = [];

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

        // Phase 8: Load org-custom wizard schema (ROPA/DPIA only) so AI can
        // map values into custom fields too. Skipped when org context missing.
        if (in_array($targetModule, ['ropa', 'dpia'], true) && $orgId) {
            $this->loadCustomSchema($orgId, $targetModule);
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
     * Phase 8: Load org-custom sections + fields for the current org+module.
     *
     * Filters out built-in sections (which AI handles via the canonical
     * field schema) — only the `org_custom` slice is injected as custom
     * fields the AI must populate alongside the canonical structure.
     */
    private function loadCustomSchema(string $orgId, string $module): void
    {
        try {
            $service = app(WizardSchemaService::class);
            $schema = $service->getSchema($orgId, $module);
            $this->customSchema = array_values(array_filter(
                $schema,
                fn ($s) => ($s['source'] ?? null) === 'org_custom'
                    && is_array($s['fields'] ?? null)
                    && count($s['fields']) > 0,
            ));
        } catch (\Throwable $e) {
            Log::warning('AiFieldMappingService: failed to load custom schema', [
                'org_id' => $orgId,
                'module' => $module,
                'error' => $e->getMessage(),
            ]);
            $this->customSchema = [];
        }
    }

    /**
     * Build the custom fields section of the AI prompt — Phase 8.
     *
     * Returns empty string when no custom sections are loaded so the prompt
     * stays clean for orgs that don't use custom fields.
     */
    private function buildCustomFieldsPromptSection(): string
    {
        if (empty($this->customSchema)) {
            return '';
        }

        $lines = ["\n\n=== SECTION CUSTOM (org-specific) yang juga harus di-extract ===\n"];
        foreach ($this->customSchema as $sec) {
            $secLabel = (string) ($sec['label'] ?? $sec['section_key'] ?? 'Custom');
            $secKey = (string) ($sec['section_key'] ?? '');
            $lines[] = "[Section: {$secLabel}] (key: {$secKey})";
            if (! empty($sec['description'])) {
                $lines[] = "  Deskripsi: {$sec['description']}";
            }
            foreach (($sec['fields'] ?? []) as $f) {
                $name = (string) ($f['name'] ?? '');
                $label = (string) ($f['label'] ?? $name);
                $type = (string) ($f['type'] ?? 'text');
                $required = ! empty($f['is_required']) ? ' [REQUIRED]' : '';
                $extra = '';
                if (in_array($type, ['select', 'multiselect'], true) && is_array($f['options'] ?? null) && count($f['options']) > 0) {
                    $opts = array_slice($f['options'], 0, 30);
                    $extra = ', options: ['.implode(' | ', array_map('strval', $opts)).']';
                }
                $help = ! empty($f['help_text']) ? " — {$f['help_text']}" : '';
                $lines[] = "  - {$label} (key: {$name}, type: {$type}{$extra}){$required}{$help}";
            }
            $lines[] = '';
        }

        $lines[] = 'INSTRUCTIONS untuk SECTION CUSTOM:';
        $lines[] = '- Extract value dari konten dokumen kalau ada relevansi yang jelas. Skip kalau tidak ada (jangan invent).';
        $lines[] = '- Output di JSON tambahan: "custom_fields": { "{field_name}": <value>, ... }';
        $lines[] = '- Type-aware values:';
        $lines[] = '  * text/textarea: string';
        $lines[] = '  * number: numeric (integer atau decimal)';
        $lines[] = '  * date: YYYY-MM-DD';
        $lines[] = '  * boolean: true/false';
        $lines[] = '  * select: WAJIB pilih PERSIS dari options di atas (string exact match)';
        $lines[] = '  * multiselect: array of strings, semua dari options di atas';
        $lines[] = '  * tags: array of strings (free-form)';

        return implode("\n", $lines);
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

        // Phase 8: append custom fields section + custom_fields slot in output
        $customFieldsSection = $this->buildCustomFieldsPromptSection();
        $customOutputSlot = ! empty($this->customSchema)
            ? ",\n  \"custom_fields\": { \"<field_name>\": <value>, \"...\": \"...\" }"
            : '';

        return <<<PROMPT
Kamu adalah AI compliance analyst spesialis UU Pelindungan Data Pribadi (PDP) Indonesia.

TUGAS: Ekstrak dan mapping informasi dari dokumen berikut ke field-field yang ditentukan.

=== DOKUMEN ===
{$rawText}

=== TARGET FIELDS (JSON Schema) ===
{$targetFields}{$riskLibrarySection}{$customFieldsSection}

=== INSTRUKSI ===
1. Untuk setiap field, cari informasi yang relevan dari dokumen.
2. Jika informasi tidak ditemukan, isi dengan null.
3. Berikan confidence score (0.0 - 1.0) untuk setiap field yang berhasil di-mapping.
4. Untuk field array (data_categories, data_subjects, recipients), berikan sebagai array string.
5. Untuk risk_level, tentukan berdasarkan konteks: "low", "medium", atau "high".
6. Jawab HANYA dalam format JSON yang valid, tanpa markdown code blocks.

FORMAT RESPONS (JSON):
{$outputFormat}{$customOutputSlot}
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

        $response = OutboundHttp::client($baseUrl)
            ->timeout(120)
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

            // Phase 8: custom_fields is a flat name->value map (no nested
            // {value, confidence} wrappers per the prompt). Persist as-is so
            // the FE renders into wizard_data.custom_fields[{field_name}].
            if ($sectionKey === 'custom_fields' && is_array($sectionFields)) {
                $mappedFields[$sectionKey] = $sectionFields;
                $confidenceScores[$sectionKey] = [];
                foreach ($sectionFields as $fieldKey => $fieldVal) {
                    $confidenceScores[$sectionKey][$fieldKey] = 0.7;
                }

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
