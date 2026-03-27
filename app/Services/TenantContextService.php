<?php

namespace App\Services;

use App\Models\GapAssessment;
use App\Models\Organization;
use App\Models\Ropa;
use Illuminate\Support\Facades\DB;

class TenantContextService
{
    /**
     * Build a rich context string from tenant's onboarding data + Gap Assessment.
     * This is injected into every AI prompt as system context.
     */
    public static function buildContext(string $orgId): string
    {
        $org = Organization::find($orgId);
        if (!$org) return 'Tidak ada data organisasi.';

        $parts = [];

        // ── 1. Organization Profile ──
        $parts[] = "=== PROFIL ORGANISASI ===";
        $parts[] = "Nama: {$org->name}";
        if ($org->industry) $parts[] = "Sektor Industri: {$org->industry}";
        if ($org->business_model) $parts[] = "Model Bisnis: {$org->business_model}";
        if ($org->company_size) $parts[] = "Ukuran Perusahaan: {$org->company_size} karyawan";
        if ($org->website) $parts[] = "Website: {$org->website}";
        if ($org->has_dpo) $parts[] = "Status DPO: Sudah memiliki Data Protection Officer";
        else $parts[] = "Status DPO: Belum memiliki DPO";

        // ── 2. Data Subjects & Systems ──
        $subjects = $org->data_subjects_type ?? [];
        if (!empty($subjects)) {
            $parts[] = "\n=== SUBJEK DATA UTAMA ===";
            $parts[] = implode(', ', $subjects);
        }

        $systems = $org->core_systems ?? [];
        if (!empty($systems)) {
            $parts[] = "\n=== SISTEM INTI YANG DIGUNAKAN ===";
            $parts[] = implode(', ', $systems);
        }

        // ── 3. Latest Gap Assessment Score ──
        $latestGap = GapAssessment::where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->latest()
            ->first();

        if ($latestGap) {
            $parts[] = "\n=== SKOR GAP ASSESSMENT TERAKHIR ===";
            $parts[] = "Skor Kepatuhan: " . ($latestGap->overall_score ?? $latestGap->score ?? 0) . "%";
            $parts[] = "Level: " . ($latestGap->compliance_level ?? 'unknown');

            // Extract weak areas from answers
            $answers = $latestGap->answers ?? [];
            $weakAreas = [];
            if (is_array($answers)) {
                foreach ($answers as $key => $answer) {
                    if (is_array($answer)) {
                        $score = $answer['score'] ?? $answer['skor'] ?? null;
                        if ($score !== null && $score < 3) {
                            $weakAreas[] = $answer['question'] ?? $answer['pertanyaan'] ?? $key;
                        }
                    }
                }
            }
            if (!empty($weakAreas)) {
                $parts[] = "Area Lemah: " . implode('; ', array_slice($weakAreas, 0, 5));
            }
        }

        // ── 4. Existing ROPA summary (to avoid duplicate suggestions) ──
        $ropaCount = Ropa::where('org_id', $orgId)->whereNull('deleted_at')->count();
        if ($ropaCount > 0) {
            $existingActivities = Ropa::where('org_id', $orgId)
                ->whereNull('deleted_at')
                ->pluck('processing_activity')
                ->take(10)
                ->toArray();

            $parts[] = "\n=== ROPA YANG SUDAH ADA ({$ropaCount} record) ===";
            $parts[] = implode(', ', $existingActivities);
            $parts[] = "(Jangan menyarankan aktivitas yang sudah tercatat di atas)";
        }

        return implode("\n", $parts);
    }

    /**
     * Get a compact context summary (for chat / lighter prompts)
     */
    public static function buildCompactContext(string $orgId): string
    {
        $org = Organization::find($orgId);
        if (!$org) return '';

        $info = [];
        if ($org->name) $info[] = $org->name;
        if ($org->industry) $info[] = "Industri: {$org->industry}";
        if ($org->business_model) $info[] = "Model: {$org->business_model}";
        if ($org->company_size) $info[] = "Size: {$org->company_size}";

        $subjects = $org->data_subjects_type ?? [];
        if (!empty($subjects)) $info[] = "Subjek Data: " . implode(', ', $subjects);

        return implode(' | ', $info);
    }
}
