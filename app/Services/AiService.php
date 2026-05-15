<?php

namespace App\Services;

use App\Http\Controllers\Api\AiProviderController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    private string $apiKey;

    private string $model;

    private string $baseUrl;

    private string $authHeader;

    private string $authPrefix;

    private string $locale = 'id';

    /**
     * Initialize AiService with the active provider config.
     *
     * @param  string|null  $orgId  Tenant org_id UUID (null = use legacy DeepSeek)
     * @param  string  $mode  'chat' or 'agent'
     */
    public function __construct(?string $orgId = null, string $mode = 'chat')
    {
        // Try multi-provider config first
        $config = AiProviderController::getActiveConfig($orgId, $mode);

        if ($config && ! empty($config['api_key'])) {
            $this->apiKey = $config['api_key'];
            $this->model = $config['model']->model_id ?? '';
            $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
            $this->authHeader = $config['auth_header'] ?: 'Authorization';
            $this->authPrefix = ($config['auth_header'] && ! ($config['auth_prefix'] ?? '')) ? '' : ($config['auth_prefix'] ?: 'Bearer');
        } else {
            // Null state, will fail cleanly in isAvailable() checking
            $this->apiKey = '';
            $this->model = '';
            $this->baseUrl = '';
            $this->authHeader = 'Authorization';
            $this->authPrefix = 'Bearer';
        }
    }

    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Set the user's preferred locale for AI responses.
     */
    public function setLocale(string $locale): self
    {
        $this->locale = in_array($locale, ['id', 'en']) ? $locale : 'id';

        return $this;
    }

    /**
     * Get a language directive to prepend to system prompts.
     */
    private function getLanguageDirective(): string
    {
        if ($this->locale === 'en') {
            return "IMPORTANT: You MUST reply entirely in English. All text, labels, and content must be in English.\n\n";
        }

        return ''; // Default is Indonesian, prompts are already in ID
    }

    /**
     * Send a prompt to the active LLM provider and get structured JSON response
     */
    public function ask(string $systemPrompt, string $userPrompt, int $maxTokens = 2000): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        // Prompt size guard — REJECT sebelum HTTP call ke provider supaya
        // tidak ada biaya yang ke-charge untuk prompt oversized. Throws
        // PromptTooLargeException → render() jadi HTTP 413.
        // Dihitung gabungan system + language directive + user supaya
        // total real yang sampai ke provider yang di-validate.
        $combined = $this->getLanguageDirective().$systemPrompt."\n\n".$userPrompt;
        app(\App\Services\AiPromptGuard::class)->assertPromptSize($combined);

        // Output guard — clamp max_tokens ke hard cap supaya caller tidak
        // bisa minta output 100000 token (drain kuota). Hard cap di
        // config('security.ai.max_output_tokens', default 4000). Caller
        // tetap boleh minta kurang (mis. 2000 untuk JSON kecil); guard
        // hanya menurunkan, tidak menaikkan.
        $maxTokens = app(\App\Services\AiOutputGuard::class)->clampMaxTokens($maxTokens);

        // Generate a unique cache key based on the model, prompts and language
        $cacheKey = 'ai_resp_'.md5($this->model.$systemPrompt.$userPrompt.$this->locale);

        // Check if we have a cached response (TTL 24 hours)
        if ($cached = Cache::get($cacheKey)) {
            Log::info("AI Cache Hit for model [{$this->model}]");

            return $cached;
        }

        try {
            $headers = ['Content-Type' => 'application/json'];
            if ($this->authPrefix) {
                $headers[$this->authHeader] = $this->authPrefix.' '.$this->apiKey;
            } else {
                $headers[$this->authHeader] = $this->apiKey;
            }

            $timeout = (int) env('AI_TIMEOUT', 180);

            $payload = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->getLanguageDirective().$systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.3,
                'max_tokens' => $maxTokens,
                // Force JSON-only output. Sebagian besar provider modern
                // (OpenAI / OpenRouter / DeepSeek) support flag ini dan akan
                // menjamin model tidak menambah teks penjelasan di luar JSON.
                'response_format' => ['type' => 'json_object'],
            ];

            Log::info('AI Request DISPATCH', [
                'model' => $this->model,
                'base_url' => $this->baseUrl,
                'system_chars' => mb_strlen($systemPrompt),
                'user_chars' => mb_strlen($userPrompt),
                'max_tokens' => $maxTokens,
                'locale' => $this->locale,
            ]);

            $t0 = microtime(true);
            $response = Http::timeout($timeout)
                ->withoutVerifying()
                ->withHeaders($headers)
                ->post($this->baseUrl.'/chat/completions', $payload);
            $elapsed = round(microtime(true) - $t0, 2);

            // Beberapa provider tolak `response_format` (HTTP 400). Retry
            // tanpa flag itu supaya tetap dapat response.
            if ($response->status() === 400 && str_contains((string) $response->body(), 'response_format')) {
                Log::warning("AI provider rejected response_format flag [{$this->model}], retrying without it", [
                    'body_preview' => mb_substr((string) $response->body(), 0, 300),
                ]);
                unset($payload['response_format']);
                $t0 = microtime(true);
                $response = Http::timeout($timeout)
                    ->withoutVerifying()
                    ->withHeaders($headers)
                    ->post($this->baseUrl.'/chat/completions', $payload);
                $elapsed = round(microtime(true) - $t0, 2);
            }

            Log::info('AI Response RECEIVED', [
                'model' => $this->model,
                'status' => $response->status(),
                'elapsed_sec' => $elapsed,
                'body_bytes' => strlen((string) $response->body()),
            ]);

            if ($response->failed()) {
                Log::error('AI Provider API error ['.$this->model.']: '.$response->body());

                return null;
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';
            $finishReason = $data['choices'][0]['finish_reason'] ?? null;
            $usage = $data['usage'] ?? null;

            Log::info('AI Content EXTRACTED', [
                'model' => $this->model,
                'content_chars' => mb_strlen($content),
                'finish_reason' => $finishReason,
                'usage' => $usage,
                'first_120_chars' => mb_substr($content, 0, 120),
                'last_120_chars' => mb_substr($content, max(0, mb_strlen($content) - 120)),
            ]);

            // Provider menandakan output ter-truncate karena max_tokens habis.
            // Tetap lanjut, tapi log keras supaya user tahu harus naikkan cap.
            if ($finishReason === 'length') {
                Log::warning("AI response TRUNCATED (finish_reason=length) [{$this->model}] — naikkan max_output_tokens", [
                    'content_chars' => mb_strlen($content),
                    'requested_max_tokens' => $maxTokens,
                ]);
            }

            // Output guard — provider sometimes ignore max_tokens kalau
            // user paksa output repetitive ("AAAA..."). Reject di sini
            // dengan exception jelas, daripada return chunk sampah ke
            // caller. Catch + log; return null supaya caller bisa fallback.
            try {
                app(\App\Services\AiOutputGuard::class)->assert($content);
            } catch (\RuntimeException $e) {
                Log::warning('AI Output Guard rejected response ['.$this->model.']: '.$e->getMessage(), [
                    'output_length' => mb_strlen($content),
                ]);

                return null;
            }

            // Robust JSON extraction:
            // (1) trim + strip markdown code fences (```json ... ```)
            // (2) balanced-brace scan ambil first complete object/array
            // (3) fallback ke trim-only kalau scanner tidak ketemu pasangan
            $cleaned = trim($content);
            $hadFence = (bool) preg_match('/```/m', $cleaned);
            $cleaned = preg_replace('/^\s*```(?:json)?\s*|\s*```\s*$/m', '', $cleaned) ?? $cleaned;
            $cleaned = trim($cleaned);

            $extracted = $this->extractBalancedJson($cleaned);
            $balancedFound = $extracted !== null;
            if ($balancedFound) {
                $cleaned = $extracted;
            }

            $parsed = json_decode($cleaned, true);
            $jsonErr = json_last_error_msg();
            $result = $parsed !== null ? $parsed : ['raw' => $content];

            // Hanya cache kalau JSON berhasil di-parse. Kalau parse gagal
            // (response terpotong / bukan JSON), caller bisa retry langsung
            // ke provider tanpa "stuck" di failed cache selama 24 jam.
            if ($parsed !== null) {
                Log::info("AI JSON parse OK [{$this->model}]", [
                    'parsed_keys' => is_array($parsed) ? array_keys($parsed) : [],
                    'had_markdown_fence' => $hadFence,
                    'balanced_extractor_used' => $balancedFound,
                ]);
                Cache::put($cacheKey, $result, now()->addHours(24));
            } else {
                // Hard log — capture seluruh konteks supaya bisa debug remote
                // tanpa harus tambah log statement lagi.
                Log::error("AI JSON PARSE FAILED [{$this->model}]", [
                    'json_error' => $jsonErr,
                    'finish_reason' => $finishReason,
                    'usage' => $usage,
                    'content_chars' => mb_strlen($content),
                    'cleaned_chars' => mb_strlen($cleaned),
                    'had_markdown_fence' => $hadFence,
                    'balanced_extractor_used' => $balancedFound,
                    'raw_full' => mb_substr($content, 0, 8000),
                    'cleaned_full' => mb_substr($cleaned, 0, 8000),
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('AI Service error ['.$this->model.']: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Extract first balanced JSON object atau array dari teks bebas.
     *
     * Lebih aman daripada regex greedy `/\{.*\}/s` yang sering tertangkap
     * placeholder `{var}` di teks penjelasan setelah JSON utama. Scanner
     * ini menghitung kedalaman brace dengan awareness terhadap string
     * dan escape sequences sehingga "{" di dalam string value tidak
     * mengacaukan pairing.
     *
     * Return null kalau tidak ada open-brace atau pairing tidak lengkap
     * (response truncated). Caller bisa fallback ke teks asli.
     */
    private function extractBalancedJson(string $text): ?string
    {
        $firstObj = strpos($text, '{');
        $firstArr = strpos($text, '[');

        if ($firstObj === false && $firstArr === false) {
            return null;
        }

        if ($firstObj === false) {
            $start = $firstArr;
            $open = '['; $close = ']';
        } elseif ($firstArr === false) {
            $start = $firstObj;
            $open = '{'; $close = '}';
        } else {
            if ($firstObj < $firstArr) {
                $start = $firstObj; $open = '{'; $close = '}';
            } else {
                $start = $firstArr; $open = '['; $close = ']';
            }
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $len = strlen($text);

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];

            if ($escape) {
                $escape = false;
                continue;
            }
            if ($inString) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === $open) {
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null; // truncated atau unbalanced
    }

    // =============================================
    // DOMAIN-SPECIFIC AI METHODS
    // =============================================

    /**
     * GAP Assessment: Generate AI remediation plan
     */
    public function gapRemediationPlan(array $recommendations, float $score, string $level): ?array
    {
        $recText = collect($recommendations)->map(fn ($r) => "- [{$r['priority']}] {$r['question']} (Pasal: {$r['article']}) → {$r['recommendation']}"
        )->implode("\n");

        $system = "Kamu adalah konsultan kepatuhan UU PDP Indonesia ahli. Output WAJIB JSON valid.\n"
            .'Format: {"greeting":"...","sections":[{"type":"text|steps|list|tip|warning|info|table|code","title":"...","content":"...","items":[],"table_data":[{"col1":"v1"}],"headers":["col1"]}],"closing":"..."}';

        $user = "Organisasi ini mendapat skor GAP Assessment: {$score}% (level: {$level}).\n\n"
            ."Berikut temuan yang perlu diperbaiki:\n{$recText}\n\n"
            ."Buatkan AI Remediation Plan yang mencakup:\n"
            ."1. Executive summary kondisi saat ini\n"
            ."2. Prioritas tindakan (critical/high/medium) dengan timeline estimasi\n"
            ."3. Quick wins yang bisa dilakukan minggu ini\n"
            ."4. Roadmap 3-6-12 bulan\n"
            ."5. Tips implementasi\n"
            .'Jawab HANYA dalam JSON format yang diminta.';

        return $this->ask($system, $user, 3000);
    }

    /**
     * RoPA: AI Risk Assessment & Legal Basis Advisor
     */
    public function ropaAnalysis(array $ropaData): ?array
    {
        $system = "Kamu adalah DPO (Data Protection Officer) ahli UU PDP Indonesia. Output WAJIB JSON valid.\n"
            .'Format: {"greeting":"...","sections":[{"type":"text|steps|list|tip|warning|info|table|code","title":"...","content":"...","items":[],"table_data":[{"col1":"v1"}],"headers":["col1"]}],"closing":"..."}';

        $dataStr = json_encode($ropaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Analisis RoPA ini dan berikan:\n"
            ."1. Risk assessment: evaluasi risk level berdasarkan data categories, recipients, retention\n"
            ."2. Legal basis advisor: rekomendasi dasar hukum yang tepat berdasarkan tujuan pemrosesan\n"
            ."3. Identifikasi jika ada data pribadi spesifik yang memerlukan DPIA\n"
            ."4. Rekomendasi security measures yang sesuai\n"
            ."5. Warning jika ada potensi pelanggaran UU PDP\n\n"
            ."Data RoPA:\n{$dataStr}\n\nJawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * DPIA: AI Risk Scoring per category
     */
    public function dpiaRiskScoring(array $dpiaData, array $riskAssessment): ?array
    {
        $system = "Kamu adalah ahli penilaian risiko data pribadi. Output WAJIB JSON valid.\n"
            .'Format: {"greeting":"...","sections":[{"type":"text|steps|list|tip|warning|info|table|code","title":"...","content":"...","items":[],"table_data":[{"col1":"v1"}],"headers":["col1"]}],"closing":"..."}';

        $dataStr = json_encode(['dpia' => $dpiaData, 'risks' => $riskAssessment], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Analisis DPIA dan risk assessment ini:\n{$dataStr}\n\n"
            ."Berikan:\n"
            ."1. Risk scoring per kategori (likelihood × impact)\n"
            ."2. Top 5 risiko tertinggi yang perlu mitigasi segera\n"
            ."3. Langkah mitigasi spesifik per risiko\n"
            ."4. Rekomendasi apakah pemrosesan boleh dilanjutkan atau harus dimodifikasi\n"
            .'Jawab HANYA dalam JSON format yang diminta.';

        return $this->ask($system, $user, 2500);
    }

    /**
     * Breach: AI Severity Assessment & Containment
     */
    public function breachAdvisor(array $breachData): ?array
    {
        $system = "Kamu adalah ahli incident response dan breach management UU PDP. Output WAJIB JSON valid.\n"
            .'Format: {"greeting":"...","sections":[{"type":"text|steps|list|tip|warning|info|table|code","title":"...","content":"...","items":[],"table_data":[{"col1":"v1"}],"headers":["col1"]}],"closing":"..."}';

        $dataStr = json_encode($breachData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Insiden data breach berikut baru dilaporkan:\n{$dataStr}\n\n"
            ."Berikan analisis AI:\n"
            ."1. Severity assessment (low/medium/high/critical) dengan justifikasi\n"
            ."2. Immediate containment steps (prioritized, 5-7 langkah)\n"
            ."3. Apakah notifikasi ke KOMDIGI wajib? (3×24 jam sesuai Pasal 46 UU PDP)\n"
            ."4. Template notifikasi ke subjek data (jika wajib)\n"
            ."5. Rekomendasi root cause investigation\n"
            ."6. Warning tentang deadline dan konsekuensi hukum\n"
            .'Jawab HANYA dalam JSON format yang diminta.';

        return $this->ask($system, $user, 3000);
    }

    /**
     * DSR: AI Response Drafter
     */
    public function dsrResponseDraft(array $dsrData): ?array
    {
        $system = "Kamu adalah DPO yang menangani Data Subject Request sesuai UU PDP. Output WAJIB JSON valid.\n"
            .'Format: {"greeting":"...","sections":[{"type":"text|steps|list|tip|warning|info|table|code","title":"...","content":"...","items":[],"table_data":[{"col1":"v1"}],"headers":["col1"]}],"closing":"..."}';

        $dataStr = json_encode($dsrData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Data Subject Request berikut perlu direspons:\n{$dataStr}\n\n"
            ."Berikan:\n"
            ."1. Verifikasi identitas: checklist item yang perlu diverifikasi\n"
            ."2. Draft respons formal ke pemohon (bahasa resmi, profesional)\n"
            ."3. Langkah-langkah internal yang perlu dilakukan\n"
            ."4. Timeline compliance sesuai UU PDP\n"
            ."5. Warning jika ada risiko atau pertimbangan khusus\n"
            .'Jawab HANYA dalam JSON format yang diminta.';

        return $this->ask($system, $user, 2500);
    }

    /**
     * Consent: AI Consent Text Generator
     */
    public function consentTextGenerator(string $purpose, array $dataTypes, string $domain): ?array
    {
        $system = "Kamu adalah privacy lawyer Indonesia ahli drafted consent text UU PDP. Output WAJIB JSON valid.\n"
            .'Format: {"greeting":"...","sections":[{"type":"text|steps|list|tip|warning|info|table|code","title":"...","content":"...","items":[],"table_data":[{"col1":"v1"}],"headers":["col1"]}],"closing":"..."}';

        $user = "Generate teks consent yang comply UU PDP untuk:\n"
            ."- Tujuan: {$purpose}\n"
            .'- Jenis data: '.implode(', ', $dataTypes)."\n"
            ."- Domain: {$domain}\n\n"
            ."Berikan:\n"
            ."1. Teks consent utama (bahasa Indonesia formal, mudah dipahami)\n"
            ."2. Detail per jenis data yang dikumpulkan\n"
            ."3. Informasi hak subjek data\n"
            ."4. Informasi kontak DPO\n"
            ."5. Tips: apa yang HARUS dan TIDAK BOLEH ada dalam consent\n"
            .'Jawab HANYA dalam JSON format yang diminta.';

        return $this->ask($system, $user, 2500);
    }

    /**
     * Consent: AI Consent Items Generator (Autofill Array)
     */
    public function consentItemsGenerator(string $tenantContext, string $pointName, string $pointDomain, array $existingItems = []): ?array
    {
        $system = "Kamu adalah privacy lawyer Indonesia ahli UU PDP. Output WAJIB JSON valid.\n"
            ."Format: {\"items\":[{\"title\":\"...\",\"description\":\"...\",\"full_text\":\"...\",\"is_required\":true/false}]}\n"
            ."Buat sekumpulan item persetujuan (consent items) yang relevan untuk collection point ini.\n"
            ."KONTEKS ORGANISASI:\n{$tenantContext}\n";

        if (! empty($existingItems)) {
            $system .= 'PERINGATAN PENTING: Berikut adalah item yang SUDAH ADA. JANGAN MEMBUAT item yang mirip, sama, atau berduplikat dengan ini: '.implode(', ', $existingItems)."\n";
        }

        $user = "Collection Point: {$pointName}\nDomain: {$pointDomain}\n\n"
            ."Berikan 3-6 consent items BARU yang penting (misal: Analytical Cookies, Marketing, Profiling, dsb).\n"
            ."Sesuaikan `is_required` dengan aturan (kalo essential/functional = true).\n"
            .'Jawab HANYA dalam JSON format yang diminta.';

        return $this->ask($system, $user, 2500);
    }

    /**
     * Dashboard: AI Compliance Summary
     */
    public function complianceSummary(array $stats): ?array
    {
        $system = "Kamu adalah konsultan kepatuhan UU PDP senior. Output WAJIB JSON valid.\n"
            .'Format: {"greeting":"...","sections":[{"type":"text|steps|list|tip|warning|info|table|code","title":"...","content":"...","items":[],"table_data":[{"col1":"v1"}],"headers":["col1"]}],"closing":"..."}';

        $dataStr = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Berikan executive compliance summary berdasarkan data dashboard ini:\n{$dataStr}\n\n"
            ."Berikan:\n"
            ."1. Ringkasan kondisi kepatuhan (2-3 kalimat bahasa manajemen/eksekutif)\n"
            ."2. Top 3 achievement\n"
            ."3. Top 3 area yang perlu perhatian segera\n"
            ."4. Rekomendasi quick wins minggu ini\n"
            ."5. Risk alert jika ada hal kritis\n"
            .'Jawab HANYA dalam JSON format yang diminta.';

        return $this->ask($system, $user, 2000);
    }

    /**
     * Fire Drill: AI Custom Scenario Generator
     */
    public function customDrillScenario(string $industry, string $riskProfile, int $questionCount = 5): ?array
    {
        $system = "Kamu adalah cybersecurity incident response trainer. Output WAJIB JSON valid.\n"
            ."Format output KHUSUS untuk drill scenario:\n"
            .'{"title":"...","description":"...","briefing":"...","questions":[{"id":"AI-Q1","phase":"...","question":"...","time_limit":60,"type":"single","options":[{"id":"a","text":"...","score":0-10,"feedback":"..."}]}]}';

        $user = "Generate skenario fire drill breach simulation kustom:\n"
            ."- Industri: {$industry}\n"
            ."- Risk profile: {$riskProfile}\n"
            ."- Jumlah pertanyaan: {$questionCount}\n\n"
            ."Buat skenario realistis dengan:\n"
            ."1. Briefing situasi yang menegangkan\n"
            ."2. {$questionCount} pertanyaan multi-fase (Detection, Assessment, Containment, Notification, Recovery)\n"
            ."3. Setiap pertanyaan punya 4 pilihan jawaban dengan skor (0-10) dan feedback\n"
            ."4. Bahasa Indonesia\n"
            .'Output JSON mentah, BUKAN dalam format sections.';

        return $this->ask($system, $user, 4000);
    }

    // =============================================
    // AUTO-FILL METHODS (output maps directly to DB columns)
    // =============================================

    /**
     * RoPA Auto-Fill: Generate complete RoPA draft from activity name + tenant context
     *
     * IMPORTANT: All checkbox/radio/select values MUST exactly match the frontend constants.
     */
    public function ropaAutoFill(string $activityName, string $tenantContext): ?array
    {
        // Exact valid option values from frontend
        $validKategoriPemrosesan = ['Pengendali Data Pribadi', 'Pemroses Data Pribadi'];
        $validJenisPemrosesan = [
            'Pemerolehan dan pengumpulan data', 'Pengolahan dan penganalisisan data',
            'Penyimpanan data', 'Perbaikan dan pembaruan data',
            'Penampilan, pengumuman, transfer, penyebarluasan, atau pengungkapan data',
            'Penghapusan atau pemusnahan data',
        ];
        $validDasarPemrosesan = [
            'Persetujuan yang Sah Secara Eksplisit', 'Pemenuhan Kewajiban Perjanjian',
            'Pemenuhan Kewajiban Hukum', 'Pemenuhan Pelindungan Kepentingan Vital',
            'Pelaksanaan Tugas dalam Rangka Kepentingan Umum',
            'Pemenuhan Kepentingan yang Sah (Legitimate Interest)',
        ];
        $validJumlahSubjek = ['≤ 1.000 subjek', '> 1.000 subjek'];
        $validDataSpesifik = [
            'Data Kesehatan', 'Data Biometrik', 'Data Genetika', 'Data Catatan Kejahatan',
            'Data Anak', 'Data Keuangan Pribadi', 'Data Ras/Etnis',
            'Data Pandangan Politik', 'Data Agama/Kepercayaan', 'Data Orientasi Seksual',
        ];
        $validDataUmum = [
            'Nama Lengkap', 'Jenis Kelamin', 'Kewarganegaraan', 'Agama', 'Status Perkawinan',
            'Alamat', 'Nomor Telepon', 'Email', 'Tanggal Lahir', 'Pendidikan', 'Pekerjaan',
        ];
        $validDataPii = [
            'NIK/KTP', 'Nomor Paspor', 'SIM', 'NPWP', 'Nomor Rekening',
            'Alamat IP (IP Address)', 'Cookie ID', 'Device ID',
        ];
        $validKategoriPihak = [
            'Pengendali Data (Controller)', 'Pemroses Data (Processor)',
            'Pengendali Bersama (Joint Controller)', 'Lainnya',
        ];
        $validKontrolKeamanan = [
            'Enkripsi (at-rest & in-transit)', 'Tokenization / Pseudonymization',
            'Access Control (RBAC)', 'Backup & Disaster Recovery',
            'Audit Log & Monitoring', 'Vulnerability Assessment', 'Penetration Testing',
        ];
        // Sprint E4 — risk trigger fields (auto-risk calculator reads these)
        $validBantuanAi = [
            'Ya (Keputusan Sepenuhnya menggunakan AI)',
            'Ya (Keputusan Akhir dari Manusia)',
            'Sebagian dari Pemrosesan',
            'Tidak menggunakan bantuan AI',
        ];
        $validOtomatis = [
            'Ya, Keputusan Penuh',
            'Ya, Keputusan Akhir dari Manusia',
            'Sebagian dari Pemrosesan',
            'Tidak',
        ];
        $validPemrofilan = [
            'Marketing', 'Advertisement', 'Penawaran Produk',
            'Peningkatan Pengalaman Pengguna', 'Personalisasi Konten',
            'Lainnya', 'Not Applicable',
        ];

        $system = "Kamu adalah DPO (Data Protection Officer) ahli UU PDP Indonesia.\n"
            ."Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
            ."KONTEKS ORGANISASI:\n{$tenantContext}\n\n"
            ."ATURAN PENTING — NILAI CHECKBOX/RADIO/SELECT:\n"
            ."Setiap field checkbox, radio, atau select HARUS menggunakan nilai PERSIS dari daftar opsi berikut.\n"
            ."JANGAN buat nilai sendiri. HANYA pilih dari opsi yang tersedia.\n\n"
            .'— kategori_pemrosesan (pilih 1): '.json_encode($validKategoriPemrosesan)."\n"
            .'— jenis_pemrosesan (pilih beberapa): '.json_encode($validJenisPemrosesan, JSON_UNESCAPED_UNICODE)."\n"
            .'— dasar_pemrosesan (pilih 1): '.json_encode($validDasarPemrosesan, JSON_UNESCAPED_UNICODE)."\n"
            .'— jumlah_subjek (pilih 1): '.json_encode($validJumlahSubjek, JSON_UNESCAPED_UNICODE)."\n"
            .'— jenis_data_spesifik (pilih yg relevan): '.json_encode($validDataSpesifik, JSON_UNESCAPED_UNICODE)."\n"
            .'— jenis_data_umum (pilih yg relevan): '.json_encode($validDataUmum, JSON_UNESCAPED_UNICODE)."\n"
            .'— jenis_data_pii (pilih yg relevan): '.json_encode($validDataPii, JSON_UNESCAPED_UNICODE)."\n"
            .'— kategori_pihak (pilih beberapa): '.json_encode($validKategoriPihak, JSON_UNESCAPED_UNICODE)."\n"
            .'— kontrol_keamanan (pilih beberapa): '.json_encode($validKontrolKeamanan, JSON_UNESCAPED_UNICODE)."\n"
            ."— pihak_ketiga, ada_penerima, transfer_luar, teknologi_baru (pilih 1): [\"Ya\", \"Tidak\"]\n"
            .'— bantuan_ai (pilih 1): '.json_encode($validBantuanAi, JSON_UNESCAPED_UNICODE)."\n"
            .'— otomatis (pilih 1): '.json_encode($validOtomatis, JSON_UNESCAPED_UNICODE)."\n"
            .'— pemrofilan (pilih beberapa, array): '.json_encode($validPemrofilan, JSON_UNESCAPED_UNICODE)."\n\n"
            ."RISK_LEVEL OTOMATIS: risk_level akan DI-COMPUTE ulang oleh backend dari trigger wizard\n"
            ."(bantuan_ai=Ya-penuh, otomatis=Ya-penuh, pemrofilan!=Not Applicable, teknologi_baru=Ya,\n"
            ."jumlah_subjek=>1000, jenis_data_spesifik tidak kosong, transfer_luar=Ya, pernah_insiden=Ya).\n"
            ."Jadi kamu TIDAK perlu set risk_level manual — cukup isi field wizard dengan jujur.\n\n"
            ."FORMAT OUTPUT:\n"
            .json_encode([
                'processing_activity' => 'string',
                'purpose' => 'string (2-3 kalimat)',
                'legal_basis' => 'HARUS dari daftar dasar_pemrosesan di atas',
                'division' => 'string',
                'description' => 'string (3-5 kalimat)',
                'risk_level' => 'low | medium | high',
                'wizard_data' => [
                    'detail_pemrosesan' => [
                        'nama_pemrosesan' => 'string',
                        'entitas' => 'string',
                        'divisi' => 'string',
                        'unit_kerja' => 'string',
                        'deskripsi' => 'string',
                        'risk_level' => 'low | medium | high',
                    ],
                    'dpo_team' => [
                        'kategori_pemrosesan' => 'HARUS dari daftar kategori_pemrosesan',
                        // Sprint E2 — multi-DPO / multi-PIC. Isi minimal 1 DPO.
                        'dpo_list' => [
                            ['name' => 'string', 'email' => 'string', 'phone' => 'string', 'jabatan' => 'string'],
                        ],
                        'pic_list' => [
                            ['name' => 'string', 'email' => 'string', 'jabatan' => 'string', 'divisi' => 'string'],
                        ],
                    ],
                    'informasi_pemrosesan' => [
                        'tujuan' => 'string',
                        'penjelasan' => 'string',
                        'jenis_pemrosesan' => ['HARUS dari daftar jenis_pemrosesan, pilih yg relevan'],
                        'dasar_pemrosesan' => 'HARUS dari daftar dasar_pemrosesan',
                        'sistem_terkait' => ['array: nama sistem IT terkait'],
                        // Sec 3 — Dasar Pemrosesan sub-Q (per opsi UU PDP). Isi field
                        // yang relevan dengan dasar_pemrosesan yang dipilih.
                        'lb_dok_nama' => 'string — nama Persetujuan/Perjanjian (jika dasar=Persetujuan/Perjanjian)',
                        'lb_dok_nomor' => 'string — nomor dokumen (jika dasar=Persetujuan/Perjanjian)',
                        'lb_dok_tanggal' => 'YYYY-MM-DD — tanggal dokumen',
                        'lb_dok_lokasi' => 'string — lokasi penyimpanan dokumen',
                        'lb_reg_nama' => 'string — nama regulasi (jika dasar=Kewajiban Hukum / Kepentingan Vital / Tugas Umum)',
                        'lb_reg_nomor' => 'string — nomor regulasi',
                        'lb_kondisi' => 'string — kondisi yang menyebabkan pemrosesan dilakukan (jika dasar=Kepentingan Vital)',
                        'lb_lia' => 'string — dokumentasi Legitimate Interest Assessment (jika dasar=Legitimate Interest)',
                        'legal_basis_detail' => 'string — catatan tambahan dasar pemrosesan',
                        // Sprint E4 — risk trigger fields
                        'bantuan_ai' => 'HARUS dari daftar bantuan_ai',
                        // Sec 3 — Sub-Q jika bantuan_ai = Ya/Sebagian
                        'ai_teknologi' => 'string — nama teknologi AI yang digunakan (e.g. GPT-4, Random Forest)',
                        'ai_tujuan' => 'string — tujuan & penggunaan AI dalam pemrosesan',
                        'otomatis' => 'HARUS dari daftar otomatis',
                        'pemrofilan' => ['HARUS dari daftar pemrofilan'],
                        'teknologi_baru' => 'Ya | Tidak',
                    ],
                    'pengumpulan_data' => [
                        // Sumber Data — UU PDP 5 opsi multi-checkbox
                        'sumber_data_list' => ['array — pilih dari: Individu, Organisasi, Sumber Terbuka / Publik, Lembaga Pemerintahan, Lembaga Survey'],
                        'sumber_data' => 'string (legacy single-select; isi sama dengan opsi pertama dari sumber_data_list)',
                        'jumlah_subjek' => 'HARUS dari daftar jumlah_subjek',
                        'kategori_subjek' => ['array string'],
                        'jenis_data' => ['array string'],
                        'jenis_data_spesifik' => ['HARUS dari daftar jenis_data_spesifik'],
                        'jenis_data_umum' => ['HARUS dari daftar jenis_data_umum'],
                        'jenis_data_pii' => ['HARUS dari daftar jenis_data_pii'],
                    ],
                    'penggunaan_penyimpanan' => [
                        'pihak_pemroses' => 'string',
                        'kategori_pihak' => ['HARUS dari daftar kategori_pihak'],
                        'kategori_pihak_lainnya' => 'string — sebutkan jika kategori_pihak berisi "Lainnya"',
                        'cara_pemrosesan' => 'string',
                        'lokasi_penyimpanan' => 'string',
                        'pihak_ketiga' => 'Ya | Tidak',
                        'nama_pihak_ketiga' => 'string — fallback catatan jika tidak ada di TPRM',
                        'vendor_ids' => ['array UUID vendor TPRM (isi kosong; sistem auto-resolve dari nama)'],
                    ],
                    'pengiriman_data' => [
                        // Penerima Internal — split dari ada_penerima legacy
                        'penerima_internal' => 'Ya | Tidak',
                        'penerima_internal_divisi' => 'string — nama divisi internal',
                        'penerima_internal_pic' => 'string — nama PIC internal',
                        'penerima_internal_email' => 'string — email PIC internal',
                        'penerima_internal_telp' => 'string — telepon PIC internal',
                        // Penerima Eksternal — entitas di luar org
                        'penerima_eksternal' => 'Ya | Tidak',
                        'penerima_eksternal_org' => 'string — nama organisasi eksternal',
                        'penerima_eksternal_alamat' => 'string',
                        'penerima_eksternal_pic' => 'string',
                        'penerima_eksternal_email' => 'string',
                        'penerima_eksternal_telp' => 'string',
                        'penerima_eksternal_sistem' => 'string — sistem informasi yang digunakan',
                        // Legacy fields (back-compat)
                        'ada_penerima' => 'Ya | Tidak (legacy; same as penerima_internal OR penerima_eksternal)',
                        'penerima_data' => 'string (legacy)',
                        // Jenis data pribadi yang dikirimkan (mirror Section 4 categories)
                        'jenis_data_spesifik_kirim' => ['array — sub-set dari jenis_data_spesifik yang dikirim ke penerima'],
                        'jenis_data_umum_kirim' => ['array — sub-set dari jenis_data_umum'],
                        'jenis_data_pii_kirim' => ['array — sub-set dari jenis_data_pii'],
                        // Transfer LN
                        'transfer_luar' => 'Ya | Tidak',
                        'negara_tujuan' => 'string jika transfer_luar=Ya',
                        'transfer_basis' => ['array — pilih dari 3 opsi UU PDP: "Negara domisili memiliki tingkat pelindungan setara/lebih tinggi", "Pelindungan memadai dan mengikat (kontrak)", "Persetujuan Subjek Data"'],
                        'safeguards' => 'string — catatan tambahan',
                    ],
                    'retensi_keamanan' => [
                        'kontrol_keamanan' => ['HARUS dari daftar kontrol_keamanan, pilih yg relevan'],
                        // Sprint E3 — retention master data. AI can only suggest new policy
                        // definitions; the create flow will persist them.
                        'retensi_list' => [
                            [
                                'name' => 'string — label retensi (e.g. "Retensi Karyawan 5 tahun")',
                                'duration_value' => 'integer | null (null jika indefinite)',
                                'duration_unit' => 'day | month | year | indefinite',
                                'trigger_event' => 'string',
                                'disposal_method' => 'delete | anonymize | archive',
                            ],
                        ],
                        'masa_retensi' => 'string (legacy free-text, opsional)',
                        // Prosedur Pemusnahan — sub-Q
                        'ada_prosedur_pemusnahan' => 'Ya | Tidak',
                        'prosedur_pemusnahan' => 'string — deskripsi prosedur (jika ada_prosedur_pemusnahan=Ya)',
                        'pemusnahan_terakhir_at' => 'YYYY-MM-DD — tanggal terakhir pemusnahan dilakukan',
                        'berita_acara_pemusnahan' => 'Ya | Tidak — apakah ada berita acara penghapusan',
                        // Insiden Pelanggaran Data
                        'pernah_insiden' => '"Ya, pernah terjadi" | "Tidak pernah"',
                        'insiden_jelaskan' => 'string — jelaskan insiden jika pernah_insiden="Ya, pernah terjadi"',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Buatkan draft RoPA lengkap untuk aktivitas pemrosesan: \"{$activityName}\".\n"
            ."Isi SEMUA field wizard_data berdasarkan konteks organisasi.\n"
            ."PENTING: Untuk checkbox/radio/select, HANYA gunakan nilai PERSIS dari daftar opsi yang sudah diberikan.\n"
            ."Pilih minimal 2-3 jenis_pemrosesan, 3-5 jenis_data_umum, 2-4 jenis_data_pii, dan 3-5 kontrol_keamanan.\n"
            .'Jawab HANYA JSON valid.';

        return $this->ask($system, $user, 4000);
    }

    /**
     * DPIA Auto-Fill: Generate complete DPIA draft
     *
     * IMPORTANT: potensi_risiko keys MUST exactly match RISK_CATEGORIES,
     * answer values MUST be exactly: sudah | sebagian | belum | tidak_berlaku
     */
    public function dpiaAutoFill(string $description, string $tenantContext): ?array
    {
        $riskCategories = [
            'Dasar Hukum Pemrosesan', 'Pemrosesan Data Pribadi yang Sah',
            'Kesesuaian Tujuan Pemrosesan', 'Minimisasi Data', 'Keakuratan Data',
            'Pembatasan Penyimpanan', 'Integritas dan Kerahasiaan', 'Akuntabilitas',
            'Hak Subjek Data - Akses', 'Hak Subjek Data - Koreksi',
            'Hak Subjek Data - Hapus', 'Hak Subjek Data - Portabilitas',
            'Persetujuan dan Consent', 'Transfer Data Lintas Batas',
            'Enkripsi dan Pseudonymization', 'Kontrol Akses', 'Monitoring dan Logging',
            'Retensi Data', 'Manajemen Insiden', 'Pelatihan dan Kesadaran',
            'Penilaian Dampak Berkala',
        ];

        // Build example potensi_risiko with all 21 categories
        $potensiRisikoExample = [];
        foreach ($riskCategories as $cat) {
            $potensiRisikoExample[$cat] = [
                'answer' => 'sudah | sebagian | belum | tidak_berlaku (pilih 1)',
                'description' => 'string: penjelasan singkat kondisi saat ini (1-2 kalimat)',
            ];
        }

        $system = "Kamu adalah ahli penilaian risiko data pribadi UU PDP Indonesia.\n"
            ."Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
            ."KONTEKS ORGANISASI:\n{$tenantContext}\n\n"
            ."ATURAN PENTING — POTENSI RISIKO:\n"
            ."Field 'potensi_risiko' HARUS berisi SEMUA 21 kategori risiko berikut sebagai KEY:\n"
            .json_encode($riskCategories, JSON_UNESCAPED_UNICODE)."\n\n"
            ."Nilai 'answer' untuk setiap kategori HARUS PERSIS salah satu dari:\n"
            ."- \"sudah\" = Sudah Memenuhi\n"
            ."- \"sebagian\" = Memenuhi Sebagian\n"
            ."- \"belum\" = Belum Memenuhi\n"
            ."- \"tidak_berlaku\" = Tidak Berlaku\n\n"
            ."FORMAT OUTPUT:\n"
            .json_encode([
                'description' => 'string: deskripsi pemrosesan yang dinilai (2-3 kalimat)',
                'risk_level' => 'low | medium | high',
                'risk_assessment' => [
                    'likelihood' => 'integer 1-5',
                    'impact' => 'integer 1-5',
                ],
                'wizard_data' => [
                    'informasi_dpia' => [
                        'deskripsi_pemrosesan' => 'string: deskripsi pemrosesan',
                        'pic_name' => 'string: nama PIC (Person In Charge)',
                        'dpo_name' => 'string: nama DPO',
                    ],
                    'koneksi_ropa' => ['connected_ropas' => []],
                    'potensi_risiko' => '(object dengan 21 kategori sebagai KEY, lihat aturan di atas)',
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n\n"
            ."CONTOH potensi_risiko (isi SEMUA 21 kategori):\n"
            .json_encode(array_slice($potensiRisikoExample, 0, 3, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Buatkan draft DPIA lengkap untuk pemrosesan: \"{$description}\".\n"
            ."WAJIB isi SEMUA 21 kategori di potensi_risiko berdasarkan konteks organisasi.\n"
            ."Untuk setiap kategori, evaluasi apakah sudah/sebagian/belum/tidak_berlaku dan beri 'description' penjelasan singkat.\n"
            .'Jawab HANYA JSON valid.';

        return $this->ask($system, $user, 4000);
    }

    /**
     * Sprint F1: LIA analysis — evaluates purpose/necessity/balancing tests.
     */
    public function liaAnalysis(array $lia): ?array
    {
        $system = "Kamu adalah privacy lawyer spesialis Legitimate Interest Assessment UU PDP/GDPR.\n"
            ."Output WAJIB JSON valid.\n\n"
            .'FORMAT: '.json_encode([
                'overall_score' => 'integer 0-100',
                'assessment_result' => 'pass | conditional | fail',
                'purpose_evaluation' => 'string (2-3 kalimat)',
                'necessity_evaluation' => 'string',
                'balancing_evaluation' => 'string — evaluasi keseimbangan kepentingan vs hak subjek',
                'recommendations' => [['priority' => 'critical|high|medium', 'description' => '...']],
                'red_flags' => ['hal yang berisiko dari perspektif UU PDP'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "LIA record:\n".json_encode($lia, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ."\n\nAnalisis ke-3 test (Purpose, Necessity, Balancing). Jawab HANYA JSON valid.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * Sprint F2: TIA analysis — evaluates cross-border transfer risk.
     */
    public function tiaAnalysis(array $tia): ?array
    {
        $system = "Kamu adalah privacy lawyer internasional ahli cross-border data transfer.\n"
            ."Output WAJIB JSON valid.\n\n"
            .'FORMAT: '.json_encode([
                'overall_risk_level' => 'low | medium | high | critical',
                'jurisdiction_analysis' => 'string — status adequacy negara tujuan vs UU PDP',
                'recommended_safeguards' => ['SCCs', 'BCR', 'encryption', 'pseudonymization', 'contractual'],
                'risk_score' => 'integer 0-100 (higher = lebih berisiko)',
                'go_no_go' => 'proceed | proceed_with_conditions | stop',
                'conditions' => ['kalau proceed_with_conditions — syarat yang harus dipenuhi'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "TIA record:\n".json_encode($tia, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ."\n\nEvaluasi risiko transfer. Jawab HANYA JSON valid.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * Sprint X4: "Tanya AI" — free-form Q&A grounded in a specific
     * LIA / TIA / Maturity record. Returns a markdown-friendly answer
     * with citations to UU PDP articles. Distinct from {kind}Analysis
     * (which produces a structured JSON evaluation) — this endpoint
     * is conversational so an auditor can drill into specifics.
     */
    public function assessmentAskAi(string $kind, array $record, string $question): ?array
    {
        $kindLabel = match ($kind) {
            'lia' => 'Legitimate Interest Assessment',
            'tia' => 'Transfer Impact Assessment',
            'maturity' => 'Maturity Assessment',
            default => ucfirst($kind),
        };
        $system = "Kamu adalah privacy lawyer & data protection officer ahli UU PDP Indonesia (UU 27/2022).\n"
            ."Konteksnya: kamu sedang membantu auditor / DPO menganalisis sebuah {$kindLabel}.\n\n"
            ."Output WAJIB JSON valid dengan format:\n"
            .json_encode([
                'answer' => 'string — jawaban langsung & jelas (boleh markdown, bullet, dan tabel)',
                'citations' => [['article' => 'Pasal X UU PDP', 'note' => 'kenapa relevan dengan jawaban di atas']],
                'confidence' => 'high | medium | low',
                'follow_up_questions' => ['pertanyaan lanjutan yang relevan untuk auditor'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ."\n\nKalau pertanyaan di luar konteks record, tolak halus dan minta klarifikasi.";

        $user = "Record {$kindLabel}:\n"
            .json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ."\n\nPertanyaan auditor:\n".$question
            ."\n\nJawab HANYA JSON valid sesuai format.";

        return $this->ask($system, $user, 2000);
    }

    /**
     * Sprint F3: Maturity analysis — evaluate & recommend roadmap.
     */
    public function maturityAnalysis(array $maturity): ?array
    {
        $system = "Kamu adalah data protection maturity auditor (CMM model, 5 level).\n"
            ."Level: 1=Initial, 2=Repeatable, 3=Defined, 4=Managed, 5=Optimizing.\n"
            ."Output WAJIB JSON valid.\n\n"
            .'FORMAT: '.json_encode([
                'overall_level' => 'integer 1-5',
                'overall_score' => 'integer 0-100',
                'dimension_summary' => [
                    'governance' => ['level' => 1, 'summary' => '...'],
                    'process' => ['level' => 1, 'summary' => '...'],
                    'technology' => ['level' => 1, 'summary' => '...'],
                    'people' => ['level' => 1, 'summary' => '...'],
                    'compliance' => ['level' => 1, 'summary' => '...'],
                ],
                'roadmap' => [['priority' => 'critical|high|medium', 'dimension' => '...', 'next_level_from_current' => 'X → Y', 'actions' => ['...']]],
                'executive_summary' => 'string (3-4 kalimat eksekutif)',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Maturity record:\n".json_encode($maturity, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            ."\n\nBeri analisis maturity + roadmap. Jawab HANYA JSON valid.";

        return $this->ask($system, $user, 3000);
    }

    /**
     * Sprint D4: Dynamic containment steps generator tailored to a specific breach case.
     * Returns: { containment_steps: [{ order, step, critical, estimated_minutes }] }
     */
    public function breachContainmentSteps(array $breachData): ?array
    {
        $system = "Kamu adalah incident commander cyber security & UU PDP Indonesia.\n"
            ."Tugasmu merancang containment steps dinamis berdasarkan kasus breach spesifik — BUKAN template 10 langkah generic.\n"
            ."Output WAJIB JSON valid.\n\n"
            ."FORMAT OUTPUT:\n"
            .json_encode([
                'containment_steps' => [[
                    'order' => 1,
                    'step' => 'Nama langkah singkat (≤ 60 karakter)',
                    'description' => 'Detail teknis langkah ini (1-2 kalimat)',
                    'critical' => true,
                    'estimated_minutes' => 15,
                    'responsible_role' => 'DPO | IT Admin | Security | Legal | ...',
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $dataStr = json_encode($breachData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Kasus breach:\n{$dataStr}\n\n"
            .'Rancang 5-10 containment step SPESIFIK untuk kasus ini. '
            .'Urutkan berdasarkan prioritas. Fokus pada action yang realistis dan technical. '
            .'Jawab HANYA JSON valid.';

        return $this->ask($system, $user, 2500);
    }

    /**
     * Sprint D1: Contract compliance analyzer with comply/non-comply split + page hints.
     * pages = [{ page: 1, text: "..." }, ...] (from DocumentParserService)
     * Returns: { comply: [...], non_comply: [...], recommendations: [...], risk_score, summary }
     */
    public function contractComplianceAnalyzer(array $pages, string $contractType = 'other'): ?array
    {
        $pagesText = '';
        foreach ($pages as $p) {
            $pagesText .= "\n--- HALAMAN {$p['page']} ---\n".mb_substr($p['text'] ?? '', 0, 3500);
        }

        $system = "Kamu adalah Data Protection Officer ahli UU PDP Indonesia (UU No. 27/2022).\n"
            ."Tugasmu menganalisis klausul kontrak dan memisahkan mana yang comply vs non-comply terhadap UU PDP.\n"
            ."Output WAJIB JSON valid.\n\n"
            ."FORMAT OUTPUT:\n"
            .json_encode([
                'comply' => [[
                    'clause' => 'judul klausul yang ditemukan',
                    'page' => 1,
                    'description' => 'kenapa klausul ini sudah memenuhi UU PDP',
                    'uu_pdp_reference' => 'Pasal X UU PDP',
                ]],
                'non_comply' => [[
                    'clause' => 'judul klausul yang bermasalah',
                    'page' => 2,
                    'description' => 'apa yang kurang / melanggar',
                    'severity' => 'high|medium|low',
                    'uu_pdp_reference' => 'Pasal X UU PDP',
                ]],
                'recommendations' => [[
                    'priority' => 'critical|high|medium',
                    'description' => 'apa yang harus ditambahkan/diubah',
                    'reference_article' => 'Pasal X',
                ]],
                'missing_clauses' => ['klausul standar yang SEHARUSNYA ada tapi tidak ditemukan'],
                'risk_score' => '0-100 integer',
                'summary' => '2-3 kalimat eksekutif summary',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Tipe kontrak: {$contractType}\n\n"
            ."Halaman-halaman kontrak:\n{$pagesText}\n\n"
            ."Analisis setiap klausul. Untuk tiap temuan, sebutkan halaman-nya dari header '--- HALAMAN N ---'. "
            .'Jawab HANYA JSON valid.';

        return $this->ask($system, $user, 4000);
    }

    /**
     * Sprint D2: Policy / SOP review analyzer (mirror of contract, different lens).
     */
    public function policyComplianceAnalyzer(array $pages, string $policyType = 'sop'): ?array
    {
        $pagesText = '';
        foreach ($pages as $p) {
            $pagesText .= "\n--- HALAMAN {$p['page']} ---\n".mb_substr($p['text'] ?? '', 0, 3500);
        }

        $system = "Kamu adalah compliance auditor untuk perlindungan data pribadi UU PDP.\n"
            ."Tugasmu mengevaluasi SOP/Kebijakan Perusahaan apakah sudah selaras dengan UU PDP.\n"
            ."Output WAJIB JSON valid.\n\n"
            ."FORMAT OUTPUT (sama dengan contract analyzer):\n"
            .json_encode([
                'comply' => [['clause' => '...', 'page' => 1, 'description' => '...', 'uu_pdp_reference' => '...']],
                'non_comply' => [['clause' => '...', 'page' => 2, 'description' => '...', 'severity' => 'high|medium|low', 'uu_pdp_reference' => '...']],
                'recommendations' => [['priority' => 'critical|high|medium', 'description' => '...', 'reference_article' => '...']],
                'missing_clauses' => [],
                'risk_score' => 'integer 0-100',
                'summary' => '2-3 kalimat',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Tipe dokumen: {$policyType} (SOP / kebijakan perusahaan)\n\n"
            ."Isi dokumen:\n{$pagesText}\n\n"
            .'Analisis kepatuhan dokumen terhadap UU PDP. Jawab HANYA JSON valid.';

        return $this->ask($system, $user, 4000);
    }

    /**
     * Sprint C2: Suggest RACI matrix for a RoPA / DPIA record.
     * Returns: { raci: [{ task, responsible, accountable, consulted, informed }] }
     */
    public function raciSuggestion(string $module, array $recordData, array $userList): ?array
    {
        $userNames = array_map(fn ($u) => "- {$u['name']} ({$u['role']})", $userList);
        $userNameList = implode("\n", $userNames);

        $moduleLabel = match ($module) {
            'ropa' => 'Record of Processing Activity',
            'dpia' => 'Data Protection Impact Assessment',
            default => ucfirst($module),
        };

        $system = "Kamu adalah konsultan ahli UU PDP dan governance privasi data.\n"
            ."Tugasmu membuat RACI matrix untuk aktivitas pemrosesan data.\n"
            ."Output WAJIB JSON valid.\n\n"
            ."FORMAT OUTPUT:\n"
            .'{"raci":[{"task":"string","responsible":"nama user","accountable":"nama user","consulted":["nama user",...],"informed":["nama user",...]}]}'."\n\n"
            ."ATURAN:\n"
            ."- Gunakan HANYA nama user dari daftar yang disediakan di user prompt.\n"
            ."- Setiap task harus punya tepat 1 Responsible dan 1 Accountable.\n"
            ."- Consulted dan Informed boleh kosong [], atau berisi banyak nama.\n"
            ."- Generate 5-8 task paling penting untuk {$moduleLabel}.\n";

        $dataStr = json_encode($recordData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "MODULE: {$moduleLabel}\n\n"
            ."DAFTAR USER YANG TERSEDIA:\n{$userNameList}\n\n"
            ."DATA RECORD:\n{$dataStr}\n\n"
            .'Buatkan RACI matrix untuk aktivitas pemrosesan ini. Jawab HANYA JSON valid.';

        return $this->ask($system, $user, 2500);
    }

    /**
     * Breach Auto-Fill: Generate incident report draft
     */
    public function breachAutoFill(string $incidentTitle, string $tenantContext): ?array
    {
        $validChecklist = [
            'Isolasi sistem yang terdampak', 'Blokir akses yang tidak sah',
            'Preserve evidence (backup log)', 'Ubah credentials yang compromised',
            'Aktifkan firewall rules tambahan', 'Identifikasi root cause',
            'Hapus malware / tutup vulnerability', 'Patch sistem yang terdampak',
            'Restore data dari backup', 'Verifikasi integritas data',
        ];

        $system = "Kamu adalah ahli incident response dan breach management UU PDP.\n"
            ."Output WAJIB berupa JSON valid. JANGAN tambahkan teks di luar JSON.\n\n"
            ."KONTEKS ORGANISASI:\n{$tenantContext}\n\n"
            ."ATURAN PENTING:\n"
            ."— severity HARUS salah satu: low | medium | high | critical\n"
            ."— source HARUS salah satu: manual | automated | external_report | monitoring\n"
            ."— affected_subjects_count HARUS integer (angka bulat, bukan string)\n"
            ."— notification_required HARUS boolean true/false\n"
            ."— containment_checklist: gunakan PERSIS step dari daftar berikut:\n"
            .json_encode($validChecklist, JSON_UNESCAPED_UNICODE)."\n\n"
            ."FORMAT OUTPUT:\n"
            .json_encode([
                'title' => 'string',
                'description' => 'string (5-7 kalimat)',
                'severity' => 'low | medium | high | critical',
                'source' => 'manual | automated | external_report | monitoring',
                'affected_data_types' => ['array string: jenis data terdampak'],
                'affected_subjects_count' => 'integer',
                'root_cause' => 'string',
                'remediation_plan' => 'string',
                'notification_required' => 'boolean',
                'containment_checklist' => 'object {"step_name": true/false} dari daftar di atas',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Buatkan draft laporan insiden breach untuk: \"{$incidentTitle}\".\n"
            ."Sesuaikan severity, dampak, dan remediasi berdasarkan konteks organisasi.\n"
            ."Untuk containment_checklist, set step yang sudah seharusnya dilakukan pertama kali ke true.\n"
            .'Jawab HANYA JSON valid.';

        return $this->ask($system, $user, 3000);
    }

    /**
     * DSR Auto-Fill: Generate DSR handling draft
     */
    public function dsrAutoFill(string $requestType, string $requesterName, string $tenantContext): ?array
    {
        $system = "Kamu adalah DPO yang menangani Data Subject Request sesuai UU PDP.\n"
            ."Output WAJIB berupa JSON valid. JANGAN tambahkan teks di luar JSON.\n\n"
            ."KONTEKS ORGANISASI:\n{$tenantContext}\n\n"
            ."ATURAN PENTING:\n"
            ."— request_type HARUS PERSIS salah satu: access | rectification | erasure | portability | restriction | objection\n"
            ."— requester_email: jika tidak ada info, gunakan format nama@example.com\n\n"
            ."FORMAT OUTPUT:\n"
            .json_encode([
                'request_type' => 'access | rectification | erasure | portability | restriction | objection',
                'requester_name' => 'string',
                'requester_email' => 'string (email format)',
                'description' => 'string: deskripsi permintaan (2-3 kalimat profesional)',
                'verification_status' => 'pending',
                'response_draft' => 'string: draft surat balasan formal ke pemohon (minimal 3 paragraf)',
                'internal_checklist' => ['array string: langkah internal yang perlu dilakukan'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Buatkan draft penanganan DSR tipe \"{$requestType}\" dari pemohon \"{$requesterName}\".\n"
            ."Sertakan draft surat balasan formal dan checklist internal.\n"
            .'Jawab HANYA JSON valid.';

        return $this->ask($system, $user, 2500);
    }

    // =============================================
    // VENDOR RISK ASSESSMENT AI HUB
    // =============================================

    /**
     * 1. AI Vendor Auto-Form (Policy/DPA Extractor)
     */
    public function vendorExtractor(string $inputUrlOrText): ?array
    {
        $system = "Kamu adalah AI Auditor Spesialis Vendor Risk Management EU GDPR & UU PDP.\n"
                ."Tugasmu membaca URL, nama, atau cuplikan privacy policy vendor dan mengekstrak profil mereka.\n"
                ."Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
                ."FORMAT OUTPUT JSON:\n"
                .json_encode([
                    'name' => 'Nama entitas vendor / perusahaan',
                    'services_provided' => ['Layanan 1', 'Layanan 2'],
                    'data_shared' => ['Nama', 'Email', 'IP Address'],
                    'summary' => 'Rangkuman aktivitas pemrosesan vendor (2 kalimat)',
                ], JSON_PRETTY_PRINT);

        $user = "Ekstrak konteks privasi dari input vendor berikut:\n\n{$inputUrlOrText}\n\n"
              .'Buat profilnya HANYA dalam JSON format yang diminta.';

        return $this->ask($system, $user, 2000);
    }

    /**
     * 2. AI Dynamic Questionnaire Generator
     */
    public function vendorQuestionnaire(array $extractedData): ?array
    {
        $system = "Kamu adalah AI Risk Assessor Ahli ISO 27001.\n"
                ."Tugasmu membuat 4-5 pertanyaan kuesioner kritis berdasarkan profil vendor yang diberikan.\n"
                ."Output WAJIB berupa JSON valid.\n\n"
                ."FORMAT OUTPUT JSON:\n"
                .json_encode([
                    'questions' => [
                        ['id' => 'q1', 'text' => 'Pertanyaan 1', 'type' => 'boolean|text'],
                    ],
                ], JSON_PRETTY_PRINT);

        $dataStr = json_encode($extractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Buat kuesioner security audit spesifik untuk vendor ini:\n{$dataStr}\n\n"
              ."Fokus pada risiko perlindungan data sesuai UU PDP terkait layanan yang mereka tawarkan.\n"
              .'Keluarkan HANYA output JSON valid.';

        return $this->ask($system, $user, 2000);
    }

    /**
     * 3. AI Vendor Risk Assessor
     */
    public function vendorRiskAssessor(array $questionsAndAnswers): ?array
    {
        $system = "Kamu adalah AI Lead Auditor.\n"
                ."Tugasmu mengevaluasi risiko pihak ketiga berdasarkan jawaban kuesioner.\n"
                ."Output WAJIB berupa JSON valid.\n\n"
                ."FORMAT OUTPUT JSON:\n"
                .json_encode([
                    'score' => 'Integer antara 0 sampai 100. (100 = Sangat Aman, 0 = Berbahaya)',
                    'risk_level' => 'low|medium|high|critical',
                    'red_flags' => ['Daftar poin bahaya yang ditemukan'],
                    'recommendations' => ['Daftar mitigasi klausul kontrak/SCCs'],
                ], JSON_PRETTY_PRINT);

        $dataStr = json_encode($questionsAndAnswers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Berikan penilaian objektif atas jawaban kuesioner vendor ini:\n{$dataStr}\n\n"
              ."Hitung score dan berikan red flags jika ada kelalaian kontrol keamanan atau transfer tanpa SCC.\n"
              .'Keluarkan HANYA output JSON valid.';

        return $this->ask($system, $user, 2500);
    }

    /**
     * 4. AI TIA (Transfer Impact Assessment) untuk Cross-Border
     */
    public function vendorTia(array $vendorData, string $destinationCountry): ?array
    {
        $system = "Kamu adalah Privacy Lawyer Internasional ahli Adequacy Decision.\n"
                ."Tugasmu menilai risiko yurisdiksi negara tujuan dalam Cross-Border Data Transfer.\n"
                ."Output WAJIB berupa JSON valid.\n\n"
                ."FORMAT OUTPUT JSON:\n"
                .json_encode([
                    'tia_score' => 'Integer 0 sampai 100',
                    'legal_basis_recommended' => 'SCCs | Binding Corporate Rules | Adequacy Decision',
                    'safeguard_recommendations' => ['Technical safeguard', 'Organizational safeguard'],
                ], JSON_PRETTY_PRINT);

        $dataStr = json_encode($vendorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Lakukan Transfer Impact Assessment (TIA) untuk mentransfer data ke negara: {$destinationCountry}.\n"
              ."Profil vendor: {$dataStr}\n\n"
              ."Bandingkan regulasi negara tersebut dengan standar UU PDP/GDPR dan rekomendasikan legal safeguards.\n"
              .'Keluarkan HANYA output JSON valid.';

        return $this->ask($system, $user, 2500);
    }

    // =============================================
    // DATA DISCOVERY & DSR ENHANCEMENTS
    // =============================================

    /**
     * AI Deep Scan schema for PII classification and encryption recommendation
     */
    public function dataDiscoveryAiDeepScan(array $schema): ?array
    {
        $schemaCompact = json_encode($schema, JSON_UNESCAPED_UNICODE);
        $formatCompact = json_encode([
            'tables' => [[
                'name' => 'nama_tabel',
                'columns' => [[
                    'name' => 'nama_kolom',
                    'pii_detected' => true,
                    'pdp_category' => 'umum',
                    'classification' => 'pii',
                    'encryption_required' => true,
                    'ai_recommendation' => 'Rekomendasi proteksi singkat',
                ]],
            ]],
            'global_recommendation' => 'Rangkuman 1-2 kalimat',
        ], JSON_UNESCAPED_UNICODE);

        $system = "Kamu adalah Auditor Data Privacy & Cybersecurity spesialis UU PDP Indonesia.\n"
                ."Tugasmu menganalisis skema database dan mengklasifikasikan PII per kolom.\n\n"
                ."ATURAN OUTPUT MUTLAK:\n"
                ."- Output HANYA JSON valid satu objek tunggal. TIDAK ADA teks sebelum/sesudah.\n"
                ."- JANGAN gunakan markdown code fences (tidak ada ```).\n"
                ."- JANGAN tulis kata pengantar (\"Berikut\", \"Tentu\", dst) atau catatan setelah JSON.\n"
                ."- Nilai field `pdp_category` hanya boleh: 'umum' atau 'spesifik'.\n"
                ."- Nilai field `classification` hanya boleh: 'pii' atau 'sensitive'.\n"
                ."- Field `pii_detected` dan `encryption_required` boolean true/false.\n\n"
                ."Struktur output:\n{$formatCompact}";

        $user = "Skema (compact JSON):\n{$schemaCompact}\n\n"
              ."Instruksi:\n"
              ."1. Kembalikan HANYA tabel dan kolom yang MENGANDUNG data pribadi (PII/PDP).\n"
              ."2. Skip kolom non-PII (id, created_at, status, foreign keys, dll).\n"
              ."3. Skip seluruh tabel jika tidak ada kolom PII di dalamnya.\n"
              ."4. Beri rekomendasi proteksi singkat (1 kalimat) per kolom PII.\n"
              .'5. Mulai response langsung dengan karakter `{` — JANGAN ada apapun sebelumnya.';

        return $this->ask($system, $user, 4000);
    }

    /**
     * Text-to-SQL Agent: Generate queries to find subject data
     */
    public function generateSqlFromText(array $schema, string $prompt, string $dialect = 'mysql'): ?array
    {
        $system = "Kamu adalah Database Administrator Ahli (Dialect: {$dialect}).\n"
                ."Tugasmu adalah menghasilkan query SQL READ-ONLY murni untuk mencari entitas spesifik yang diminta user berdasarkan skema.\n"
                ."Output WAJIB berupa JSON valid.\n\n"
                ."FORMAT OUTPUT JSON:\n"
                .json_encode([
                    'sql_queries' => [
                        "SELECT * FROM table1 WHERE col1 LIKE '%keyword%';",
                        "SELECT a.* FROM table2 a JOIN table3 b ON a.fk = b.id WHERE b.col2 = 'exact';",
                    ],
                ], JSON_PRETTY_PRINT);

        $schemaStr = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Skema Database (hanya relasi dan kolom kunci):\n{$schemaStr}\n\n"
              ."Instruksi pencarian DSR: \"{$prompt}\"\n\n"
              ."Hasilkan maksimal 3 query SQL yang paling optimal untuk menemukan data tersebut.\n"
              ."PENTING: Hanya gunakan SELECT. JANGAN gunakan UPDATE, DELETE, INSERT, DROP.\n"
              .'Keluarkan HANYA output JSON valid.';

        return $this->ask($system, $user, 1500);
    }

    // Removed: analyzeRawSubjectData — previously sent raw database rows to the
    // LLM for "insight analysis" on the Text-to-SQL flow. By policy, the AI
    // provider must never receive actual data rows. SQL generation uses schema
    // metadata only; execution is user-triggered and stays inside the backend.

    /**
     * Leak-detection step 1: given a list of columns the user suspects were
     * leaked (e.g. from a dark-web dump header), find which scanned table in
     * this system matches. The AI only sees column/table NAMES — no data,
     * no sample values. Returns ranked candidates with confidence.
     */
    public function matchLeakedSchema(array $schema, array $leakedColumns, ?string $tableHint = null, string $dialect = 'mysql'): ?array
    {
        $system = "Kamu adalah Database Forensic Analyst.\n"
                ."Tugasmu membandingkan daftar kolom yang diduga bocor dengan skema database terdaftar.\n"
                ."Output WAJIB berupa JSON valid.\n\n"
                ."FORMAT OUTPUT JSON:\n"
                .json_encode([
                    'matches' => [
                        [
                            'table' => 'users_profile',
                            'confidence' => 0.92,
                            'matching_columns' => ['email', 'nik', 'name'],
                            'missing_columns' => ['phone'],
                            'reason' => 'Penjelasan singkat kenapa match.',
                        ],
                    ],
                ], JSON_PRETTY_PRINT);

        $schemaStr = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $leakedStr = json_encode($leakedColumns, JSON_UNESCAPED_UNICODE);
        $hintStr = $tableHint ? "User memberi petunjuk nama tabel: \"{$tableHint}\".\n\n" : '';

        $user = "Dialect: {$dialect}\n\n"
              ."Skema database yang sudah di-scan (nama tabel + daftar kolom, TIDAK ada nilai):\n{$schemaStr}\n\n"
              .$hintStr
              ."Urutan kolom yang diduga bocor dari sumber eksternal:\n{$leakedStr}\n\n"
              ."Cari maksimal 5 tabel di skema yang paling cocok. Kriteria penilaian:\n"
              ."1. EXACT MATCH ONLY: Prioritaskan tabel dengan nama kolom yang PERSIS SAMA (100% exact literal match) dengan daftar kolom bocor.\n"
              ."2. PENALTI KEMIRIPAN: Jika nama kolom hanya mirip (contoh: 'users' vs 'user', 'emails' vs 'email'), turunkan nilai confidence secara drastis (maksimal 0.40).\n"
              ."3. Confidence hanya boleh melebihi 0.80 jika sebagian besar kolom adalah EXACT MATCH tanpa tambahan atau pengurangan karakter apapun.\n"
              ."4. Urutan kolom yang cocok (exact match) menambah skor confidence.\n"
              ."5. Range Confidence 0–1. Berikan skor rendah (<= 0.40) jika kalkulasi didominasi oleh sekadar kemiripan kata.\n\n"
              .'Keluarkan HANYA JSON valid.';

        return $this->ask($system, $user, 1500);
    }
}
