<?php

namespace App\Observers;

use App\Jobs\EmbedRecordJob;
use App\Models\Dpia;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DpiaEmbeddingObserver
{
    /**
     * Fields whose change should trigger re-embedding of the DPIA record.
     */
    private const TRACKED_FIELDS = [
        'nama_pemrosesan',
        'description',
        'wizard_data',
        'risk_level',
        'status',
    ];

    /**
     * Max characters to send to the embedding provider per record.
     * bge-m3 token budget ~8K; ~3000 chars leaves headroom for metadata + prompt.
     */
    private const CONTENT_CHAR_LIMIT = 3000;

    /**
     * Fire on create + update. Skips when nothing meaningful changed.
     */
    public function saved(Dpia $dpia): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        // Skip queue dispatch when no tracked field changed (purely auxiliary
        // updates like progress recompute should not burn embedding credits).
        // wasRecentlyCreated bypasses the wasChanged check — new records always
        // embed once.
        if (! $dpia->wasRecentlyCreated && ! $dpia->wasChanged(self::TRACKED_FIELDS)) {
            return;
        }

        if (empty($dpia->org_id) || empty($dpia->id)) {
            return;
        }

        $content = $this->buildContent($dpia);

        if (trim($content) === '') {
            return;
        }

        $metadata = $this->buildMetadata($dpia);

        EmbedRecordJob::dispatch(
            (string) $dpia->org_id,
            'dpia',
            (string) $dpia->id,
            $content,
            $metadata,
        );
    }

    /**
     * Soft-delete: mark the corresponding vector_embeddings rows as deleted so
     * VectorSearchService (which filters deleted_at IS NULL) excludes them.
     */
    public function deleted(Dpia $dpia): void
    {
        if (! $this->vectorTableAvailable()) {
            return;
        }

        if (empty($dpia->org_id) || empty($dpia->id)) {
            return;
        }

        try {
            DB::table('vector_embeddings')
                ->where('org_id', $dpia->org_id)
                ->where('source_type', 'dpia')
                ->where('source_id', $dpia->id)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        } catch (Throwable $e) {
            // Vector table may be absent in test/dev (SQLite). Swallow silently.
        }
    }

    /**
     * Restore: un-set deleted_at on previously soft-deleted embeddings so the
     * record is searchable again without burning credits on re-embedding.
     */
    public function restored(Dpia $dpia): void
    {
        if (! $this->vectorTableAvailable()) {
            return;
        }

        if (empty($dpia->org_id) || empty($dpia->id)) {
            return;
        }

        try {
            DB::table('vector_embeddings')
                ->where('org_id', $dpia->org_id)
                ->where('source_type', 'dpia')
                ->where('source_id', $dpia->id)
                ->whereNotNull('deleted_at')
                ->update([
                    'deleted_at' => null,
                    'updated_at' => Carbon::now(),
                ]);
        } catch (Throwable $e) {
            // No-op when table missing.
        }
    }

    /**
     * Assemble the text body that will be embedded. Order matters: most
     * identifying fields first so that truncation preserves signal.
     */
    private function buildContent(Dpia $dpia): string
    {
        $parts = [];

        if (! empty($dpia->registration_number)) {
            $parts[] = 'Nomor Registrasi: '.$dpia->registration_number;
        }

        $namaPemrosesan = $this->resolveNamaPemrosesan($dpia);
        if ($namaPemrosesan !== '') {
            $parts[] = 'Nama Pemrosesan: '.$namaPemrosesan;
        }

        if (! empty($dpia->description)) {
            $parts[] = 'Deskripsi: '.$dpia->description;
        }

        if (! empty($dpia->risk_level)) {
            $parts[] = 'Tingkat Risiko: '.$dpia->risk_level;
        }

        if (! empty($dpia->status)) {
            $parts[] = 'Status: '.$dpia->status;
        }

        $wizard = is_array($dpia->wizard_data) ? $dpia->wizard_data : [];

        $informasi = $this->flattenSection($wizard['informasi_dpia'] ?? null);
        if ($informasi !== '') {
            $parts[] = 'Informasi DPIA: '.$informasi;
        }

        $ropaSummary = $this->extractRopaSummary($wizard['koneksi_ropa'] ?? null);
        if ($ropaSummary !== '') {
            $parts[] = 'Koneksi RoPA: '.$ropaSummary;
        }

        $riskEvents = $this->extractRiskEvents($wizard['potensi_risiko'] ?? null);
        if ($riskEvents !== '') {
            $parts[] = 'Potensi Risiko: '.$riskEvents;
        }

        $content = implode("\n", $parts);

        if (mb_strlen($content) > self::CONTENT_CHAR_LIMIT) {
            $content = mb_substr($content, 0, self::CONTENT_CHAR_LIMIT);
        }

        return $content;
    }

    /**
     * Metadata stored alongside the embedding row (JSONB). Keep keys flat so
     * the retrieval layer can filter without parsing.
     */
    private function buildMetadata(Dpia $dpia): array
    {
        $metadata = [
            'registration_number' => $dpia->registration_number,
            'risk_level' => $dpia->risk_level,
            'status' => $dpia->status,
        ];

        if (! empty($dpia->ropa_id)) {
            $metadata['ropa_id'] = (string) $dpia->ropa_id;
        }

        return array_filter(
            $metadata,
            static fn ($value) => $value !== null && $value !== ''
        );
    }

    /**
     * DPIA model does not expose `nama_pemrosesan` as a column. The canonical
     * source is wizard_data.informasi_dpia.nama_pemrosesan (set during the
     * "Informasi DPIA" wizard step), with attribute fallback for forward
     * compatibility.
     */
    private function resolveNamaPemrosesan(Dpia $dpia): string
    {
        $attr = $dpia->getAttribute('nama_pemrosesan');
        if (is_string($attr) && $attr !== '') {
            return $attr;
        }

        $wizard = is_array($dpia->wizard_data) ? $dpia->wizard_data : [];
        $informasi = $wizard['informasi_dpia'] ?? null;

        if (is_array($informasi)) {
            foreach (['nama_pemrosesan', 'nama', 'judul', 'title'] as $key) {
                if (! empty($informasi[$key]) && is_string($informasi[$key])) {
                    return $informasi[$key];
                }
            }
        }

        return '';
    }

    /**
     * Flatten arbitrary nested array/scalar payload into a "k: v; k: v" string.
     */
    private function flattenSection(mixed $section): string
    {
        if ($section === null) {
            return '';
        }

        if (is_string($section)) {
            return trim($section);
        }

        if (! is_array($section)) {
            return '';
        }

        $pieces = [];
        foreach ($section as $key => $value) {
            if (is_array($value)) {
                $inner = $this->flattenSection($value);
                if ($inner !== '') {
                    $pieces[] = (is_string($key) ? $key.': ' : '').$inner;
                }
            } elseif (is_scalar($value) && $value !== '') {
                $pieces[] = (is_string($key) ? $key.': ' : '').(string) $value;
            }
        }

        return implode('; ', $pieces);
    }

    /**
     * Extract a compact summary of connected RoPAs (registration numbers +
     * names) from the wizard payload.
     */
    private function extractRopaSummary(mixed $section): string
    {
        if (! is_array($section)) {
            return '';
        }

        // Prefer pre-computed summary if the wizard wrote one.
        if (! empty($section['ropa_summary']) && is_string($section['ropa_summary'])) {
            return trim($section['ropa_summary']);
        }

        $items = [];

        $connected = $section['connected_ropas'] ?? $section['ropas'] ?? null;
        if (is_array($connected)) {
            foreach ($connected as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $label = $entry['registration_number']
                    ?? $entry['ropa_code']
                    ?? $entry['code']
                    ?? null;
                $name = $entry['nama_pemrosesan']
                    ?? $entry['name']
                    ?? $entry['title']
                    ?? null;

                $piece = trim(implode(' - ', array_filter([$label, $name])));
                if ($piece !== '') {
                    $items[] = $piece;
                }
            }
        }

        if (empty($items)) {
            return $this->flattenSection($section);
        }

        return implode('; ', $items);
    }

    /**
     * Extract risk events from the "potensi_risiko" wizard section. We keep
     * only the event/description fields to bound the size — full RACI matrix
     * and per-event scoring aren't useful for semantic recall.
     */
    private function extractRiskEvents(mixed $section): string
    {
        if (! is_array($section)) {
            return '';
        }

        $events = $section['risk_events']
            ?? $section['events']
            ?? $section['risks']
            ?? null;

        if (! is_array($events)) {
            return $this->flattenSection($section);
        }

        $pieces = [];
        foreach ($events as $event) {
            if (! is_array($event)) {
                if (is_scalar($event) && $event !== '') {
                    $pieces[] = (string) $event;
                }
                continue;
            }

            $label = $event['category']
                ?? $event['kategori']
                ?? $event['name']
                ?? null;
            $desc = $event['description']
                ?? $event['deskripsi']
                ?? $event['event']
                ?? $event['risk']
                ?? null;
            $level = $event['level']
                ?? $event['risk_level']
                ?? $event['severity']
                ?? null;

            $piece = trim(implode(' - ', array_filter([$label, $desc, $level])));
            if ($piece !== '') {
                $pieces[] = $piece;
            }
        }

        return implode('; ', $pieces);
    }

    /**
     * Guard against running soft-delete UPDATE on environments where the
     * vector_embeddings table doesn't exist (SQLite tests, fresh installs).
     */
    private function vectorTableAvailable(): bool
    {
        try {
            return Schema::hasTable('vector_embeddings');
        } catch (Throwable $e) {
            return false;
        }
    }
}
