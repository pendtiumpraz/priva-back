<?php

namespace App\Services;

use App\Http\Controllers\Api\AiProviderController;
use App\Models\Ropa;
use Illuminate\Support\Facades\Http;

class AiFieldMappingService
{
    /**
     * Map extracted document data to ROPA fields using AI.
     *
     * @param array $extractedData Output from DocumentParserService
     * @param string $targetModule 'ropa' or 'dpia'
     * @param string|null $orgId Tenant org ID for AI provider resolution
     * @return array ['mapped_fields' => [...], 'confidence_scores' => [...], 'raw_response' => '...']
     */
    public function map(array $extractedData, string $targetModule, ?string $orgId): array
    {
        // Use 'document' mode AI provider (falls back to 'chat' if not set)
        $config = AiProviderController::getActiveConfig($orgId, 'document');

        if (!$config) {
            throw new \RuntimeException('AI provider belum dikonfigurasi. Silakan setting AI provider terlebih dahulu di Settings.');
        }

        $prompt = $this->buildPrompt($extractedData, $targetModule);

        $response = $this->callAi($config, $prompt);

        return $this->parseAiResponse($response, $targetModule);
    }

    /**
     * Build the extraction prompt.
     */
    private function buildPrompt(array $extractedData, string $targetModule): string
    {
        $rawText = $extractedData['raw_text'] ?? '';
        // Truncate to ~12000 chars to stay within context limits
        if (mb_strlen($rawText) > 12000) {
            $rawText = mb_substr($rawText, 0, 12000) . "\n\n[...dokumen terpotong karena terlalu panjang...]";
        }

        if ($targetModule === 'ropa') {
            $targetFields = $this->getRopaFieldSchema();
        } else {
            $targetFields = $this->getDpiaFieldSchema();
        }

        return <<<PROMPT
Kamu adalah AI compliance analyst spesialis UU Pelindungan Data Pribadi (PDP) Indonesia.

TUGAS: Ekstrak dan mapping informasi dari dokumen berikut ke field-field yang ditentukan.

=== DOKUMEN ===
{$rawText}

=== TARGET FIELDS (JSON Schema) ===
{$targetFields}

=== INSTRUKSI ===
1. Untuk setiap field, cari informasi yang relevan dari dokumen.
2. Jika informasi tidak ditemukan, isi dengan null.
3. Berikan confidence score (0.0 - 1.0) untuk setiap field yang berhasil di-mapping.
4. Untuk field array (data_categories, data_subjects, recipients), berikan sebagai array string.
5. Untuk risk_level, tentukan berdasarkan konteks: "low", "medium", atau "high".
6. Jawab HANYA dalam format JSON yang valid, tanpa markdown code blocks.

FORMAT RESPONS (JSON):
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
PROMPT;
    }

    private function getRopaFieldSchema(): string
    {
        return <<<SCHEMA
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
        return <<<SCHEMA
- description: Deskripsi kegiatan pemrosesan data
- risk_level: Tingkat risiko (low/medium/high/critical)
- risk_assessment: Penilaian risiko (JSON object with threats, likelihood, impact)
- mitigation_measures: Langkah mitigasi risiko (JSON array)
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
        $authP = ($config['auth_header'] && !($config['auth_prefix'] ?? '')) ? '' : (($config['auth_prefix'] ?? '') ?: 'Bearer');
        if ($authP) {
            $headers[$authH] = $authP . ' ' . $apiKey;
        } else {
            $headers[$authH] = $apiKey;
        }

        $response = Http::timeout(120)
            ->withoutVerifying()
            ->withHeaders($headers)
            ->post($baseUrl . '/chat/completions', [
                'model' => $model->model_id,
                'messages' => [
                    ['role' => 'system', 'content' => 'Kamu adalah AI compliance analyst spesialis UU PDP Indonesia. Jawab HANYA dalam JSON yang valid.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens' => 4096,
                'temperature' => 0.2,
            ]);

        if (!$response->successful()) {
            $err = $response->json()['error']['message'] ?? $response->body();
            throw new \RuntimeException("AI API Error: " . substr($err, 0, 300));
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

        if (!$parsed || !is_array($parsed)) {
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

        foreach ($parsed as $sectionKey => $sectionFields) {
            if (!is_array($sectionFields)) continue;

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

        // Calculate average confidence
        $totalConf = 0;
        $fieldCount = 0;
        foreach ($confidenceScores as $section) {
            foreach ($section as $score) {
                $totalConf += $score;
                $fieldCount++;
            }
        }
        $avgConfidence = $fieldCount > 0 ? round($totalConf / $fieldCount, 3) : 0;

        return [
            'mapped_fields' => $mappedFields,
            'confidence_scores' => $confidenceScores,
            'average_confidence' => $avgConfidence,
            'raw_response' => $response,
        ];
    }
}
