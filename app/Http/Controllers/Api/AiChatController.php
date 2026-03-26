<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class AiChatController extends Controller
{
    /**
     * Chat with AI assistant — answers based on PRIVASIMU knowledge base only.
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'nullable|array',
        ]);

        $apiKey = AppSetting::get('deepseek_api_key');
        if (!$apiKey) {
            return response()->json(['message' => 'API key belum dikonfigurasi. Hubungi SuperAdmin.'], 503);
        }

        $knowledgeBase = $this->getKnowledgeBase();
        $userMessage = $request->message;
        $history = $request->history ?? [];

        $systemPrompt = <<<PROMPT
Kamu adalah PRIVASIMU Assistant — asisten AI khusus untuk platform kepatuhan data pribadi PRIVASIMU berdasarkan UU Pelindungan Data Pribadi (UU No. 27 Tahun 2022).

ATURAN KETAT:
1. Kamu HANYA boleh menjawab pertanyaan yang berkaitan dengan penggunaan platform PRIVASIMU dan UU PDP Indonesia.
2. Jika user bertanya di luar konteks PRIVASIMU atau UU PDP, tolak dengan sopan: "Maaf, saya hanya bisa membantu seputar penggunaan PRIVASIMU dan kepatuhan UU PDP."
3. JANGAN PERNAH mengungkapkan teknologi yang dipakai (framework, database, bahasa pemrograman, API, dsb).
4. Jika ditanya soal teknologi/stack: "Informasi teknis internal bersifat rahasia."
5. Jawab dalam Bahasa Indonesia yang profesional dan mudah dipahami.
6. Berikan jawaban yang praktis, step-by-step jika perlu.

KNOWLEDGE BASE PRIVASIMU:
{$knowledgeBase}

Berdasarkan knowledge base di atas, bantu user memahami dan menggunakan platform PRIVASIMU dengan baik.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add conversation history (max last 10)
        $historySlice = array_slice($history, -10);
        foreach ($historySlice as $msg) {
            if (isset($msg['role']) && isset($msg['content'])) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }

        $messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.deepseek.com/chat/completions', [
                    'model' => 'deepseek-chat',
                    'messages' => $messages,
                    'temperature' => 0.3,
                    'max_tokens' => 1500,
                ]);

            if ($response->failed()) {
                \Log::error('DeepSeek API error: ' . $response->body());
                return response()->json(['message' => 'AI sedang tidak tersedia. Coba lagi nanti.'], 502);
            }

            $data = $response->json();
            $reply = $data['choices'][0]['message']['content'] ?? 'Maaf, tidak ada respons.';

            return response()->json([
                'reply' => $reply,
                'usage' => $data['usage'] ?? null,
            ]);
        } catch (\Exception $e) {
            \Log::error('AI Chat error: ' . $e->getMessage());
            return response()->json(['message' => 'Terjadi kesalahan. Coba lagi nanti.'], 500);
        }
    }

    /**
     * Get/Update knowledge base (SuperAdmin only for update)
     */
    public function knowledgeBase(Request $request)
    {
        if ($request->isMethod('GET')) {
            $kb = AppSetting::get('knowledge_base', $this->getDefaultKnowledgeBase());
            return response()->json(['data' => $kb]);
        }

        // PUT — update
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate(['content' => 'required|string']);
        AppSetting::set('knowledge_base', $request->content);

        return response()->json(['message' => 'Knowledge base updated']);
    }

    /**
     * Get/Set DeepSeek API key (SuperAdmin only)
     */
    public function apiSettings(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($request->isMethod('GET')) {
            $key = AppSetting::get('deepseek_api_key', '');
            return response()->json([
                'has_key' => !empty($key),
                'key_preview' => $key ? substr($key, 0, 8) . '...' . substr($key, -4) : null,
            ]);
        }

        // PUT — save key to database
        $request->validate(['api_key' => 'required|string']);
        AppSetting::set('deepseek_api_key', $request->api_key);

        return response()->json(['message' => 'API key updated & saved to database']);
    }

    private function getKnowledgeBase(): string
    {
        return AppSetting::get('knowledge_base', $this->getDefaultKnowledgeBase());
    }

    private function getDefaultKnowledgeBase(): string
    {
        return <<<KB
# PRIVASIMU — Platform Kepatuhan UU PDP

## Tentang PRIVASIMU
PRIVASIMU adalah platform SaaS untuk membantu organisasi mematuhi UU Pelindungan Data Pribadi (UU No. 27 Tahun 2022). Platform ini multi-tenant, artinya setiap organisasi memiliki data terpisah.

## Modul-Modul Utama

### 1. Dashboard
- Menampilkan statistik kepatuhan secara real-time
- KPI: total ROPA, DPIA, breach incidents, gap assessment score
- Chart: tren kepatuhan, distribusi risiko

### 2. ROPA (Record of Processing Activities)
- Mencatat semua aktivitas pemrosesan data pribadi
- Wizard 6 langkah: Identifikasi → Tujuan → Kategori Data → Keamanan → Review → Submit
- Risk level otomatis: data sensitif (kesehatan, biometrik, anak) → HIGH risk → otomatis generate DPIA
- Field: processing_activity, division, data_categories, legal_basis, retention_period, security_measures

### 3. DPIA (Data Protection Impact Assessment)
- Penilaian dampak pelindungan data
- Wizard 4 langkah: Identifikasi → Analisis Risiko → Mitigasi → Review
- Scoring: likelihood × impact = risk score
- Hasil: skor risiko, rekomendasi mitigasi

### 4. Gap Assessment (Analisis Kesenjangan UU PDP)
- 62 pertanyaan berdasarkan UU PDP
- 7 domain: Kebijakan, Data-processing, DPIA, Hak Subjek Data, Breach Response, Transfer Data, Organisasi
- Scoring otomatis: compliance_level (Awal/Berkembang/Terkelola/Optimized)
- Hasil: ringkasan per domain, rekomendasi tindak lanjut

### 5. Data Breach Management
- Flow 5 fase: Terdeteksi → Assessment → Containment → Notifikasi → Ditutup
- Setiap fase memiliki action items yang HARUS diselesaikan sebelum lanjut ke fase berikutnya
- Countdown 72 jam untuk notifikasi KOMDIGI (UU PDP Pasal 46)
- RACI Matrix: DPO, IT Security, Legal, Manajemen, PR/Comms
- Integrasi SIEM & SOAR
- Template notifikasi ke KOMDIGI dan subjek data (otomatis)
- Containment checklist 10 item

### 6. DSR (Data Subject Request)
- Manajemen permintaan hak subjek data
- Jenis: akses, koreksi, hapus, portabilitas, tarik consent
- Deadline tracking

### 7. Consent Management
- Kelola persetujuan pengumpulan data
- Tracking consent per collection point
- Audit trail

### 8. Simulasi / Fire Drill
- 4 mode: Quiz, Tabletop, SOP Walkthrough, Live Visual Drill
- Live Drill: simulasi real-time dengan efek visual (screen shake, flashing)
- Skenario: Ransomware Attack, Data Exfiltration
- Penilaian performa tim (A-F grade)

### 9. Data Mapping
- Pemetaan alur data dalam organisasi
- Identifikasi sumber, tujuan, dan kategori data

### 10. Dokumentasi
- Panduan penggunaan, business process, arsitektur sistem
- Tab: Proses Bisnis, Integrasi, Role & Permission, Pricing, USP & Roadmap
- Tab Arsitektur & API hanya untuk SuperAdmin

## Role & Permission
- **SuperAdmin**: Akses penuh ke semua tenant, manajemen user global, konfigurasi platform, manajemen license
- **Admin**: Manajemen tenant sendiri, user di bawah organisasinya, input license SaaS
- **DPO**: Data Protection Officer — akses semua modul compliance
- **Maker**: Input data dan pemrosesan
- **Viewer**: Hanya bisa melihat data

## License System
- Paket: Basic (tanpa AI), Pro (dengan AI), Enterprise (AI Agent)
- Tipe: Beli Putus (perpetual) atau SaaS (subscription)
- 1 license = 1x penggunaan, ada domain whitelist
- Percobaan penggunaan >1x akan tercatat dan dilaporkan

## Kontak
Untuk pembelian license: PT Sainskerta Solusi Nusantara
Kontak: 081319504441 (Galih)

## Kepatuhan UU PDP
- UU No. 27 Tahun 2022 tentang Pelindungan Data Pribadi
- Pasal 46: Notifikasi Breach dalam 3×24 jam
- Data sensitif: kesehatan, biometrik, genetik, anak, keuangan, ras, agama, orientasi seksual, pandangan politik
KB;
    }

}
