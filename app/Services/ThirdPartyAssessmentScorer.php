<?php

namespace App\Services;

use App\Models\VendorAssessment;
use App\Models\VendorQuestionnaire;

/**
 * Sprint G.5 — Skor calculator untuk asesmen pihak ketiga (TPRM publik).
 *
 * Berbeda dengan VendorRiskScoreService (deterministic, pakai weight + direction
 * + answer_score yang kompleks), scorer ini di-tune untuk kuisoner v2_2026 yang
 * di-isi pihak ketiga lewat tautan publik. Pilihan jawaban di-sederhanakan jadi
 * tiga nilai: 'ya' / 'tidak' / 'tidak_tahu' supaya pihak ketiga tidak perlu
 * konteks rating.
 *
 * Formula:
 *   score = (jumlah_jawab_ya / total_pertanyaan_aktif) * 100
 *
 *   Jawaban 'tidak_tahu' dan tidak diisi sama-sama tidak menambah skor —
 *   secara konservatif diperlakukan sebagai "belum terbukti compliance".
 *
 * Mapping risk level (sesuai requirement spec G.5):
 *   ≥ 80   rendah
 *   60-79  sedang
 *   40-59  tinggi
 *   < 40   kritis
 *
 * Rekomendasi: setiap pertanyaan yang dijawab 'tidak' dan punya kolom
 * `recommendation_if_no` → di-collect ke daftar rekomendasi yang bisa
 * ditindaklanjuti oleh tenant pengelola.
 *
 * Source pertanyaan: pakai VendorQuestionnaire::effectiveForOrg() supaya
 * tenant yang override / tambah pertanyaan custom tetap ter-honor — sesuai
 * pola multi-tenant landlord + override yang sudah diadopsi modul lain.
 */
class ThirdPartyAssessmentScorer
{
    public const VERSION = 'v2_2026';

    public function compute(VendorAssessment $assessment): array
    {
        // Resolve effective questions untuk org pemilik assessment. Filter:
        // hanya pertanyaan aktif + versi v2_2026 (versi publik baru).
        $questions = VendorQuestionnaire::effectiveForOrg($assessment->org_id)
            ->filter(fn ($q) => $q->is_active && $q->version === self::VERSION);

        $answers = is_array($assessment->answers) ? $assessment->answers : [];
        $totalAktif = $questions->count();

        $yes = 0;
        $no = 0;
        $recommendations = [];

        foreach ($questions as $q) {
            // Pihak ketiga menyimpan jawaban sebagai object
            // { value: 'ya'|'tidak'|'tidak_tahu', note?, evidence?: [] }
            $entry = $answers[$q->id] ?? null;
            $value = null;
            if (is_array($entry)) {
                $value = $entry['value'] ?? null;
            } elseif (is_string($entry)) {
                // Backward compat — kalau jawaban di-save sebagai string langsung.
                $value = $entry;
            }

            if ($value === 'ya') {
                $yes++;
            } elseif ($value === 'tidak') {
                $no++;
                if (! empty($q->recommendation_if_no)) {
                    $recommendations[] = [
                        'question_id' => $q->id,
                        'question_code' => $q->question_code,
                        'section' => $q->section,
                        'pertanyaan' => $q->question_text,
                        'rekomendasi' => $q->recommendation_if_no,
                    ];
                }
            }
            // 'tidak_tahu' & null tidak menambah counter — tidak ikut numerator.
        }

        $score = $totalAktif > 0
            ? round(($yes / $totalAktif) * 100, 2)
            : 0;

        $riskLevel = match (true) {
            $score >= 80 => 'rendah',
            $score >= 60 => 'sedang',
            $score >= 40 => 'tinggi',
            default => 'kritis',
        };

        return [
            'score' => $score,
            'risk_level' => $riskLevel,
            'total_aktif' => $totalAktif,
            'jawab_ya' => $yes,
            'jawab_tidak' => $no,
            'jawab_kosong' => $totalAktif - $yes - $no,
            'recommendations' => $recommendations,
            'version' => self::VERSION,
        ];
    }
}
