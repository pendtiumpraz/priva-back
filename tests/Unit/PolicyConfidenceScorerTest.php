<?php

namespace Tests\Unit;

use App\Services\PolicyConfidenceScorer;
use Tests\TestCase;

/**
 * Per-section confidence heuristic. No token-probability / RAG-hit signal exists
 * in the repo, so confidence is derived deterministically from content quality
 * markers (unfilled placeholders, too-short prose). Low confidence → review flag.
 */
class PolicyConfidenceScorerTest extends TestCase
{
    public function test_placeholder_section_is_low_confidence_and_flagged(): void
    {
        $sections = [
            ['type' => 'paragraph', 'text' => 'Kontak DPO kami adalah [Lengkapi kontak DPO] dan dapat dihubungi kapan saja.'],
        ];

        $result = PolicyConfidenceScorer::score($sections);
        $sec = $result['sections'][0];

        $this->assertLessThan(0.6, $sec['confidence']);
        $this->assertTrue($sec['needs_review']);
        $this->assertContains('placeholder', $sec['flags']);
        $this->assertSame(1, $result['low_confidence_count']);
    }

    public function test_substantive_cited_paragraph_is_high_confidence(): void
    {
        $sections = [
            ['type' => 'paragraph', 'text' => 'Dasar hukum pemrosesan data pribadi Anda adalah pelaksanaan kontrak sebagaimana diatur dalam Pasal 20 UU PDP, untuk penyediaan layanan.'],
        ];

        $result = PolicyConfidenceScorer::score($sections);

        $this->assertGreaterThanOrEqual(0.8, $result['sections'][0]['confidence']);
        $this->assertFalse($result['sections'][0]['needs_review']);
    }

    public function test_legal_disclaimer_node_is_always_high_confidence(): void
    {
        $sections = [
            ['type' => 'paragraph', 'role' => 'legal_disclaimer', 'text' => 'Template ini bukan nasihat hukum [review].'],
        ];

        $result = PolicyConfidenceScorer::score($sections);

        // Boilerplate disclaimer must not be flagged even though it contains brackets.
        $this->assertSame(1.0, $result['sections'][0]['confidence']);
        $this->assertFalse($result['sections'][0]['needs_review']);
    }

    public function test_overall_score_and_low_count_aggregate(): void
    {
        $sections = [
            ['type' => 'paragraph', 'text' => 'Paragraf yang cukup panjang dan jelas menjelaskan tujuan pemrosesan data pribadi pengguna layanan kami.'],
            ['type' => 'paragraph', 'text' => 'Isi [TBD].'],
            ['type' => 'heading_1', 'text' => '1. Pendahuluan'],
        ];

        $result = PolicyConfidenceScorer::score($sections);

        $this->assertGreaterThanOrEqual(1, $result['low_confidence_count']);
        $this->assertIsFloat($result['overall']);
        $this->assertGreaterThan(0, $result['overall']);
        $this->assertLessThanOrEqual(1.0, $result['overall']);
    }
}
