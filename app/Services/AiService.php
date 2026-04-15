<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\KnowledgeBaseSection;
use App\Http\Controllers\Api\AiProviderController;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

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
     * @param string|null $orgId  Tenant org_id UUID (null = use legacy DeepSeek)
     * @param string $mode     'chat' or 'agent'
     */
    public function __construct(?string $orgId = null, string $mode = 'chat')
    {
        // Try multi-provider config first
        $config = AiProviderController::getActiveConfig($orgId, $mode);

        if ($config && !empty($config['api_key'])) {
            $this->apiKey = $config['api_key'];
            $this->model = $config['model']->model_id ?? '';
            $this->baseUrl = rtrim($config['base_url'] ?? '', '/');
            $this->authHeader = $config['auth_header'] ?: 'Authorization';
            $this->authPrefix = ($config['auth_header'] && !($config['auth_prefix'] ?? '')) ? '' : ($config['auth_prefix'] ?: 'Bearer');
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
        return !empty($this->apiKey);
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
        if (!$this->isAvailable()) {
            return null;
        }

        // Generate a unique cache key based on the model, prompts and language
        $cacheKey = 'ai_resp_' . md5($this->model . $systemPrompt . $userPrompt . $this->locale);

        // Check if we have a cached response (TTL 24 hours)
        if ($cached = Cache::get($cacheKey)) {
            Log::info("AI Cache Hit for model [{$this->model}]");
            return $cached;
        }

        try {
            $headers = ['Content-Type' => 'application/json'];
            if ($this->authPrefix) {
                $headers[$this->authHeader] = $this->authPrefix . ' ' . $this->apiKey;
            } else {
                $headers[$this->authHeader] = $this->apiKey;
            }

            $timeout = (int) env('AI_TIMEOUT', 180);
            
            $response = Http::timeout($timeout)
                ->withoutVerifying()
                ->withHeaders($headers)
                ->post($this->baseUrl . '/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->getLanguageDirective() . $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => $maxTokens,
                ]);

            if ($response->failed()) {
                Log::error('AI Provider API error [' . $this->model . ']: ' . $response->body());
                return null;
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            // Extract JSON block aggressively
            $cleaned = trim($content);
            if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
                $cleaned = $matches[0];
            } elseif (preg_match('/\[.*\]/s', $cleaned, $matches)) {
                $cleaned = $matches[0];
            }

            $parsed = json_decode($cleaned, true);
            $result = $parsed !== null ? $parsed : ['raw' => $content];

            // Store in cache for 24 hours
            Cache::put($cacheKey, $result, now()->addHours(24));

            return $result;
        } catch (\Exception $e) {
            Log::error('AI Service error [' . $this->model . ']: ' . $e->getMessage());
            return null;
        }
    }

    // =============================================
    // DOMAIN-SPECIFIC AI METHODS
    // =============================================

    /**
     * GAP Assessment: Generate AI remediation plan
     */
    public function gapRemediationPlan(array $recommendations, float $score, string $level): ?array
    {
        $recText = collect($recommendations)->map(fn($r) =>
            "- [{$r['priority']}] {$r['question']} (Pasal: {$r['article']}) → {$r['recommendation']}"
        )->implode("\n");

        $system = "Kamu adalah konsultan kepatuhan UU PDP Indonesia ahli. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info\",\"title\":\"...\",\"content\":\"...\",\"items\":[]}],\"closing\":\"...\"}";

        $user = "Organisasi ini mendapat skor GAP Assessment: {$score}% (level: {$level}).\n\n"
            . "Berikut temuan yang perlu diperbaiki:\n{$recText}\n\n"
            . "Buatkan AI Remediation Plan yang mencakup:\n"
            . "1. Executive summary kondisi saat ini\n"
            . "2. Prioritas tindakan (critical/high/medium) dengan timeline estimasi\n"
            . "3. Quick wins yang bisa dilakukan minggu ini\n"
            . "4. Roadmap 3-6-12 bulan\n"
            . "5. Tips implementasi\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 3000);
    }

    /**
     * ROPA: AI Risk Assessment & Legal Basis Advisor
     */
    public function ropaAnalysis(array $ropaData): ?array
    {
        $system = "Kamu adalah DPO (Data Protection Officer) ahli UU PDP Indonesia. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info\",\"title\":\"...\",\"content\":\"...\",\"items\":[]}],\"closing\":\"...\"}";

        $dataStr = json_encode($ropaData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Analisis ROPA ini dan berikan:\n"
            . "1. Risk assessment: evaluasi risk level berdasarkan data categories, recipients, retention\n"
            . "2. Legal basis advisor: rekomendasi dasar hukum yang tepat berdasarkan tujuan pemrosesan\n"
            . "3. Identifikasi jika ada data pribadi spesifik yang memerlukan DPIA\n"
            . "4. Rekomendasi security measures yang sesuai\n"
            . "5. Warning jika ada potensi pelanggaran UU PDP\n\n"
            . "Data ROPA:\n{$dataStr}\n\nJawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * DPIA: AI Risk Scoring per category
     */
    public function dpiaRiskScoring(array $dpiaData, array $riskAssessment): ?array
    {
        $system = "Kamu adalah ahli penilaian risiko data pribadi. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info\",\"title\":\"...\",\"content\":\"...\",\"items\":[]}],\"closing\":\"...\"}";

        $dataStr = json_encode(['dpia' => $dpiaData, 'risks' => $riskAssessment], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Analisis DPIA dan risk assessment ini:\n{$dataStr}\n\n"
            . "Berikan:\n"
            . "1. Risk scoring per kategori (likelihood × impact)\n"
            . "2. Top 5 risiko tertinggi yang perlu mitigasi segera\n"
            . "3. Langkah mitigasi spesifik per risiko\n"
            . "4. Rekomendasi apakah pemrosesan boleh dilanjutkan atau harus dimodifikasi\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * Breach: AI Severity Assessment & Containment
     */
    public function breachAdvisor(array $breachData): ?array
    {
        $system = "Kamu adalah ahli incident response dan breach management UU PDP. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info\",\"title\":\"...\",\"content\":\"...\",\"items\":[]}],\"closing\":\"...\"}";

        $dataStr = json_encode($breachData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Insiden data breach berikut baru dilaporkan:\n{$dataStr}\n\n"
            . "Berikan analisis AI:\n"
            . "1. Severity assessment (low/medium/high/critical) dengan justifikasi\n"
            . "2. Immediate containment steps (prioritized, 5-7 langkah)\n"
            . "3. Apakah notifikasi ke KOMDIGI wajib? (3×24 jam sesuai Pasal 46 UU PDP)\n"
            . "4. Template notifikasi ke subjek data (jika wajib)\n"
            . "5. Rekomendasi root cause investigation\n"
            . "6. Warning tentang deadline dan konsekuensi hukum\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 3000);
    }

    /**
     * DSR: AI Response Drafter
     */
    public function dsrResponseDraft(array $dsrData): ?array
    {
        $system = "Kamu adalah DPO yang menangani Data Subject Request sesuai UU PDP. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info\",\"title\":\"...\",\"content\":\"...\",\"items\":[]}],\"closing\":\"...\"}";

        $dataStr = json_encode($dsrData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Data Subject Request berikut perlu direspons:\n{$dataStr}\n\n"
            . "Berikan:\n"
            . "1. Verifikasi identitas: checklist item yang perlu diverifikasi\n"
            . "2. Draft respons formal ke pemohon (bahasa resmi, profesional)\n"
            . "3. Langkah-langkah internal yang perlu dilakukan\n"
            . "4. Timeline compliance sesuai UU PDP\n"
            . "5. Warning jika ada risiko atau pertimbangan khusus\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * Consent: AI Consent Text Generator
     */
    public function consentTextGenerator(string $purpose, array $dataTypes, string $domain): ?array
    {
        $system = "Kamu adalah privacy lawyer Indonesia ahli drafted consent text UU PDP. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info\",\"title\":\"...\",\"content\":\"...\",\"items\":[]}],\"closing\":\"...\"}";

        $user = "Generate teks consent yang comply UU PDP untuk:\n"
            . "- Tujuan: {$purpose}\n"
            . "- Jenis data: " . implode(', ', $dataTypes) . "\n"
            . "- Domain: {$domain}\n\n"
            . "Berikan:\n"
            . "1. Teks consent utama (bahasa Indonesia formal, mudah dipahami)\n"
            . "2. Detail per jenis data yang dikumpulkan\n"
            . "3. Informasi hak subjek data\n"
            . "4. Informasi kontak DPO\n"
            . "5. Tips: apa yang HARUS dan TIDAK BOLEH ada dalam consent\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * Consent: AI Consent Items Generator (Autofill Array)
     */
    public function consentItemsGenerator(string $tenantContext, string $pointName, string $pointDomain, array $existingItems = []): ?array
    {
        $system = "Kamu adalah privacy lawyer Indonesia ahli UU PDP. Output WAJIB JSON valid.\n"
            . "Format: {\"items\":[{\"title\":\"...\",\"description\":\"...\",\"full_text\":\"...\",\"is_required\":true/false}]}\n"
            . "Buat sekumpulan item persetujuan (consent items) yang relevan untuk collection point ini.\n"
            . "KONTEKS ORGANISASI:\n{$tenantContext}\n";

        if (!empty($existingItems)) {
            $system .= "PERINGATAN PENTING: Berikut adalah item yang SUDAH ADA. JANGAN MEMBUAT item yang mirip, sama, atau berduplikat dengan ini: " . implode(', ', $existingItems) . "\n";
        }

        $user = "Collection Point: {$pointName}\nDomain: {$pointDomain}\n\n"
            . "Berikan 3-6 consent items BARU yang penting (misal: Analytical Cookies, Marketing, Profiling, dsb).\n"
            . "Sesuaikan `is_required` dengan aturan (kalo essential/functional = true).\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * Dashboard: AI Compliance Summary
     */
    public function complianceSummary(array $stats): ?array
    {
        $system = "Kamu adalah konsultan kepatuhan UU PDP senior. Output WAJIB JSON valid.\n"
            . "Format: {\"greeting\":\"...\",\"sections\":[{\"type\":\"text|steps|list|tip|warning|info\",\"title\":\"...\",\"content\":\"...\",\"items\":[]}],\"closing\":\"...\"}";

        $dataStr = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Berikan executive compliance summary berdasarkan data dashboard ini:\n{$dataStr}\n\n"
            . "Berikan:\n"
            . "1. Ringkasan kondisi kepatuhan (2-3 kalimat bahasa manajemen/eksekutif)\n"
            . "2. Top 3 achievement\n"
            . "3. Top 3 area yang perlu perhatian segera\n"
            . "4. Rekomendasi quick wins minggu ini\n"
            . "5. Risk alert jika ada hal kritis\n"
            . "Jawab HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 2000);
    }

    /**
     * Fire Drill: AI Custom Scenario Generator
     */
    public function customDrillScenario(string $industry, string $riskProfile, int $questionCount = 5): ?array
    {
        $system = "Kamu adalah cybersecurity incident response trainer. Output WAJIB JSON valid.\n"
            . "Format output KHUSUS untuk drill scenario:\n"
            . "{\"title\":\"...\",\"description\":\"...\",\"briefing\":\"...\",\"questions\":[{\"id\":\"AI-Q1\",\"phase\":\"...\",\"question\":\"...\",\"time_limit\":60,\"type\":\"single\",\"options\":[{\"id\":\"a\",\"text\":\"...\",\"score\":0-10,\"feedback\":\"...\"}]}]}";

        $user = "Generate skenario fire drill breach simulation kustom:\n"
            . "- Industri: {$industry}\n"
            . "- Risk profile: {$riskProfile}\n"
            . "- Jumlah pertanyaan: {$questionCount}\n\n"
            . "Buat skenario realistis dengan:\n"
            . "1. Briefing situasi yang menegangkan\n"
            . "2. {$questionCount} pertanyaan multi-fase (Detection, Assessment, Containment, Notification, Recovery)\n"
            . "3. Setiap pertanyaan punya 4 pilihan jawaban dengan skor (0-10) dan feedback\n"
            . "4. Bahasa Indonesia\n"
            . "Output JSON mentah, BUKAN dalam format sections.";

        return $this->ask($system, $user, 4000);
    }

    // =============================================
    // AUTO-FILL METHODS (output maps directly to DB columns)
    // =============================================

    /**
     * ROPA Auto-Fill: Generate complete ROPA draft from activity name + tenant context
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

        $system = "Kamu adalah DPO (Data Protection Officer) ahli UU PDP Indonesia.\n"
            . "Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
            . "KONTEKS ORGANISASI:\n{$tenantContext}\n\n"
            . "ATURAN PENTING — NILAI CHECKBOX/RADIO/SELECT:\n"
            . "Setiap field checkbox, radio, atau select HARUS menggunakan nilai PERSIS dari daftar opsi berikut.\n"
            . "JANGAN buat nilai sendiri. HANYA pilih dari opsi yang tersedia.\n\n"
            . "— kategori_pemrosesan (pilih 1): " . json_encode($validKategoriPemrosesan) . "\n"
            . "— jenis_pemrosesan (pilih beberapa): " . json_encode($validJenisPemrosesan, JSON_UNESCAPED_UNICODE) . "\n"
            . "— dasar_pemrosesan (pilih 1): " . json_encode($validDasarPemrosesan, JSON_UNESCAPED_UNICODE) . "\n"
            . "— jumlah_subjek (pilih 1): " . json_encode($validJumlahSubjek, JSON_UNESCAPED_UNICODE) . "\n"
            . "— jenis_data_spesifik (pilih yg relevan): " . json_encode($validDataSpesifik, JSON_UNESCAPED_UNICODE) . "\n"
            . "— jenis_data_umum (pilih yg relevan): " . json_encode($validDataUmum, JSON_UNESCAPED_UNICODE) . "\n"
            . "— jenis_data_pii (pilih yg relevan): " . json_encode($validDataPii, JSON_UNESCAPED_UNICODE) . "\n"
            . "— kategori_pihak (pilih beberapa): " . json_encode($validKategoriPihak, JSON_UNESCAPED_UNICODE) . "\n"
            . "— kontrol_keamanan (pilih beberapa): " . json_encode($validKontrolKeamanan, JSON_UNESCAPED_UNICODE) . "\n"
            . "— pihak_ketiga, ada_penerima, transfer_luar (pilih 1): [\"Ya\", \"Tidak\"]\n\n"
            . "FORMAT OUTPUT:\n"
            . json_encode([
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
                        'dpo_name' => 'string',
                        'dpo_email' => 'string',
                        'dpo_phone' => 'string',
                    ],
                    'informasi_pemrosesan' => [
                        'tujuan' => 'string',
                        'penjelasan' => 'string',
                        'jenis_pemrosesan' => ['HARUS dari daftar jenis_pemrosesan, pilih yg relevan'],
                        'dasar_pemrosesan' => 'HARUS dari daftar dasar_pemrosesan',
                        'sistem_terkait' => ['array: nama sistem IT terkait'],
                    ],
                    'pengumpulan_data' => [
                        'sumber_data' => 'string',
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
                        'cara_pemrosesan' => 'string',
                        'lokasi_penyimpanan' => 'string',
                        'pihak_ketiga' => 'Ya | Tidak',
                        'nama_pihak_ketiga' => 'string jika pihak_ketiga=Ya',
                    ],
                    'pengiriman_data' => [
                        'ada_penerima' => 'Ya | Tidak',
                        'penerima_data' => 'string',
                        'transfer_luar' => 'Ya | Tidak',
                        'negara_tujuan' => 'string jika transfer_luar=Ya',
                        'safeguards' => 'string',
                    ],
                    'retensi_keamanan' => [
                        'kontrol_keamanan' => ['HARUS dari daftar kontrol_keamanan, pilih yg relevan'],
                        'masa_retensi' => 'string',
                        'prosedur_pemusnahan' => 'string',
                        'pernah_insiden' => 'Ya, pernah terjadi | Tidak pernah',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Buatkan draft ROPA lengkap untuk aktivitas pemrosesan: \"{$activityName}\".\n"
            . "Isi SEMUA field wizard_data berdasarkan konteks organisasi.\n"
            . "PENTING: Untuk checkbox/radio/select, HANYA gunakan nilai PERSIS dari daftar opsi yang sudah diberikan.\n"
            . "Pilih minimal 2-3 jenis_pemrosesan, 3-5 jenis_data_umum, 2-4 jenis_data_pii, dan 3-5 kontrol_keamanan.\n"
            . "Jawab HANYA JSON valid.";

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
            . "Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
            . "KONTEKS ORGANISASI:\n{$tenantContext}\n\n"
            . "ATURAN PENTING — POTENSI RISIKO:\n"
            . "Field 'potensi_risiko' HARUS berisi SEMUA 21 kategori risiko berikut sebagai KEY:\n"
            . json_encode($riskCategories, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "Nilai 'answer' untuk setiap kategori HARUS PERSIS salah satu dari:\n"
            . "- \"sudah\" = Sudah Memenuhi\n"
            . "- \"sebagian\" = Memenuhi Sebagian\n"
            . "- \"belum\" = Belum Memenuhi\n"
            . "- \"tidak_berlaku\" = Tidak Berlaku\n\n"
            . "FORMAT OUTPUT:\n"
            . json_encode([
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
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n"
            . "CONTOH potensi_risiko (isi SEMUA 21 kategori):\n"
            . json_encode(array_slice($potensiRisikoExample, 0, 3, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Buatkan draft DPIA lengkap untuk pemrosesan: \"{$description}\".\n"
            . "WAJIB isi SEMUA 21 kategori di potensi_risiko berdasarkan konteks organisasi.\n"
            . "Untuk setiap kategori, evaluasi apakah sudah/sebagian/belum/tidak_berlaku dan beri 'description' penjelasan singkat.\n"
            . "Jawab HANYA JSON valid.";

        return $this->ask($system, $user, 4000);
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
            . "Output WAJIB berupa JSON valid. JANGAN tambahkan teks di luar JSON.\n\n"
            . "KONTEKS ORGANISASI:\n{$tenantContext}\n\n"
            . "ATURAN PENTING:\n"
            . "— severity HARUS salah satu: low | medium | high | critical\n"
            . "— source HARUS salah satu: manual | automated | external_report | monitoring\n"
            . "— affected_subjects_count HARUS integer (angka bulat, bukan string)\n"
            . "— notification_required HARUS boolean true/false\n"
            . "— containment_checklist: gunakan PERSIS step dari daftar berikut:\n"
            . json_encode($validChecklist, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "FORMAT OUTPUT:\n"
            . json_encode([
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
            . "Sesuaikan severity, dampak, dan remediasi berdasarkan konteks organisasi.\n"
            . "Untuk containment_checklist, set step yang sudah seharusnya dilakukan pertama kali ke true.\n"
            . "Jawab HANYA JSON valid.";

        return $this->ask($system, $user, 3000);
    }

    /**
     * DSR Auto-Fill: Generate DSR handling draft
     */
    public function dsrAutoFill(string $requestType, string $requesterName, string $tenantContext): ?array
    {
        $system = "Kamu adalah DPO yang menangani Data Subject Request sesuai UU PDP.\n"
            . "Output WAJIB berupa JSON valid. JANGAN tambahkan teks di luar JSON.\n\n"
            . "KONTEKS ORGANISASI:\n{$tenantContext}\n\n"
            . "ATURAN PENTING:\n"
            . "— request_type HARUS PERSIS salah satu: access | rectification | erasure | portability | restriction | objection\n"
            . "— requester_email: jika tidak ada info, gunakan format nama@example.com\n\n"
            . "FORMAT OUTPUT:\n"
            . json_encode([
                'request_type' => 'access | rectification | erasure | portability | restriction | objection',
                'requester_name' => 'string',
                'requester_email' => 'string (email format)',
                'description' => 'string: deskripsi permintaan (2-3 kalimat profesional)',
                'verification_status' => 'pending',
                'response_draft' => 'string: draft surat balasan formal ke pemohon (minimal 3 paragraf)',
                'internal_checklist' => ['array string: langkah internal yang perlu dilakukan'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $user = "Buatkan draft penanganan DSR tipe \"{$requestType}\" dari pemohon \"{$requesterName}\".\n"
            . "Sertakan draft surat balasan formal dan checklist internal.\n"
            . "Jawab HANYA JSON valid.";

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
                . "Tugasmu membaca URL, nama, atau cuplikan privacy policy vendor dan mengekstrak profil mereka.\n"
                . "Output WAJIB berupa JSON valid. JANGAN tambahkan teks apapun di luar JSON.\n\n"
                . "FORMAT OUTPUT JSON:\n"
                . json_encode([
                    'name' => 'Nama entitas vendor / perusahaan',
                    'services_provided' => ['Layanan 1', 'Layanan 2'],
                    'data_shared' => ['Nama', 'Email', 'IP Address'],
                    'summary' => 'Rangkuman aktivitas pemrosesan vendor (2 kalimat)'
                ], JSON_PRETTY_PRINT);

        $user = "Ekstrak konteks privasi dari input vendor berikut:\n\n{$inputUrlOrText}\n\n"
              . "Buat profilnya HANYA dalam JSON format yang diminta.";

        return $this->ask($system, $user, 2000);
    }

    /**
     * 2. AI Dynamic Questionnaire Generator
     */
    public function vendorQuestionnaire(array $extractedData): ?array
    {
        $system = "Kamu adalah AI Risk Assessor Ahli ISO 27001.\n"
                . "Tugasmu membuat 4-5 pertanyaan kuesioner kritis berdasarkan profil vendor yang diberikan.\n"
                . "Output WAJIB berupa JSON valid.\n\n"
                . "FORMAT OUTPUT JSON:\n"
                . json_encode([
                    'questions' => [
                        ['id' => 'q1', 'text' => 'Pertanyaan 1', 'type' => 'boolean|text']
                    ]
                ], JSON_PRETTY_PRINT);
        
        $dataStr = json_encode($extractedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Buat kuesioner security audit spesifik untuk vendor ini:\n{$dataStr}\n\n"
              . "Fokus pada risiko perlindungan data sesuai UU PDP terkait layanan yang mereka tawarkan.\n"
              . "Keluarkan HANYA output JSON valid.";

        return $this->ask($system, $user, 2000);
    }

    /**
     * 3. AI Vendor Risk Assessor
     */
    public function vendorRiskAssessor(array $questionsAndAnswers): ?array
    {
        $system = "Kamu adalah AI Lead Auditor.\n"
                . "Tugasmu mengevaluasi risiko pihak ketiga berdasarkan jawaban kuesioner.\n"
                . "Output WAJIB berupa JSON valid.\n\n"
                . "FORMAT OUTPUT JSON:\n"
                . json_encode([
                    'score' => 'Integer antara 0 sampai 100. (100 = Sangat Aman, 0 = Berbahaya)',
                    'risk_level' => 'low|medium|high|critical',
                    'red_flags' => ['Daftar poin bahaya yang ditemukan'],
                    'recommendations' => ['Daftar mitigasi klausul kontrak/SCCs']
                ], JSON_PRETTY_PRINT);

        $dataStr = json_encode($questionsAndAnswers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Berikan penilaian objektif atas jawaban kuesioner vendor ini:\n{$dataStr}\n\n"
              . "Hitung score dan berikan red flags jika ada kelalaian kontrol keamanan atau transfer tanpa SCC.\n"
              . "Keluarkan HANYA output JSON valid.";

        return $this->ask($system, $user, 2500);
    }

    /**
     * 4. AI TIA (Transfer Impact Assessment) untuk Cross-Border
     */
    public function vendorTia(array $vendorData, string $destinationCountry): ?array
    {
        $system = "Kamu adalah Privacy Lawyer Internasional ahli Adequacy Decision.\n"
                . "Tugasmu menilai risiko yurisdiksi negara tujuan dalam Cross-Border Data Transfer.\n"
                . "Output WAJIB berupa JSON valid.\n\n"
                . "FORMAT OUTPUT JSON:\n"
                . json_encode([
                    'tia_score' => 'Integer 0 sampai 100',
                    'legal_basis_recommended' => 'SCCs | Binding Corporate Rules | Adequacy Decision',
                    'safeguard_recommendations' => ['Technical safeguard', 'Organizational safeguard']
                ], JSON_PRETTY_PRINT);

        $dataStr = json_encode($vendorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Lakukan Transfer Impact Assessment (TIA) untuk mentransfer data ke negara: {$destinationCountry}.\n"
              . "Profil vendor: {$dataStr}\n\n"
              . "Bandingkan regulasi negara tersebut dengan standar UU PDP/GDPR dan rekomendasikan legal safeguards.\n"
              . "Keluarkan HANYA output JSON valid.";

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
        $system = "Kamu adalah Auditor Data Privacy & Cybersecurity spesialis UU PDP Indonesia.\n"
                . "Tugasmu menganalisis skema database dan mengklasifikasikan PII untuk tiap kolom.\n"
                . "Output WAJIB berupa JSON valid.\n\n"
                . "FORMAT OUTPUT JSON:\n"
                . json_encode([
                    'tables' => [
                        [
                            'name' => 'nama_tabel',
                            'columns' => [
                                [
                                    'name' => 'nama_kolom',
                                    'pii_detected' => true,
                                    'pdp_category' => 'umum | spesifik',
                                    'classification' => 'pii | sensitive',
                                    'encryption_required' => true,
                                    'ai_recommendation' => 'Rekomendasi proteksi spesifik'
                                ]
                            ]
                        ]
                    ],
                    'global_recommendation' => 'Rangkuman risiko database secara keseluruhan (2 kalimat)'
                ], JSON_PRETTY_PRINT);

        $dataStr = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Analisis skema tabel berikut:\n{$dataStr}\n\n"
              . "PENTING: HANYA kembalikan tabel dan kolom yang MENGANDUNG data pribadi (PII/PDP). \n"
              . "TIDAK PERLU mengembalikan kolom seperti id, created_at, status, fk_id, dll yang bukan PII.\n"
              . "Jika suatu tabel tidak memiliki kolom PII satupun, JANGAN masukkan tabel tersebut ke dalam JSON response.\n"
              . "Berikan juga rekomendasi proteksi ditiap kolom PII tersebut.\n"
              . "Keluarkan HANYA output JSON valid.";

        return $this->ask($system, $user, 4000); // Increased back to 4000 to prevent JSON truncation
    }

    /**
     * Text-to-SQL Agent: Generate queries to find subject data
     */
    public function generateSqlFromText(array $schema, string $prompt, string $dialect = 'mysql'): ?array
    {
        $system = "Kamu adalah Database Administrator Ahli (Dialect: {$dialect}).\n"
                . "Tugasmu adalah menghasilkan query SQL READ-ONLY murni untuk mencari entitas spesifik yang diminta user berdasarkan skema.\n"
                . "Output WAJIB berupa JSON valid.\n\n"
                . "FORMAT OUTPUT JSON:\n"
                . json_encode([
                    'sql_queries' => [
                        "SELECT * FROM table1 WHERE col1 LIKE '%keyword%';",
                        "SELECT a.* FROM table2 a JOIN table3 b ON a.fk = b.id WHERE b.col2 = 'exact';"
                    ]
                ], JSON_PRETTY_PRINT);

        $schemaStr = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $user = "Skema Database (hanya relasi dan kolom kunci):\n{$schemaStr}\n\n"
              . "Instruksi pencarian DSR: \"{$prompt}\"\n\n"
              . "Hasilkan maksimal 3 query SQL yang paling optimal untuk menemukan data tersebut.\n"
              . "PENTING: Hanya gunakan SELECT. JANGAN gunakan UPDATE, DELETE, INSERT, DROP.\n"
              . "Keluarkan HANYA output JSON valid.";

        return $this->ask($system, $user, 1500);
    }

    /**
     * Analyze RAW Database response from Text-to-SQL execution
     */
    public function analyzeRawSubjectData(array $rawData, string $originalPrompt): ?array
    {
        $system = "Kamu adalah Data Protection Officer Ahli UU PDP.\n"
                . "Tugasmu menganalisa raw data yang ditemukan dari database terkait Data Subject Request (DSR).\n"
                . "Output WAJIB berupa JSON valid.\n\n"
                . "FORMAT OUTPUT JSON:\n"
                . json_encode([
                    'subject_found' => true,
                    'insight' => 'Rangkuman analisa terhadap data yang ditemukan, sebutkan sensitivitas data (max 3 kalimat)',
                    'tables_found' => ['tabel1', 'tabel2'],
                    'recommendation' => 'Rekomendasi redaction/masking sebelum data dikirim ke subject (max 2 kalimat)'
                ], JSON_PRETTY_PRINT);

        // Limit the raw data size to prevent hitting context limits
        $dataStr = json_encode(array_slice($rawData, 0, 50), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        $user = "Tujuan Pencarian: \"{$originalPrompt}\"\n\n"
              . "Hasil Eksekusi Raw SQL (Dibatasi 50 row per tabel untuk keamanan):\n{$dataStr}\n\n"
              . "Berdasarkan record tersebut, buatkan insight risiko (terutama jika ada data spesifik) dan rekomendasi redaction.\n"
              . "Keluarkan HANYA output JSON valid.";

        return $this->ask($system, $user, 2000);
    }
}
