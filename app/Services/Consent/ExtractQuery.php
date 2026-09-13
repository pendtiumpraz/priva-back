<?php

namespace App\Services\Consent;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use Illuminate\Database\Eloquent\Builder;

/**
 * Satu-satunya penyusun kueri untuk ekstrak consent.
 *
 * Sebelumnya kueri yang sama ditulis DUA KALI — di ConsentExtractController
 * ::buildQuery dan di PushExtractToCrmJob::loadRecords. Keduanya kebetulan
 * masih setara, tapi susunannya sudah berbeda, dan tiap penapis baru harus
 * ditulis dua kali agar tetap seiring. Yang lebih berbahaya: gerbang aturan
 * yang dipasang pada salah satunya saja akan membuat pratinjau terjaga
 * sementara dorongan CRM tetap bocor.
 *
 * PENAPIS COLLECTION DIPERBAIKI DI SINI
 * -------------------------------------
 * `consent_logs.collection_id` menyimpan UUID titik pengumpulan, sedangkan
 * kotak isian di wizard bertuliskan contoh "CNT-…" — yaitu kode yang dibaca
 * manusia. Membandingkan keduanya secara langsung TIDAK PERNAH cocok, sehingga
 * menyaring per titik diam-diam menghasilkan nol baris dan terlihat seperti
 * "memang tidak ada datanya". Di sini nilainya diterjemahkan lebih dulu: kode
 * maupun UUID sama-sama diterima.
 */
class ExtractQuery
{
    /**
     * @param  array<string,mixed>  $filters
     * @return Builder<ConsentLog>
     */
    public function build(string $orgId, array $filters): Builder
    {
        /** @var Builder<ConsentLog> $q */
        $q = ConsentLog::withoutGlobalScope('org')->where('org_id', $orgId);

        if (! empty($filters['collection_id'])) {
            $uuid = $this->resolvePointId($orgId, (string) $filters['collection_id']);

            // Titik yang tidak dikenali menghasilkan nol baris — itu jawaban
            // yang jujur. Mengabaikan penapisnya akan mengirim SEMUA orang
            // hanya karena kodenya salah ketik.
            $q->where('collection_id', $uuid ?? '-tidak-ada-');
        }

        if (! empty($filters['source_form'])) {
            $q->where('source_form', $filters['source_form']);
        }
        if (! empty($filters['country'])) {
            $q->where('ip_country', strtoupper((string) $filters['country']));
        }
        if (! empty($filters['date_from'])) {
            $q->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('created_at', '<=', $filters['date_to']);
        }
        foreach ((array) ($filters['purpose_keys'] ?? []) as $key) {
            // Portabel lintas basis data: LIKE atas larik yang ter-JSON.
            $q->where('purpose_keys', 'like', '%"'.addslashes((string) $key).'"%');
        }

        // Hanya baris yang teridentifikasi.
        $q->whereNotNull('email');

        return $q;
    }

    /**
     * Menerima kode yang dibaca manusia ("CNT-2026-001") maupun UUID.
     */
    public function resolvePointId(string $orgId, string $nilai): ?string
    {
        $id = ConsentCollectionPoint::where('org_id', $orgId)
            ->where(fn ($w) => $w->where('collection_id', $nilai)->orWhere('id', $nilai))
            ->value('id');

        return $id !== null ? (string) $id : null;
    }
}
