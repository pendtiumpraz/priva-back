<?php

namespace App\Observers;

use App\Jobs\EmbedRecordJob;
use App\Models\VendorAssessment;
use Illuminate\Support\Facades\DB;

/**
 * Observer untuk auto-dispatch embedding job ketika VendorAssessment
 * dibuat/diubah.
 *
 * Note internal naming: "vendor" tetap dipakai sebagai source_type (sesuai
 * spec RAG_IMPLEMENTATION_SPEC.md §source_type enum). User-facing copy
 * tetap memakai "pihak ketiga" — observer ini tidak menyentuh UI string.
 *
 * Cross-tenant safety: dispatch job dengan $assessment->org_id (no hardcode).
 * Skipped entirely jika config('ai_embedding.enabled') === false.
 *
 * Soft-delete pattern: deleted_at di vector_embeddings di-set saat
 * VendorAssessment soft-deleted (jika model menggunakan SoftDeletes), di-unset
 * saat restored — vector rows tidak hard-delete supaya restore tetap
 * fungsional tanpa re-embed (hemat 1 kredit + 1 API call).
 */
class VendorEmbeddingObserver
{
    /**
     * Fields yang trigger re-embedding ketika berubah.
     */
    private const SIGNIFICANT_FIELDS = [
        'answers',
        'score',
        'risk_level',
        'status',
        'category',
        'recommendations',
        'notes',
    ];

    /**
     * Max content length to send for embedding (rough char budget).
     */
    private const MAX_CONTENT_CHARS = 3000;

    /**
     * Maximum number of answers (questions) yang di-include di content.
     * Ambil top-N pertama dari array answers — assumes question bank order
     * udah ditata sehingga material risk questions di depan.
     */
    private const MAX_ANSWERS_INCLUDED = 10;

    /**
     * Handle the VendorAssessment "saved" event (covers create & update).
     */
    public function saved(VendorAssessment $assessment): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        // Pada update, skip kalau tidak ada signifikan field yang berubah.
        // Untuk create (wasRecentlyCreated), wasChanged tetap true untuk
        // setiap dirty field, jadi check ini juga aman untuk create.
        if (! $assessment->wasRecentlyCreated && ! $assessment->wasChanged(self::SIGNIFICANT_FIELDS)) {
            return;
        }

        $content = $this->buildContent($assessment);
        if ($content === '') {
            return;
        }

        $metadata = [
            'vendor_id' => $assessment->vendor_id,
            'risk_score' => $assessment->score,
            'risk_level' => $assessment->risk_level,
            'status' => $assessment->status,
        ];

        EmbedRecordJob::dispatch(
            $assessment->org_id,
            'vendor',
            $assessment->id,
            $content,
            $metadata,
        );
    }

    /**
     * Handle the VendorAssessment "deleted" event.
     *
     * Vector rows tidak di-hard-delete — hanya tandai deleted_at supaya
     * restore() bisa balikin tanpa re-embed.
     */
    public function deleted(VendorAssessment $assessment): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        DB::table('vector_embeddings')
            ->where('org_id', $assessment->org_id)
            ->where('source_type', 'vendor')
            ->where('source_id', $assessment->id)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    /**
     * Handle the VendorAssessment "restored" event — un-set deleted_at on
     * matching vector rows. Org scoping retained as defense-in-depth.
     */
    public function restored(VendorAssessment $assessment): void
    {
        if (config('ai_embedding.enabled') === false) {
            return;
        }

        DB::table('vector_embeddings')
            ->where('org_id', $assessment->org_id)
            ->where('source_type', 'vendor')
            ->where('source_id', $assessment->id)
            ->whereNotNull('deleted_at')
            ->update(['deleted_at' => null]);
    }

    /**
     * Compose content string from VendorAssessment + related vendor data.
     * Trim ke ~3000 char untuk fit embedding model context.
     */
    private function buildContent(VendorAssessment $assessment): string
    {
        $parts = [];

        // Vendor header (via relation, jika sudah loaded atau bisa di-fetch).
        $vendor = $assessment->vendor;
        if ($vendor) {
            if (! empty($vendor->name)) {
                $parts[] = 'Pihak Ketiga: '.$vendor->name;
            }
            if (! empty($vendor->category)) {
                $parts[] = 'Kategori: '.$vendor->category;
            }
            if (! empty($vendor->type)) {
                $parts[] = 'Tipe: '.$vendor->type;
            }
            if (! empty($vendor->description)) {
                $parts[] = 'Deskripsi: '.$vendor->description;
            }
        }

        if (! empty($assessment->category)) {
            $parts[] = 'Kategori Asesmen: '.$assessment->category;
        }
        if ($assessment->score !== null) {
            $parts[] = 'Risk Score: '.$assessment->score;
        }
        if (! empty($assessment->risk_level)) {
            $parts[] = 'Risk Level: '.$assessment->risk_level;
        }
        if (! empty($assessment->status)) {
            $parts[] = 'Status: '.$assessment->status;
        }
        if (! empty($assessment->notes)) {
            $parts[] = 'Catatan: '.$assessment->notes;
        }
        if (! empty($assessment->recommendations)) {
            $parts[] = 'Rekomendasi: '.$this->stringifyValue($assessment->recommendations);
        }

        // Ringkasan jawaban: ambil top-N pertanyaan material.
        $answers = $assessment->answers ?? [];
        if (is_array($answers) && ! empty($answers)) {
            $summary = $this->summarizeAnswers($answers, self::MAX_ANSWERS_INCLUDED);
            if ($summary !== '') {
                $parts[] = 'Ringkasan Jawaban: '.$summary;
            }
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
     * Bangun ringkasan dari array answers. Mendukung beberapa shape umum:
     *  - [{question_id, question, answer, score?}]
     *  - [question_id => answer_string|answer_array]
     *  - [{q, a}], [{label, value}]
     *
     * Ambil max $limit entries pertama; setiap entry direnders sebagai
     * "Q: ... | A: ...".
     */
    private function summarizeAnswers(array $answers, int $limit): string
    {
        $lines = [];
        $count = 0;

        foreach ($answers as $key => $value) {
            if ($count >= $limit) {
                break;
            }

            $question = null;
            $answer = null;

            if (is_array($value)) {
                $question = $value['question'] ?? $value['q'] ?? $value['label'] ?? $value['question_text'] ?? (is_string($key) ? $key : null);
                $answer = $value['answer'] ?? $value['a'] ?? $value['value'] ?? $value['response'] ?? null;

                // Sertakan score per-pertanyaan kalau ada (material untuk risk context).
                if (isset($value['score']) && is_scalar($value['score'])) {
                    $answer = ($answer === null ? '' : $this->stringifyValue($answer).' ').'[score='.$value['score'].']';
                }

                if ($answer === null) {
                    // Fallback: flatten the whole entry.
                    $answer = $this->stringifyValue($value);
                }
            } else {
                $question = is_string($key) ? $key : null;
                $answer = $value;
            }

            $qStr = $question !== null ? $this->stringifyValue($question) : '';
            $aStr = $answer !== null ? $this->stringifyValue($answer) : '';

            if ($qStr === '' && $aStr === '') {
                continue;
            }

            if ($qStr !== '' && $aStr !== '') {
                $lines[] = 'Q: '.$qStr.' | A: '.$aStr;
            } elseif ($aStr !== '') {
                $lines[] = $aStr;
            } else {
                $lines[] = $qStr;
            }

            $count++;
        }

        return implode(' ; ', $lines);
    }

    /**
     * Render nilai (string|array|nested array|scalar) jadi flat string
     * yang aman dimasukkan ke content embedding.
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
