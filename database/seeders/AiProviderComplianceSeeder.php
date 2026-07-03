<?php

namespace Database\Seeders;

use App\Models\AiProvider;
use Illuminate\Database\Seeder;

/**
 * Metadata kepatuhan per AI provider — supaya pemilihan provider AI benar-benar
 * sadar UU PDP (Pasal 51 prosesor + Pasal 56 transfer lintas negara), bukan
 * sekadar disclaimer.
 *
 * Sumber: riset publik Juli 2026 (DPA, ZDR, GDPR, yurisdiksi). WAJIB diverifikasi
 * ulang oleh DPO/legal sebelum eksekusi — term provider bisa berubah.
 *
 *   gdpr_status : verified (native UE) | compliant (DPA+SCC) | partial | none
 *   pdp_risk    : safe | caution | not_recommended
 *   dpa_url     : null = "Tidak ada" (tidak menyediakan DPA enterprise)
 */
class AiProviderComplianceSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'openai' => [
                'jurisdiction' => 'Amerika Serikat', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://openai.com/policies/data-processing-addendum/',
                'privacy_url' => 'https://openai.com/policies/privacy-policy/',
                'zdr_note' => 'Opt-in via tim sales untuk endpoint eligible. Default retensi 30 hari (abuse monitoring).',
                'compliance_note' => 'Eksekusi DPA di platform.openai.com → Settings → Compliance APIs → Execute Data Processing Agreement. Tidak melatih model dari data API. Aman untuk PII dengan DPA + ZDR aktif.',
            ],
            'anthropic' => [
                'jurisdiction' => 'Amerika Serikat', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://privacy.claude.com/en/articles/7996862-how-do-i-view-and-sign-your-data-processing-addendum-dpa',
                'privacy_url' => 'https://www.anthropic.com/legal/privacy',
                'zdr_note' => 'Zero Data Retention untuk pelanggan enterprise (request) — prompt/response diproses in-memory, tidak ditulis ke disk.',
                'compliance_note' => 'DPA + SCC otomatis termasuk di Commercial Terms of Service. Tidak melatih dari data API. Aman untuk PII dengan zero-retention.',
            ],
            'google' => [
                'jurisdiction' => 'AS / region GCP (opsi EU)', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://cloud.google.com/terms/data-processing-addendum',
                'privacy_url' => 'https://cloud.google.com/terms/cloud-privacy-notice',
                'zdr_note' => 'ZDR-equivalent via Vertex AI (amandemen kontrak DPA). EU-only inference untuk workload eligible.',
                'compliance_note' => 'WAJIB pakai Vertex AI / tier berbayar — tier gratis/consumer Gemini BISA dipakai training. DPA Google Cloud (CDPA), kontrol region GCP. Tidak melatih (paid).',
            ],
            'deepseek' => [
                'jurisdiction' => 'Tiongkok (China)', 'gdpr_status' => 'none', 'no_training' => false,
                'zdr_available' => false, 'pdp_risk' => 'not_recommended',
                'dpa_url' => null,
                'privacy_url' => 'https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html',
                'zdr_note' => 'Tidak tersedia. Data pribadi diproses & disimpan di server Tiongkok tanpa tenggat hapus yang enforceable.',
                'compliance_note' => 'TIDAK ADA DPA enterprise, TIDAK ADA SCC EU–Tiongkok, yurisdiksi Tiongkok (bukan negara adekuasi). Diblokir Garante (Italia) 2026. JANGAN untuk data pribadi — hanya open-weight via host tepercaya / on-prem.',
            ],
            'deepseek-vision' => [
                'jurisdiction' => 'AS (host DeepInfra) — model open-weight', 'gdpr_status' => 'partial', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'caution',
                'dpa_url' => 'https://deepinfra.com/docs/data',
                'privacy_url' => 'https://deepinfra.com/privacy',
                'zdr_note' => 'Di-host DeepInfra (AS) — retensi minimal untuk inference. Verifikasi DPA DeepInfra untuk PII.',
                'compliance_note' => 'Model DeepSeek-OCR (open-weight) di-host DeepInfra di AS — yurisdiksi Tiongkok tidak berlaku. Untuk OCR dokumen ber-PII, konfirmasi DPA + retensi DeepInfra dulu.',
            ],
            'mistral' => [
                'jurisdiction' => 'Uni Eropa (Prancis)', 'gdpr_status' => 'verified', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://legal.mistral.ai/terms/data-processing-addendum',
                'privacy_url' => 'https://legal.mistral.ai/terms/privacy-policy',
                'zdr_note' => 'Data disimpan di UE secara default; SCC untuk transfer bila perlu.',
                'compliance_note' => 'GDPR-native (berbasis & data di Uni Eropa). PILIHAN TERBAIK untuk kepatuhan/residency Eropa. DPA + SCC tersedia untuk semua pelanggan bisnis.',
            ],
            'xai' => [
                'jurisdiction' => 'AS (SCC yurisdiksi Irlandia)', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://x.ai/legal/data-processing-addendum',
                'privacy_url' => 'https://x.ai/legal/privacy-policy',
                'zdr_note' => 'ZDR-Enabled API — tidak menyimpan User Content (termasuk PII) setelah proses selesai.',
                'compliance_note' => 'DPA otomatis (SCC Module 2/3, hukum Irlandia). Mendukung GDPR/CCPA/HIPAA + audit logging. Aman untuk PII dengan ZDR-Enabled API.',
            ],
            'qwen' => [
                'jurisdiction' => 'Tiongkok (Alibaba; Internasional = Singapura)', 'gdpr_status' => 'partial', 'no_training' => true,
                'zdr_available' => false, 'pdp_risk' => 'caution',
                'dpa_url' => 'https://www.alibabacloud.com/help/en/legal',
                'privacy_url' => 'https://www.alibabacloud.com/help/en/legal/latest/alibaba-cloud-international-website-privacy-policy',
                'zdr_note' => 'ZDR tidak dipublikasi. Klaim tidak melatih; data transit terenkripsi. Region: termasuk Frankfurt (UE).',
                'compliance_note' => 'Alibaba Cloud Internasional (entitas Singapura). Untuk GDPR ketat: pakai region Frankfurt atau self-host open-weight. DPA GDPR khusus belum dipublikasi — hubungi Alibaba. Hindari untuk PII sensitif kecuali on-prem / region UE.',
            ],
            'groq' => [
                'jurisdiction' => 'Amerika Serikat', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://console.groq.com/docs/legal/customer-data-processing-addendum',
                'privacy_url' => 'https://groq.com/privacy-policy/',
                'zdr_note' => 'Default TIDAK menyimpan data inference; ZDR bisa diaktifkan di Data Controls. Hapus data ≤180 hari saat terminasi.',
                'compliance_note' => 'Penyedia inference AS (host, bukan pembuat model). Default no-retention untuk inference; DPA GroqCloud tersedia. Aman untuk PII dengan ZDR.',
            ],
            'cohere' => [
                'jurisdiction' => 'Kanada (status adekuasi UE)', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://trustcenter.cohere.com/',
                'privacy_url' => 'https://cohere.com/privacy',
                'zdr_note' => 'Komitmen enterprise; DPA multi-yurisdiksi (request ke privacy@cohere.com).',
                'compliance_note' => 'Kanada memiliki status adekuasi UE (transfer lebih mudah). DPA multi-yurisdiksi + SOC 2 Type II. Tidak melatih dari data pelanggan. Aman untuk PII.',
            ],
            'openrouter' => [
                'jurisdiction' => 'AS (aggregator multi-provider)', 'gdpr_status' => 'partial', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'caution',
                'dpa_url' => 'https://openrouter.ai/docs/guides/privacy/provider-logging',
                'privacy_url' => 'https://openrouter.ai/privacy',
                'zdr_note' => 'Set parameter zdr=true → hanya route ke endpoint ZDR. OpenRouter sendiri default tidak log prompt/response.',
                'compliance_note' => 'Aggregator — retensi bergantung provider TUJUAN. Untuk PII: WAJIB aktifkan zdr=true + allowlist provider ber-DPA jelas; kepatuhan sulit dijamin end-to-end (multi-hop).',
            ],
            'modelslab' => [
                'jurisdiction' => 'India (aggregator open-source)', 'gdpr_status' => 'none', 'no_training' => false,
                'zdr_available' => false, 'pdp_risk' => 'caution',
                'dpa_url' => null,
                'privacy_url' => 'https://modelslab.com/privacy',
                'zdr_note' => 'Tidak dipublikasi.',
                'compliance_note' => 'Aggregator 50rb+ model open-source. DPA/GDPR enterprise tidak dipublikasi. Cocok untuk konten NON-PII (gen media). Hindari untuk data pribadi.',
            ],

            // ── Voice / TTS providers (suara = data BIOMETRIK, Pasal 4 UU PDP) ──
            'elevenlabs' => [
                'jurisdiction' => 'Amerika Serikat (sertifikasi EU-US DPF)', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://elevenlabs.io/dpa',
                'privacy_url' => 'https://elevenlabs.io/privacy-policy',
                'zdr_note' => 'Opsi data residency + no-training. Tersertifikasi EU-US Data Privacy Framework (aktif per 2026).',
                'compliance_note' => 'Suara = data BIOMETRIK (Pasal 4 UU PDP / Art.9 GDPR) → butuh persetujuan EKSPLISIT. DPA + SCC + dukungan DPIA. Aman dengan DPA + consent biometrik.',
            ],
            'openai-tts' => [
                'jurisdiction' => 'Amerika Serikat', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://openai.com/policies/data-processing-addendum/',
                'privacy_url' => 'https://openai.com/policies/privacy-policy/',
                'zdr_note' => 'Sama dengan OpenAI API — ZDR opt-in via sales.',
                'compliance_note' => 'TTS OpenAI di bawah DPA OpenAI. Suara = biometrik → perhatikan consent. Aman dengan DPA + ZDR.',
            ],
            'google-tts' => [
                'jurisdiction' => 'AS / region GCP (opsi EU)', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://cloud.google.com/terms/data-processing-addendum',
                'privacy_url' => 'https://cloud.google.com/terms/cloud-privacy-notice',
                'zdr_note' => 'Google Cloud DPA (CDPA), kontrol region GCP.',
                'compliance_note' => 'Google Cloud Text-to-Speech di bawah CDPA. Suara = biometrik. Aman dengan region + consent.',
            ],
            'azure-tts' => [
                'jurisdiction' => 'Region customer + EU Data Boundary', 'gdpr_status' => 'compliant', 'no_training' => true,
                'zdr_available' => true, 'pdp_risk' => 'safe',
                'dpa_url' => 'https://www.microsoft.com/licensing/docs/view/Microsoft-Products-and-Services-Data-Protection-Addendum-DPA',
                'privacy_url' => 'https://privacy.microsoft.com/privacystatement',
                'zdr_note' => 'EU Data Boundary (Data Zone Standard EUR) — data tetap di UE. Tidak menyimpan/melatih.',
                'compliance_note' => 'Azure AI Speech di bawah Microsoft Products & Services DPA. Kontrol residency TERKUAT (EU Data Boundary). Suara = biometrik → consent. Sangat aman.',
            ],
            'minimax-tts' => [
                'jurisdiction' => 'Tiongkok (Nanonoble, entitas Singapura)', 'gdpr_status' => 'partial', 'no_training' => null,
                'zdr_available' => false, 'pdp_risk' => 'caution',
                'dpa_url' => null,
                'privacy_url' => 'https://www.minimax.io/audio/doc/privacy-policy.html',
                'zdr_note' => 'ZDR tidak dipublikasi. Data kemungkinan diproses di server Tiongkok. DPA enterprise: perlu diminta.',
                'compliance_note' => 'Perusahaan Tiongkok — data suara (biometrik) bisa diproses di Tiongkok. DPA khusus perlu diminta. Hindari untuk suara ber-PII kecuali ada DPA + safeguard transfer.',
            ],
        ];

        $n = 0;
        foreach ($data as $slug => $fields) {
            $p = AiProvider::where('slug', $slug)->first();
            if (! $p) {
                continue;
            }
            $p->fill($fields)->save();
            $n++;
        }

        $this->command->info("✅ Compliance metadata di-set untuk {$n} AI provider (DPA/ZDR/GDPR/yurisdiksi/risiko PDP)");
    }
}
