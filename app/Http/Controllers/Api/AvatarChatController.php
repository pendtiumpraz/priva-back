<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Services\AiContentSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Avatar Chat Controller
 *
 * Dedicated endpoint for the 3D Avatar (Priva) chat.
 * - Uses 'avatar' mode from AI Provider
 * - Has a strict system prompt: platform Q&A only, no AI system discussion
 * - Reads platform knowledge base for context-aware answers
 */
class AvatarChatController extends Controller
{
    /**
     * License gate (P0 fix dari audit AI security).
     */
    private function checkAiChatLicense(Request $request): bool
    {
        $user = $request->user();
        if (! $user || ! $user->org_id || in_array($user->role, ['root', 'superadmin'], true)) {
            return true;
        }

        $license = License::where('org_id', $user->org_id)
            ->where('status', 'active')
            ->first();

        return $license && $license->package_type !== 'basic';
    }

    /**
     * Chat with the avatar
     */
    public function chat(Request $request)
    {
        // P0: License gate
        if (! $this->checkAiChatLicense($request)) {
            return response()->json([
                'reply' => 'Fitur Avatar AI hanya tersedia untuk paket Pro AI dan Enterprise.',
                'upgrade_required' => true,
                'error' => true,
            ], 403);
        }

        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
        ]);

        $user = $request->user();
        $orgId = $user->org_id;

        // Get avatar AI provider config, fallback to chat
        $providerConfig = AiProviderController::getActiveConfig($orgId, 'avatar');
        if (! $providerConfig) {
            $providerConfig = AiProviderController::getActiveConfig($orgId, 'chat');
        }
        if (! $providerConfig) {
            $providerConfig = AiProviderController::getActiveConfig($orgId, 'agent');
        }

        if (! $providerConfig || empty($providerConfig['api_key'])) {
            return response()->json([
                'reply' => 'Maaf, AI belum dikonfigurasi. Silakan set Avatar AI Provider di menu Settings > AI Providers terlebih dahulu.',
                'error' => true,
            ]);
        }

        $userMessage = $request->message;
        $history = $request->history ?? [];

        // Search knowledge base for relevant context
        $relevantDocs = $this->searchKnowledgeBase($userMessage);

        // Build system prompt
        $systemPrompt = $this->buildSystemPrompt($user, $relevantDocs);

        // Build messages array
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add conversation history (max 10 messages)
        foreach (array_slice($history, -10) as $msg) {
            $messages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ];
        }

        // Add current message
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // Call AI
        $apiKey = $providerConfig['api_key'];
        $model = $providerConfig['model']->model_id;
        $baseUrl = rtrim($providerConfig['base_url'], '/');
        $authHeader = $providerConfig['auth_header'] ?: 'Authorization';
        $authPrefix = ($providerConfig['auth_header'] && ! ($providerConfig['auth_prefix'] ?? '')) ? '' : ($providerConfig['auth_prefix'] ?: 'Bearer');

        $headers = ['Content-Type' => 'application/json'];
        if ($authPrefix) {
            $headers[$authHeader] = $authPrefix.' '.$apiKey;
        } else {
            $headers[$authHeader] = $apiKey;
        }

        try {
            // Clamp max_tokens via output guard supaya tidak bisa over-ride
            // hard cap dari settings (default 4000). 2048 < cap dalam kondisi
            // default, tapi clamp jaga konsistensi kalau admin set cap lebih
            // ketat.
            $outputGuard = app(\App\Services\AiOutputGuard::class);
            $maxTokens = $outputGuard->clampMaxTokens(2048);

            $response = Http::timeout(30)
                ->withoutVerifying()
                ->withHeaders($headers)
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'messages' => $messages,
                    'max_tokens' => $maxTokens,
                    'temperature' => 0.7,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $reply = $data['choices'][0]['message']['content'] ?? 'Maaf, saya tidak bisa menjawab saat ini.';

                // Output safety guard — tolak respons yang melewati batas
                // karakter, mengandung pola berulang, atau baris terlalu
                // panjang. Cegah skenario "tulis A 1jt baris" yang lolos
                // dari max_tokens.
                if (! $outputGuard->isSafe($reply)) {
                    Log::warning('Avatar Chat AI Output Guard rejected response', [
                        'length' => mb_strlen($reply),
                    ]);

                    return response()->json([
                        'reply' => 'Maaf, respons AI ditolak karena melebihi batas atau mengandung pola tidak wajar. Silakan coba pertanyaan yang lebih spesifik.',
                        'error' => true,
                    ]);
                }

                // Clean response — strip all markdown formatting
                $reply = preg_replace('/```[\s\S]*?```/', '', $reply);  // code blocks
                $reply = preg_replace('/`([^`]+)`/', '$1', $reply);     // inline code
                $reply = preg_replace('/\*\*([^*]+)\*\*/', '$1', $reply); // **bold**
                $reply = preg_replace('/\*([^*]+)\*/', '$1', $reply);   // *italic*
                $reply = preg_replace('/__([^_]+)__/', '$1', $reply);   // __bold__
                $reply = preg_replace('/_([^_]+)_/', '$1', $reply);     // _italic_
                $reply = preg_replace('/^#{1,6}\s*/m', '', $reply);     // # headings
                $reply = preg_replace('/^\s*>\s*/m', '', $reply);       // > blockquotes

                return response()->json([
                    'reply' => trim($reply),
                    'model' => $providerConfig['model']->name ?? $model,
                ]);
            } else {
                $errBody = $response->json();
                $errMsg = $errBody['error']['message'] ?? $response->body();
                Log::error('Avatar Chat AI Error: '.$errMsg);

                return response()->json([
                    'reply' => 'Maaf, terjadi kesalahan pada AI. Silakan coba lagi.',
                    'error' => true,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Avatar Chat Exception: '.$e->getMessage());

            return response()->json([
                'reply' => 'Koneksi ke AI terputus. Silakan coba lagi.',
                'error' => true,
            ]);
        }
    }

    /**
     * Build the system prompt for the avatar
     */
    private function buildSystemPrompt($user, string $relevantDocs): string
    {
        $userName = $user->name ?? 'Pengguna';
        $orgName = $user->organization->name ?? 'Organisasi';

        $prompt = <<<PROMPT
Kamu adalah **Priva**, asisten virtual 3D milik platform PRIVASIMU — platform kepatuhan perlindungan data pribadi (UU PDP Indonesia).

## IDENTITAS
- Nama: Priva
- Peran: Asisten interaktif yang membantu pengguna memahami dan menggunakan platform PRIVASIMU
- Gaya bicara: Ramah, profesional, menggunakan Bahasa Indonesia yang baik
- Panggil pengguna dengan nama: {$userName}
- Organisasi pengguna: {$orgName}

## ATURAN KETAT
1. **JANGAN PERNAH** membahas tentang:
   - Sistem AI, model AI, prompt engineering, LLM, GPT, Gemini, Claude, atau teknologi AI lainnya
   - Cara kerja internal AI atau bagaimana kamu dibuat
   - Topik yang tidak berkaitan dengan platform PRIVASIMU
2. Jika ditanya tentang hal di atas, jawab: "Saya Priva, asisten platform PRIVASIMU. Saya hanya bisa membantu seputar penggunaan platform dan kepatuhan perlindungan data pribadi. Ada yang bisa saya bantu tentang PRIVASIMU?"
3. **Jawab HANYA** berdasarkan knowledge base platform yang diberikan
4. Jika tidak tahu jawabannya, katakan dengan jujur dan sarankan untuk membaca dokumentasi atau menghubungi tim support
5. Jawab dengan singkat dan jelas (maksimal 2-3 paragraf pendek). Selesaikan kalimat, jangan potong di tengah.
6. Gunakan emoji sesekali untuk membuat percakapan lebih hidup 😊
7. **JANGAN memperkenalkan diri berulang kali**. Jika sudah ada history percakapan sebelumnya, langsung jawab pertanyaan pengguna tanpa perkenalan. Perkenalan HANYA di pesan pertama saat belum ada history.
8. Variasikan gaya jawaban, jangan gunakan pola kalimat yang sama berulang kali.
9. Ingat: jawaban akan dibacakan lewat TTS (text-to-speech), jadi buat kalimat pendek dan natural untuk didengar, bukan dibaca.

## FORMAT JAWABAN (WAJIB DIIKUTI)
- Jawab dalam teks biasa (plain text) saja
- DILARANG menggunakan format markdown apapun: tidak boleh pakai tanda bintang (*), underscore (_), hash (#), backtick (`), blockquote (>)
- Jika ingin memberi penekanan, cukup gunakan tanda kutip, contoh: "fitur ini"
- Gunakan tanda strip (–) untuk daftar/list
- Jawab dalam paragraf pendek dan ringkas
- Jangan gunakan huruf kapital semua untuk satu kata

PROMPT;

        if ($relevantDocs) {
            $prompt .= "\n## KNOWLEDGE BASE (Referensi untuk menjawab)\n\n{$relevantDocs}\n";
        }

        return $prompt;
    }

    /**
     * Search the knowledge base for relevant content based on user query
     */
    private function searchKnowledgeBase(string $query): string
    {
        $kb = $this->getKnowledgeBase();
        $query = mb_strtolower($query);
        $queryWords = array_filter(explode(' ', $query), fn ($w) => mb_strlen($w) >= 3);

        $matches = [];

        foreach ($kb as $topic) {
            $score = 0;

            // Check keywords match
            foreach ($topic['keywords'] as $keyword) {
                if (str_contains($query, mb_strtolower($keyword))) {
                    $score += 10;
                }
            }

            // Check word overlap with title
            foreach ($queryWords as $word) {
                if (str_contains(mb_strtolower($topic['title']), $word)) {
                    $score += 5;
                }
                if (str_contains(mb_strtolower($topic['content']), $word)) {
                    $score += 1;
                }
            }

            if ($score > 0) {
                $matches[] = ['score' => $score, 'topic' => $topic];
            }
        }

        // Sort by score descending
        usort($matches, fn ($a, $b) => $b['score'] - $a['score']);

        // Take top 3 matches to keep context manageable
        $topMatches = array_slice($matches, 0, 3);

        if (empty($topMatches)) {
            // Include general platform overview as fallback (juga sanitize)
            return AiContentSanitizer::neutralize($this->getGeneralOverview());
        }

        $result = '';
        foreach ($topMatches as $m) {
            // P0 fix: sanitize KB content sebelum inject ke system prompt.
            // Cegah indirect injection via DB-stored KB content.
            $safeTitle = AiContentSanitizer::neutralize($m['topic']['title']);
            $safeContent = AiContentSanitizer::neutralize($m['topic']['content']);
            $result .= "### {$safeTitle}\n{$safeContent}\n\n";
        }

        return $result;
    }

    /**
     * General platform overview (fallback when no specific topic matches)
     */
    private function getGeneralOverview(): string
    {
        return <<<'OVERVIEW'
### Tentang PRIVASIMU
PRIVASIMU adalah platform SaaS/On-Premises untuk kepatuhan perlindungan data pribadi sesuai UU PDP Indonesia (UU No. 27 Tahun 2022).

Fitur utama:
• Dashboard — Pemantauan compliance score real-time
• Gap Assessment — Audit kepatuhan dengan kuesioner berdasarkan UU PDP/GDPR/PDPA
• RoPA — Pencatatan aktivitas pemrosesan data pribadi (Record of Processing Activities)
• DPIA — Data Protection Impact Assessment untuk proyek berisiko tinggi
• DSR — Pengelolaan permintaan hak subjek data (Data Subject Request)
• Data Discovery — Pemindaian database untuk deteksi PII (Personal Identifiable Information)
• Vendor Risk — Manajemen risiko pihak ketiga
• Contract Review — Review kontrak DPA menggunakan AI
• Cross Border — Dokumentasi transfer data lintas negara
• Consent Management — Widget consent + tracking persetujuan
• Breach Management — Manajemen insiden kebocoran data dengan countdown 72 jam
• Fire Drill — Simulasi breach untuk latihan response team
• Security Posture (DSPM) — Dashboard postur keamanan data
• AI Agent — Asisten AI untuk automasi compliance

Menu Settings: User Management, License, Organisasi, Departemen, Jabatan, AI Providers, AI Assistant, CRM Integration, SSO Enterprise, dan lainnya.
OVERVIEW;
    }

    /**
     * Full knowledge base as PHP array
     * Extracted and summarized from frontend feature-documentation
     */
    private function getKnowledgeBase(): array
    {
        return [
            // ===== DASHBOARD =====
            [
                'title' => 'Dashboard Utama & Compliance Score',
                'keywords' => ['dashboard', 'compliance', 'score', 'skor', 'kepatuhan', 'kpi', 'statistik', 'beranda', 'home'],
                'content' => 'Dashboard menampilkan Compliance Score (persentase kepatuhan keseluruhan), jumlah DSR yang belum diproses, statistik RoPA/DPIA, dan status breach. Data real-time dari semua modul. Compliance Score dihitung dari Gap Assessment terbaru. KPI: Open DSR, Active Breaches, RoPA Coverage, DPIA Pending. Ada grafik tren 6 bulan dan pie chart per kategori.',
            ],

            // ===== GAP ASSESSMENT =====
            [
                'title' => 'Gap Assessment & Audit Kepatuhan',
                'keywords' => ['gap', 'assessment', 'audit', 'kepatuhan', 'kuesioner', 'regulasi', 'framework', 'uu pdp', 'gdpr'],
                'content' => 'Modul Gap Assessment mengevaluasi kepatuhan organisasi terhadap UU PDP, GDPR, atau PDPA. Fitur: kuesioner dinamis (Tata Kelola & Siklus Proses PDP), scoring otomatis, AI Remediation Plan, dan Compare Assessment (radar chart). Langkah: Mulai Assessment → Pilih Framework → Isi Kuesioner → Lihat Skor & Rekomendasi → Export PDF. AI Remediation Plan menganalisis gap dan membuat roadmap perbaikan (memotong 1 credit AI).',
            ],

            // ===== RoPA =====
            [
                'title' => 'RoPA (Record of Processing Activities)',
                'keywords' => ['ropa', 'pemrosesan', 'data pribadi', 'record', 'aktivitas', 'retensi', 'dasar hukum'],
                'content' => 'Berdasarkan Pasal 31 UU PDP, wajib memiliki rekam jejak pemrosesan. RoPA merekam: divisi, tujuan pemrosesan, jenis data, masa retensi, mekanisme keamanan. Fitur AI Auto-Fill: isi semua field hanya dengan kata kunci singkat (1 credit). Risk Level (Low/Medium/High/Critical) dihitung otomatis. Bisa trigger DPIA otomatis jika risiko tinggi. Export PDF untuk audit.',
            ],

            // ===== DPIA =====
            [
                'title' => 'DPIA (Data Protection Impact Assessment)',
                'keywords' => ['dpia', 'impact', 'assessment', 'risiko', 'privasi', 'proyek', 'mitigasi', 'dpo'],
                'content' => 'DPIA wajib sebelum pemrosesan data berisiko tinggi. Template terstruktur: identifikasi risiko, analisis likelihood × impact (matriks 5×5), rencana mitigasi. Risk Level: Critical (20-25), High (12-19), Medium (6-11), Low (1-5). Alur: Buat DPIA → Detail Proyek → Identifikasi Risiko → Analisis → Mitigasi → Review DPO → Approved/Rejected/Needs Revision.',
            ],

            // ===== DSR =====
            [
                'title' => 'DSR (Data Subject Request)',
                'keywords' => ['dsr', 'hak', 'subjek', 'akses', 'hapus', 'portabilitas', 'permintaan', 'right', 'forgotten'],
                'content' => 'DSR adalah permintaan resmi pemilik data untuk menggunakan haknya: Hak Akses, Perbaikan, Penghapusan (Right to be Forgotten), Pembatasan, Portabilitas, Keberatan. Wajib respons 3x24 jam (UU PDP). Alur: Terima & Catat → Verifikasi Identitas → Assign PIC → Proses → Kirim Respon → Tutup. AI Auto-Draft untuk template surat balasan (1 credit).',
            ],

            // ===== DATA DISCOVERY =====
            [
                'title' => 'Data Discovery & PII Detection',
                'keywords' => ['discovery', 'scan', 'pii', 'database', 'shadow', 'koneksi', 'deteksi', 'kolom', 'tabel'],
                'content' => 'Memindai database (MySQL, PostgreSQL, MongoDB, MSSQL, Oracle) dan cloud storage untuk menemukan PII. Standard Scan: pattern matching nama kolom. AI Deep Scan: content sampling untuk shadow data. Shadow Score (0-100). Protection Assessment: checklist manual per kolom PII (masking, enkripsi, ACL, API redaction, audit log, retensi). Gunakan credential READ-ONLY.',
            ],

            // ===== VENDOR RISK =====
            [
                'title' => 'Vendor Risk Management',
                'keywords' => ['vendor', 'risk', 'pihak ketiga', 'third party', 'dpa', 'kontrak', 'mitra'],
                'content' => 'Kelola risiko pembagian data ke pihak ketiga. Registrasi vendor: nama, layanan, jenis data, lokasi server, sertifikasi (ISO 27001, SOC 2). VRA (Vendor Risk Assessment) kuesioner. Scoring: Low/Medium/High/Critical. Review minimal setiap tahun. Vendor Critical sebaiknya tidak melanjutkan kontrak tanpa remediation.',
            ],

            // ===== CONTRACT REVIEW =====
            [
                'title' => 'Contract Review (AI)',
                'keywords' => ['kontrak', 'contract', 'review', 'dpa', 'klausul', 'upload', 'pdf'],
                'content' => 'Upload PDF kontrak, AI mengidentifikasi klausul DPA: ✅ Compliant, ⚠️ Missing, 📝 Rekomendasi Revisi. Memotong 2-3 credit AI tergantung panjang dokumen. Export temuan sebagai checklist untuk tim legal.',
            ],

            // ===== CROSS BORDER =====
            [
                'title' => 'Cross Border Data Transfer',
                'keywords' => ['cross border', 'transfer', 'lintas batas', 'negara', 'adequacy', 'scc'],
                'content' => 'Dokumentasi transfer data ke luar Indonesia. UU PDP mengatur negara tujuan harus punya standar setara (Adequacy Decision) atau ada SCC/BCR/persetujuan eksplisit. Register: negara tujuan, jenis data, dasar legal, safeguards (enkripsi, pseudonymization).',
            ],

            // ===== CONSENT MANAGEMENT =====
            [
                'title' => 'Consent Management & Widget',
                'keywords' => ['consent', 'persetujuan', 'widget', 'banner', 'cookie', 'embed', 'preference', 'crm', 'telemarketing'],
                'content' => 'Buat widget privacy consent banner untuk website. Kustomisasi tampilan, generate kode embed (script tag). Setiap consent tercatat: IP, User-Agent, timestamp — bukti legal. Preference Center untuk ubah pilihan. Integrasi CRM: Odoo, Salesforce, HubSpot, Zoho, Pipedrive, Dynamics 365, Zendesk, Freshsales, Bitrix24, Keap, SugarCRM. Consent as a Service (CaaS) — klien B2B tidak perlu bangun backend sendiri. Mendukung Mobile Native (API headless).',
            ],

            // ===== BREACH MANAGEMENT =====
            [
                'title' => 'Data Breach Management',
                'keywords' => ['breach', 'kebocoran', 'insiden', 'notifikasi', 'komdigi', 'war room', 'telegram', 'siem'],
                'content' => 'Manajemen insiden kebocoran data. 5-Phase Lifecycle: Detected → Assessing → Containment → Notification → Closed. Countdown 72 jam (UU PDP Pasal 46). Containment checklist 10 item. Integrasi: Telegram War Room, SIEM (Splunk/ELK/Wazuh), SOAR automation. AI Breach Advisor. Template Root Cause Analysis & Remediation.',
            ],

            // ===== FIRE DRILL =====
            [
                'title' => 'Fire Drill (Simulasi Breach)',
                'keywords' => ['fire drill', 'simulasi', 'latihan', 'skenario', 'gladi', 'exercise'],
                'content' => 'Simulasi breach fiktif untuk menguji kesiapan tim. Template: Ransomware, Phishing, Data Leak, Insider Threat, atau custom. Parameter: timer, keparahan, objective. Scoring efisiensi berdasarkan kecepatan dan kelengkapan respon. Lakukan minimal setiap 6 bulan.',
            ],

            // ===== SECURITY POSTURE =====
            [
                'title' => 'Data Security Posture (DSPM)',
                'keywords' => ['security', 'posture', 'dspm', 'keamanan', 'enkripsi', 'infrastruktur'],
                'content' => 'Dashboard keamanan: status enkripsi (at rest & in transit), audit log, access control, vulnerability summary. Checklist: Encryption at Rest, TLS in Transit, Access Control, Audit Logging, Backup & Recovery, Patch Management.',
            ],

            // ===== USER MANAGEMENT =====
            [
                'title' => 'User Management & RBAC',
                'keywords' => ['user', 'pengguna', 'role', 'rbac', 'admin', 'dpo', 'maker', 'viewer', 'superadmin', 'akun'],
                'content' => '5 Role Default: Superadmin (root), Admin (kelola tenant), DPO (reviewer/approver), Maker (read-write), Viewer (read-only). Custom Role untuk akses granular per modul. Struktur multi-organisasi: Holding → Sub Holding → Anak Perusahaan. Setiap user hanya milik 1 organisasi. Invite via email, bisa nonaktifkan tanpa hapus.',
            ],

            // ===== LICENSE =====
            [
                'title' => 'Manajemen License',
                'keywords' => ['license', 'lisensi', 'aktivasi', 'paket', 'saas', 'perpetual', 'kode', 'key'],
                'content' => 'Aktivasi kode lisensi format PRIV-XXXX-XXXX-XXXX. SaaS: bayar bulanan/tahunan, hosting cloud, auto-update. Perpetual: bayar sekali, deploy sendiri (Docker), fitur tambahan (SSO, Custom Role, Web Terminal). Jika expired: read-only mode, data tetap aman.',
            ],

            // ===== SETTINGS =====
            [
                'title' => 'Pengaturan Sistem',
                'keywords' => ['settings', 'pengaturan', 'konfigurasi', 'organisasi', 'profil', 'password', 'notifikasi', 'sso'],
                'content' => 'Menu Settings mencakup: Profil Organisasi, Departemen (hierarki parent-child), Jabatan, CRM Integration (11 CRM provider), Profil Saya, Keamanan (password + idle timeout), Notifikasi (email toggle), Compliance Settings (DSR deadline, breach deadline, retensi), Otomasi Sistem (4 automation rule), Master Aplikasi (registrasi database), Manajemen Role (custom RBAC), SSO Enterprise (Azure AD, Google Workspace, Keycloak).',
            ],

            // ===== AI PROVIDERS =====
            [
                'title' => 'AI Providers & Credits',
                'keywords' => ['ai', 'provider', 'llm', 'openai', 'gemini', 'deepseek', 'api key', 'credits', 'credit', 'kuota'],
                'content' => 'Kelola API key untuk berbagai AI LLM: OpenAI, Google Gemini, Anthropic Claude, DeepSeek, Groq, Mistral. Simpan API key (dienkripsi), test koneksi, pilih model aktif untuk: Chat/Autofill, AI Agent, Document, dan Avatar 3D. AI Credits: mata uang internal, monitoring penggunaan. Umumnya 1 credit per aksi AI. Reset bulanan atau top-up.',
            ],

            // ===== AI AGENT =====
            [
                'title' => 'AI Agent',
                'keywords' => ['agent', 'chat', 'ai agent', 'function calling', 'otomasi', 'agentic'],
                'content' => 'AI Agent adalah asisten cerdas dengan kemampuan function calling — bisa mengeksekusi aksi langsung di platform. Fitur: membuat RoPA/DPIA/DSR/Breach otomatis, mengisi form, menganalisis data, menjawab pertanyaan compliance. Memerlukan model yang mendukung Tools/Function Calling. Tersedia di menu AI Agent.',
            ],

            // ===== AI ASSISTANT / KNOWLEDGE BASE =====
            [
                'title' => 'AI Assistant & Knowledge Base',
                'keywords' => ['knowledge', 'base', 'assistant', 'chatbot', 'markdown', 'konten'],
                'content' => 'Knowledge Base Editor: konten Markdown yang menjadi referensi utama AI chatbot saat menjawab. Superadmin bisa edit konten: info organisasi, kebijakan privasi, prosedur internal, FAQ. Semakin lengkap = semakin baik jawaban AI.',
            ],

            // ===== SUPERADMIN TOOLS =====
            [
                'title' => 'Superadmin Tools',
                'keywords' => ['superadmin', 'terminal', 'system', 'log', 'update', 'feature', 'flags', 'maintenance'],
                'content' => 'Tools khusus Superadmin: Chat History (audit percakapan AI), System Update (cek versi & deploy), Feature Status (toggle modul on/off/maintenance), System Logs (error monitoring dari laravel.log), Web Terminal (command line di browser — htop, artisan, dll).',
            ],

            // ===== AVATAR 3D =====
            [
                'title' => 'Avatar 3D Priva',
                'keywords' => ['avatar', '3d', 'priva', 'vrm', 'model', 'suara', 'bicara', 'mic'],
                'content' => 'Avatar 3D Priva adalah asisten virtual interaktif yang bisa ditemui di menu AI Agent. Fitur: lip sync (sinkronisasi bibir dengan suara), ekspresi emosi otomatis, speech-to-text (bicara langsung ke avatar), text-to-speech (avatar menjawab dengan suara). Pilihan model: Priva, Priva Esteh, Priva Edmuku. Klik tombol "Priva AI Avatar" di header AI Agent untuk membuka.',
            ],
        ];
    }
}
