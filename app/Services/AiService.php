<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\KnowledgeBaseSection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    private string $apiKey;
    private string $model = 'deepseek-chat';

    public function __construct()
    {
        $this->apiKey = AppSetting::get('deepseek_api_key', '');
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Send a prompt to DeepSeek and get structured JSON response
     */
    public function ask(string $systemPrompt, string $userPrompt, int $maxTokens = 2000): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $response = Http::timeout(60)
                ->withoutVerifying()
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.deepseek.com/chat/completions', [
                    'model' => $this->model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.3,
                    'max_tokens' => $maxTokens,
                ]);

            if ($response->failed()) {
                Log::error('DeepSeek API error: ' . $response->body());
                return null;
            }

            $data = $response->json();
            $content = $data['choices'][0]['message']['content'] ?? '';

            // Try parse JSON
            $cleaned = trim($content);
            if (str_starts_with($cleaned, '```')) {
                $cleaned = preg_replace('/^```(?:json)?\s*/', '', $cleaned);
                $cleaned = preg_replace('/\s*```$/', '', $cleaned);
            }

            $parsed = json_decode($cleaned, true);
            if ($parsed) {
                return $parsed;
            }

            // Return as raw text
            return ['raw' => $content];
        } catch (\Exception $e) {
            Log::error('AI Service error: ' . $e->getMessage());
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
}
