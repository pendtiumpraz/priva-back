<?php

namespace App\Services;

use App\Models\Vendor;

/**
 * Sinkronisasi skor "headline" vendor (kolom cache `risk_score` + `risk_level`
 * yang tampil di badge tabel TPRM).
 *
 * Rework TPRM (2026-07) — skor DISIMPAN PER-ASESMEN (tiap VendorAssessment per
 * library punya score + risk_level sendiri, TIDAK dirata-rata). Headline vendor
 * hanya MEMILIH satu asesmen sebagai wajah:
 *
 *   1. Asesmen UU PDP (library category pdp_compliance) yang sudah dinilai —
 *      paling baru. UU PDP adalah asesmen utama.
 *   2. Bila belum ada UU PDP dinilai → asesmen dinilai TERBARU (library apa pun).
 *   3. Bila belum ada asesmen dinilai sama sekali → sentinel "belum dinilai"
 *      (risk_score 0, risk_level 'belum_dinilai' — kolom NOT NULL di schema).
 *
 * "Dinilai" = punya `score` non-null (asesmen publik yang sudah submit, atau
 * asesmen internal deterministic/AI). Tautan draft/sent yang belum diisi punya
 * score null → tidak pernah jadi headline.
 */
class VendorHeadlineService
{
    public function __construct(private CanonicalPdpLibraryService $pdp) {}

    public function sync(Vendor $vendor): void
    {
        $scored = $vendor->assessments()
            ->whereNotNull('score')
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->get(['id', 'library_id', 'score', 'risk_level', 'submitted_at']);

        if ($scored->isEmpty()) {
            // Belum dinilai — reset ke sentinel schema (kolom NOT NULL: risk_score
            // default 0, risk_level 'belum_dinilai'). Jangan tampilkan skor palsu.
            $vendor->forceFill([
                'risk_score' => 0,
                'risk_level' => 'belum_dinilai',
            ])->save();

            return;
        }

        $pdpLibIds = $this->pdp->pdpLibraryIds();

        // Prefer asesmen UU PDP terbaru (list sudah desc), else terbaru apa pun.
        $headline = $scored->first(fn ($a) => $a->library_id !== null
            && in_array($a->library_id, $pdpLibIds, true))
            ?? $scored->first();

        $vendor->forceFill([
            'risk_score' => (int) $headline->score,
            'risk_level' => $headline->risk_level,
        ])->save();
    }
}
