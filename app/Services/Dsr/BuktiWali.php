<?php

namespace App\Services\Dsr;

use App\Models\DsrRequest;
use App\Models\GuardianConsent;
use App\Support\KunciPencarian;

/**
 * Bukti kewenangan wali pada permohonan DSR — PP 33/2026 Pasal 38 ayat (5)–(7)
 * & Pasal 39 ayat (5).
 *
 * SATU-SATUNYA penulis kolom guardian_proof_* dan guardian_consent_id. Kolom
 * itu sengaja tidak fillable: gerbang keamanan tidak boleh bisa dilepas lewat
 * payload universal CRUD.
 *
 * GARIS PARADOKS WALI (keputusan produk): ketat untuk hak yang MERUSAK, longgar
 * untuk yang aman. Hapus, tarik consent, batasi, keberatan, dan koreksi tidak
 * boleh dieksekusi sebelum bukti diterima — orang yang mengaku wali bisa saja
 * bukan. Akses, portabilitas, dan info tetap berjalan tetapi bukti yang masih
 * menunggu TERLIHAT jelas di layar DPO, dan DPO yang memutuskan sebelum data
 * dikirim. Garis ini ada di DsrRequest::HAK_MERUSAK — satu tempat.
 *
 * Bukti OTOMATIS: bila pasangan (surel pemohon × penanda subjek) sudah punya
 * kewenangan wali yang terverifikasi dan belum dicabut di modul consent —
 * orang yang sama, untuk anak yang sama — verifikasi tidak diulang.
 */
final class BuktiWali
{
    /** Saat permohonan lahir. Dipanggil kedua jalur (widget publik & API mitra). */
    public function tentukan(DsrRequest $dsr): void
    {
        if ($dsr->requester_type !== DsrRequest::PEMOHON_WALI) {
            $dsr->forceFill(['guardian_proof_status' => DsrRequest::BUKTI_TIDAK_PERLU])->save();

            return;
        }

        $kw = $this->kewenanganCocok($dsr);

        $dsr->forceFill([
            'guardian_consent_id' => $kw?->id,
            'guardian_proof_status' => $kw ? DsrRequest::BUKTI_OTOMATIS : DsrRequest::BUKTI_MENUNGGU,
            'guardian_proof_verified_at' => $kw ? now() : null,
        ])->save();
    }

    /** Keputusan DPO — diterima | ditolak, dengan alasan yang bisa diaudit. */
    public function putuskan(DsrRequest $dsr, string $keputusan, string $alasan, string $olehUserId): void
    {
        if (! in_array($keputusan, [DsrRequest::BUKTI_DITERIMA, DsrRequest::BUKTI_DITOLAK], true)) {
            throw new \InvalidArgumentException("Keputusan bukti wali tidak dikenal: {$keputusan}");
        }

        $dsr->forceFill([
            'guardian_proof_status' => $keputusan,
            'guardian_proof_reason' => trim($alasan),
            'guardian_proof_verified_at' => now(),
            'guardian_proof_verified_by' => $olehUserId,
        ])->save();
    }

    /**
     * Kewenangan wali yang sudah terverifikasi untuk pasangan ini — surel
     * pemohon sebagai kontak wali, penanda subjek sebagai subjek.
     */
    private function kewenanganCocok(DsrRequest $dsr): ?GuardianConsent
    {
        $hashWali = KunciPencarian::hash($dsr->requester_email);
        $hashSubjek = $dsr->subject_identifier_hash;

        if ($hashWali === null || $hashSubjek === null || $hashSubjek === '') {
            return null;
        }

        return GuardianConsent::withoutGlobalScope('org')
            ->where('org_id', $dsr->org_id)
            ->whereNotNull('verified_at')
            ->whereNull('revoked_at')
            ->whereHas('guardian', fn ($g) => $g->where('contact_hash', $hashWali))
            ->whereHas('consentSubject', fn ($s) => $s->where('subject_hash', $hashSubjek))
            ->orderByDesc('verified_at')
            ->first();
    }
}
