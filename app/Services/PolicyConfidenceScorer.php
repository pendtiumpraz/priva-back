<?php

namespace App\Services;

/**
 * Per-section confidence for a generated policy.
 *
 * NOTE on metric choice: the design brief suggested "RAG hit rate / token prob",
 * but this repo has no vector-RAG layer and AiService does not expose token
 * log-probs, so neither signal exists. Confidence is therefore a deterministic
 * CONTENT-QUALITY heuristic: sections that still contain unfilled placeholders
 * (e.g. "[Lengkapi ...]") or are suspiciously short score low and are flagged
 * for manual review. The legal-safety disclaimer (boilerplate) is always high.
 */
class PolicyConfidenceScorer
{
    private const REVIEW_THRESHOLD = 0.6;

    /**
     * @param  array<int,mixed>  $sections
     * @return array{sections:array<int,array{index:int,type:string,confidence:float,needs_review:bool,flags:array<int,string>}>,low_confidence_count:int,overall:float}
     */
    public static function score(array $sections): array
    {
        $scored = [];
        $low = 0;
        $sum = 0.0;
        $n = 0;

        foreach ($sections as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            $type = (string) ($node['type'] ?? '');
            if (! in_array($type, ['paragraph', 'heading_1', 'heading_2', 'heading_3', 'list'], true)) {
                continue;
            }

            $text = self::nodeText($node);
            $confidence = 1.0;
            $flags = [];

            if (($node['role'] ?? null) === 'legal_disclaimer') {
                // Boilerplate — always trusted, never flagged.
                $scored[] = ['index' => $i, 'type' => $type, 'confidence' => 1.0, 'needs_review' => false, 'flags' => []];
                $sum += 1.0;
                $n++;

                continue;
            }

            if (preg_match('/\[[^\]]*\]/', $text)) {
                $confidence -= 0.5;
                $flags[] = 'placeholder';
            }
            if ($type === 'paragraph' && mb_strlen(trim($text)) < 40) {
                $confidence -= 0.25;
                $flags[] = 'too_short';
            }

            $confidence = round(max(0.0, min(1.0, $confidence)), 2);
            $needsReview = $confidence < self::REVIEW_THRESHOLD;

            $scored[] = [
                'index' => $i,
                'type' => $type,
                'confidence' => $confidence,
                'needs_review' => $needsReview,
                'flags' => $flags,
            ];

            if ($needsReview) {
                $low++;
            }
            $sum += $confidence;
            $n++;
        }

        return [
            'sections' => $scored,
            'low_confidence_count' => $low,
            'overall' => $n > 0 ? round($sum / $n, 2) : 1.0,
        ];
    }

    private static function nodeText(array $node): string
    {
        if (! empty($node['text']) && is_string($node['text'])) {
            return $node['text'];
        }
        if (is_array($node['items'] ?? null)) {
            return implode(' ', array_map('strval', $node['items']));
        }

        return '';
    }
}
