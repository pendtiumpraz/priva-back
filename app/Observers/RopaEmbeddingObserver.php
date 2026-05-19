<?php

namespace App\Observers;

use App\Jobs\EmbedRecordJob;
use App\Models\Ropa;
use Illuminate\Support\Facades\DB;

/**
 * Observer untuk auto-dispatch embedding job ketika RoPA dibuat/diubah.
 *
 * Cross-tenant safety: dispatch job dengan $ropa->org_id (no hardcode).
 * Skipped entirely jika config('ai_embedding.enabled') === false.
 *
 * Soft-delete pattern: deleted_at di vector_embeddings di-set saat Ropa
 * soft-deleted, di-unset saat restored — vector rows tidak hard-delete
 * supaya restore tetap fungsional.
 */
class RopaEmbeddingObserver
{
    /**
     * Fields yang trigger re-embedding ketika berubah.
     */
    private const SIGNIFICANT_FIELDS = [
        'processing_activity',
        'description',
        'wizard_data',
        'division',
        'risk_level',
    ];

    /**
     * Max content length to send for embedding (rough char budget).
     */
    private const MAX_CONTENT_CHARS = 3000;

    /**
     * Handle the Ropa "saved" event (covers create & update).
     */
    public function saved(Ropa $ropa): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        // Pada update, skip kalau tidak ada signifikan field yang berubah.
        // Untuk create (wasRecentlyCreated), wasChanged tetap true untuk
        // setiap dirty field, jadi check ini juga aman untuk create.
        if (! $ropa->wasRecentlyCreated && ! $ropa->wasChanged(self::SIGNIFICANT_FIELDS)) {
            return;
        }

        $content = $this->buildContent($ropa);
        if ($content === '') {
            return;
        }

        $metadata = [
            'registration_number' => $ropa->registration_number,
            'division' => $ropa->division,
            'risk_level' => $ropa->risk_level,
            'status' => $ropa->status,
        ];

        EmbedRecordJob::dispatch(
            $ropa->org_id,
            'ropa',
            $ropa->id,
            $content,
            $metadata,
        );
    }

    /**
     * Handle the Ropa "deleted" event (soft-delete in this codebase).
     *
     * Vector rows tidak di-hard-delete — hanya tandai deleted_at supaya
     * restore() bisa balikin tanpa re-embed (hemat 1 kredit + 1 API call).
     */
    public function deleted(Ropa $ropa): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        DB::table('vector_embeddings')
            ->where('org_id', $ropa->org_id)
            ->where('source_type', 'ropa')
            ->where('source_id', $ropa->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    /**
     * Handle the Ropa "restored" event — un-set deleted_at on matching
     * vector rows. Org scoping retained as defense-in-depth.
     */
    public function restored(Ropa $ropa): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        DB::table('vector_embeddings')
            ->where('org_id', $ropa->org_id)
            ->where('source_type', 'ropa')
            ->where('source_id', $ropa->id)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null]);
    }

    /**
     * Compose content string from RoPA + wizard_data sections.
     * Trim ke ~3000 char untuk fit embedding model context.
     */
    private function buildContent(Ropa $ropa): string
    {
        $parts = [];

        if (! empty($ropa->registration_number)) {
            $parts[] = 'Registration: '.$ropa->registration_number;
        }
        if (! empty($ropa->processing_activity)) {
            $parts[] = 'Aktivitas Pemrosesan: '.$ropa->processing_activity;
        }
        if (! empty($ropa->division)) {
            $parts[] = 'Divisi: '.$ropa->division;
        }
        if (! empty($ropa->risk_level)) {
            $parts[] = 'Risk Level: '.$ropa->risk_level;
        }
        if (! empty($ropa->description)) {
            $parts[] = 'Deskripsi: '.$ropa->description;
        }

        $wizard = $ropa->wizard_data ?? [];

        // Section 1 — detail_pemrosesan
        $detail = $wizard['detail_pemrosesan'] ?? [];
        if (! empty($detail['nama_pemrosesan'])) {
            $parts[] = 'Nama Pemrosesan: '.$detail['nama_pemrosesan'];
        }
        if (! empty($detail['deskripsi'])) {
            $parts[] = 'Detail Deskripsi: '.$detail['deskripsi'];
        }

        // Section 2 — dpo_team
        $dpoTeam = $wizard['dpo_team'] ?? [];
        $dpoList = $dpoTeam['dpo_list'] ?? null;
        if (! empty($dpoList)) {
            $parts[] = 'DPO Team: '.$this->stringifyValue($dpoList);
        }

        // Section 3 — informasi_pemrosesan
        $info = $wizard['informasi_pemrosesan'] ?? [];
        if (! empty($info['purpose'])) {
            $parts[] = 'Tujuan: '.$this->stringifyValue($info['purpose']);
        }

        // Section 4 — pengumpulan_data
        $collect = $wizard['pengumpulan_data'] ?? [];
        if (! empty($collect['kategori_subjek'])) {
            $parts[] = 'Kategori Subjek: '.$this->stringifyValue($collect['kategori_subjek']);
        }

        $joined = trim(implode("\n", array_filter($parts, fn ($p) => $p !== '')));

        if ($joined === '') {
            return '';
        }

        if (mb_strlen($joined) > self::MAX_CONTENT_CHARS) {
            $joined = mb_substr($joined, 0, self::MAX_CONTENT_CHARS);
        }

        return $joined;
    }

    /**
     * Render wizard_data values (which may be string|array|nested array)
     * into a flat string suitable for embedding.
     */
    private function stringifyValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $flat = [];
            array_walk_recursive($value, function ($v) use (&$flat) {
                if (is_scalar($v) && $v !== '' && $v !== null) {
                    $flat[] = (string) $v;
                }
            });

            return implode(', ', $flat);
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
