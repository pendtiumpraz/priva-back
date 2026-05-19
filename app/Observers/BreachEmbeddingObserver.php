<?php

namespace App\Observers;

use App\Jobs\EmbedRecordJob;
use App\Models\BreachIncident;
use App\Models\VectorEmbedding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * BreachEmbeddingObserver
 *
 * Mirrors the embedding lifecycle of other domain observers (RoPA, DPIA, ...)
 * for the BreachIncident model. Whenever a tracked field changes the observer
 * builds a compact textual representation of the incident and queues an
 * {@see EmbedRecordJob} so the vector store stays in sync.
 *
 * Cross-tenant safety: org_id is always taken from the model instance, never
 * from request / session context, so background jobs cannot leak across orgs.
 */
class BreachEmbeddingObserver
{
    /**
     * Fields that materially affect the embedding content. Changing any of
     * these triggers a re-embed; other field changes (timestamps, FK ids,
     * encrypted PII like pic_name) are ignored to avoid wasted work.
     *
     * @var array<int, string>
     */
    private const TRACKED_FIELDS = [
        'title',
        'description',
        'incident_code',
        'severity',
        'status',
        'containment_checklist',
        'timeline_log',
    ];

    /**
     * Maximum length (chars) of the content payload sent to the embedder.
     * bge-m3 supports up to 8192 tokens, but we keep things tight to control
     * cost and reduce noise in retrieval.
     */
    private const MAX_CONTENT_CHARS = 3000;

    /**
     * Number of most-recent timeline entries summarised into the embedding.
     */
    private const TIMELINE_RECENT_LIMIT = 5;

    /**
     * Handle the BreachIncident "saved" event (covers created + updated).
     *
     * @return false|null Returning false aborts further observer chain — used
     *                    when there is nothing to re-embed.
     */
    public function saved(BreachIncident $breach)
    {
        if (config('ai_embedding.enabled') === false) {
            return false;
        }

        // On a pure update, only proceed when a tracked field actually changed.
        // `wasChanged` is empty on a fresh create, so we let creates through.
        if ($breach->wasRecentlyCreated === false && ! $breach->wasChanged(self::TRACKED_FIELDS)) {
            return false;
        }

        if (empty($breach->org_id) || empty($breach->id)) {
            return false;
        }

        $content = $this->buildContent($breach);
        if ($content === '') {
            return false;
        }

        $metadata = [
            'incident_code' => $breach->incident_code,
            'severity' => $breach->severity,
            'status' => $breach->status,
            'notification_deadline' => $breach->notification_deadline instanceof Carbon
                ? $breach->notification_deadline->toIso8601String()
                : $breach->notification_deadline,
        ];

        EmbedRecordJob::dispatch(
            (string) $breach->org_id,
            'breach',
            (string) $breach->id,
            $content,
            $metadata,
        );

        return null;
    }

    /**
     * Soft-delete companion: tombstone any vector rows linked to this breach
     * so they stop appearing in semantic search results. We avoid touching
     * the table when it does not exist (e.g. SQLite test env without pgvector).
     */
    public function deleted(BreachIncident $breach): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        if (empty($breach->org_id) || empty($breach->id)) {
            return;
        }

