<?php

namespace App\Services;

use App\Models\VendorQuestionnaire;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 — Deterministic vendor risk scoring engine.
 *
 * Replaces the "user picks risk_level + score arbitrarily" manual mode.
 * Operator answers a structured questionnaire (12-15 questions per
 * category) and the score is computed by formula — no AI dependency.
 *
 * Formula:
 *   base = 50  (neutral — represents "average vendor")
 *   per question Q with answer A:
 *     contribution = weight(Q) × direction(Q) × answer_score(A)
 *   final_score = clamp(0, 100, base + sum(contributions))
 *
 * Where:
 *   - weight(Q):   1-10, relative importance per question
 *   - direction(Q): +1 (positive answer raises score) or -1 (positive
 *                   answer lowers score, e.g. "had a breach lately?")
 *   - answer_score(A): in -1.0..+1.0 from the question's answer_options
 *
 * Map score → risk_level:
 *   ≥ 85  low
 *   65-84 medium
 *   40-64 high
 *   < 40  critical
 *
 * Recommendations are auto-derived from the lowest-scoring questions
 * — operator gets a concrete list of what to ask the vendor to fix.
 */
class VendorRiskScoreService
{
    public function compute(string $category, array $answers, string $version = 'v1'): array
    {
        $questions = VendorQuestionnaire::query()
            ->where('category', $category)
            ->where('version', $version)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($questions->isEmpty()) {
            return [
                'score' => 50,
                'risk_level' => 'medium',
                'breakdown' => [],
                'recommendations' => [],
                'questions_total' => 0,
                'questions_answered' => 0,
                'error' => "No questions found for category={$category} version={$version}",
            ];
        }

        $breakdown = [];
        $totalContribution = 0.0;
        $answered = 0;
        $unanswered = [];

        foreach ($questions as $q) {
            $code = $q->question_code;
            $rawAnswer = $answers[$code] ?? null;

            if ($rawAnswer === null || $rawAnswer === '') {
                $unanswered[] = $code;
                $breakdown[] = [
                    'question_code' => $code,
                    'question_text' => $q->question_text,
                    'section' => $q->section,
                    'answer' => null,
                    'weight' => $q->weight,
                    'direction' => $q->direction,
                    'answer_score' => null,
                    'contribution' => 0,
                    'note' => 'unanswered — counted as 0 contribution',
                ];
                continue;
            }

            $answered++;
            $answerScore = $this->resolveAnswerScore($q, $rawAnswer);
            $contribution = $q->weight * $q->direction * $answerScore;
            $totalContribution += $contribution;

            $breakdown[] = [
                'question_code' => $code,
                'question_text' => $q->question_text,
                'section' => $q->section,
                'regulation_ref' => $q->regulation_ref,
                'answer' => $rawAnswer,
                'weight' => $q->weight,
                'direction' => $q->direction,
                'answer_score' => $answerScore,
                'contribution' => round($contribution, 2),
            ];
        }

        $finalScore = (int) round(max(0, min(100, 50 + $totalContribution)));

        return [
            'score' => $finalScore,
            'risk_level' => $this->scoreToRiskLevel($finalScore),
            'breakdown' => $breakdown,
            'recommendations' => $this->buildRecommendations($breakdown),
            'questions_total' => $questions->count(),
            'questions_answered' => $answered,
            'unanswered' => $unanswered,
            'category' => $category,
            'version' => $version,
        ];
    }

    /**
     * Resolve the per-answer score contribution from the question's
     * answer_options. Returns 0 if the answer doesn't match any option
     * (defensive — operator can't break scoring with bad input).
     */
    private function resolveAnswerScore(VendorQuestionnaire $q, mixed $rawAnswer): float
    {
        $options = is_array($q->answer_options) ? $q->answer_options : [];

        if ($q->answer_type === VendorQuestionnaire::ANSWER_SCALE_1_5) {
            // 1-5 scale — normalize to -1.0..+1.0 (1=-1, 3=0, 5=+1)
            $val = is_numeric($rawAnswer) ? (int) $rawAnswer : 3;
            $val = max(1, min(5, $val));
            return ($val - 3) / 2;
        }

        // yes_no and multi_choice both use answer_options[].value lookup
        $needle = is_string($rawAnswer) || is_numeric($rawAnswer) ? (string) $rawAnswer : '';
        foreach ($options as $opt) {
            if ((string) ($opt['value'] ?? '') === $needle) {
                return (float) ($opt['score_contribution'] ?? 0);
            }
        }
        // Unrecognized answer → 0 (defensive, log warning)
        Log::warning('VendorRiskScoreService unrecognized answer', [
            'question_code' => $q->question_code,
            'raw_answer' => $rawAnswer,
        ]);
        return 0;
    }

    public function scoreToRiskLevel(int $score): string
    {
        if ($score >= 85) return 'low';
        if ($score >= 65) return 'medium';
        if ($score >= 40) return 'high';
        return 'critical';
    }

    /**
     * Build human-readable recommendations from the worst-scoring
     * questions. We surface up to 5 items, focusing on:
     *   - High-weight questions answered "no" / negative
     *   - Negative-direction questions answered "yes" (the bad way)
     *
     * Each recommendation includes the question text + regulation_ref
     * so the operator can take it to the vendor verbatim.
     */
    private function buildRecommendations(array $breakdown): array
    {
        $negative = collect($breakdown)
            ->filter(fn ($b) => $b['contribution'] < 0)
            ->sortBy('contribution')   // most negative first
            ->take(5)
            ->values();

        return $negative->map(function ($b) {
            $text = "Minta vendor menyediakan: {$b['question_text']}";
            if (!empty($b['regulation_ref'])) {
                $text .= " (Regulasi: {$b['regulation_ref']})";
            }
            return [
                'question_code' => $b['question_code'],
                'description' => $text,
                'severity' => $b['contribution'] <= -5 ? 'critical' : ($b['contribution'] <= -2 ? 'high' : 'medium'),
                'regulation_ref' => $b['regulation_ref'] ?? null,
            ];
        })->all();
    }

    /**
     * Compute next assessment due date based on risk level — high-risk
     * vendors should be re-assessed more often.
     */
    public function nextDueDate(string $riskLevel): \Carbon\Carbon
    {
        return match ($riskLevel) {
            'critical' => now()->addMonths(3),
            'high'     => now()->addMonths(6),
            'medium'   => now()->addYear(),
            default    => now()->addYears(2),  // 'low'
        };
    }
}