        try {
            if (! Schema::hasTable('vector_embeddings')) {
                return;
            }

            // Defensive: filter by org_id AND source — cross-tenant safety.
            VectorEmbedding::query()
                ->where('org_id', $breach->org_id)
                ->where('source_type', 'breach')
                ->where('source_id', $breach->id)
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);
        } catch (Throwable $e) {
            // Never let an embedding bookkeeping failure cascade back into the
            // domain transaction. Log via the default channel for ops follow-up.
            report($e);
        }
    }

    /**
     * Restore companion: un-tombstone vector rows when the breach itself is
     * restored. We do not regenerate the embedding here; the next saved()
     * (or backfill command) will refresh if content drifted.
     */
    public function restored(BreachIncident $breach): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        if (empty($breach->org_id) || empty($breach->id)) {
            return;
        }

        try {
            if (! Schema::hasTable('vector_embeddings')) {
                return;
            }

            VectorEmbedding::withTrashed()
                ->where('org_id', $breach->org_id)
                ->where('source_type', 'breach')
                ->where('source_id', $breach->id)
                ->whereNotNull('deleted_at')
                ->update(['deleted_at' => null]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Compose a compact textual representation of the incident suitable for
     * embedding. We intentionally keep it Bahasa Indonesia formal because the
     * AI tools answer tenants in that register.
     */
    private function buildContent(BreachIncident $breach): string
    {
        $lines = [];

        if (! empty($breach->incident_code)) {
            $lines[] = 'Kode Insiden: ' . $breach->incident_code;
        }
        if (! empty($breach->title)) {
            $lines[] = 'Judul: ' . $breach->title;
        }
        if (! empty($breach->severity)) {
            $lines[] = 'Tingkat Keparahan: ' . $breach->severity;
        }
        if (! empty($breach->status)) {
            $lines[] = 'Status: ' . $breach->status;
        }
        if (! empty($breach->description)) {
            $lines[] = 'Deskripsi: ' . $breach->description;
        }

        $checklistSummary = $this->summariseChecklist($breach->containment_checklist);
        if ($checklistSummary !== '') {
            $lines[] = 'Langkah Penanggulangan: ' . $checklistSummary;
        }

        $timelineSummary = $this->summariseTimeline($breach->timeline_log);
        if ($timelineSummary !== '') {
            $lines[] = 'Riwayat Terbaru: ' . $timelineSummary;
        }

        $content = trim(implode("\n", $lines));

        if (mb_strlen($content) > self::MAX_CONTENT_CHARS) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_CHARS);
        }

        return $content;
    }

    /**
     * Flatten containment_checklist into "step - status" pairs. The structure
     * varies by template (templated containment vs. ad-hoc), so we coerce
     * defensively and skip anything we cannot read.
     *
     * @param  mixed  $checklist
     */
    private function summariseChecklist($checklist): string
    {
        if (! is_array($checklist) || empty($checklist)) {
            return '';
        }

        $parts = [];
        foreach ($checklist as $item) {
            if (! is_array($item)) {
                if (is_string($item) && $item !== '') {
                    $parts[] = $item;
                }
                continue;
            }

            $label = $item['label']
                ?? $item['name']
                ?? $item['step']
                ?? $item['title']
                ?? null;
            $status = $item['status']
                ?? ($item['done'] ?? null)
                ?? ($item['completed'] ?? null);

            if (is_bool($status)) {
                $status = $status ? 'selesai' : 'belum';
            }

            if (! is_string($label) || $label === '') {
                continue;
            }

            $parts[] = is_string($status) && $status !== ''
                ? sprintf('%s (%s)', $label, $status)
                : $label;
        }

        return implode('; ', $parts);
    }

    /**
     * Pick the last N entries from timeline_log (chronological tail) and
     * render them as "yyyy-mm-dd hh:mm — note" lines.
     *
     * @param  mixed  $timeline
     */
    private function summariseTimeline($timeline): string
    {
        if (! is_array($timeline) || empty($timeline)) {
            return '';
        }

        $entries = array_slice($timeline, -self::TIMELINE_RECENT_LIMIT);
        $parts = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                if (is_string($entry) && $entry !== '') {
                    $parts[] = $entry;
                }
                continue;
            }

            $timestamp = $entry['at']
                ?? $entry['timestamp']
                ?? $entry['time']
                ?? $entry['created_at']
                ?? null;
            $note = $entry['note']
                ?? $entry['message']
                ?? $entry['event']
                ?? $entry['description']
                ?? null;

            if (! is_string($note) || $note === '') {
                continue;
            }

            $parts[] = is_string($timestamp) && $timestamp !== ''
                ? sprintf('[%s] %s', $timestamp, $note)
                : $note;
        }

        return implode(' | ', $parts);
    }
}
