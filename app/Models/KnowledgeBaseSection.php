<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class KnowledgeBaseSection extends Model
{
    use HasUuids;

    protected $fillable = [
        'module_key', 'title', 'content', 'keywords', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Simple RAG: find relevant sections based on keyword matching.
     * Returns max 3 most relevant sections + always includes 'general'.
     */
    public static function findRelevant(string $query): array
    {
        $sections = self::where('is_active', true)->orderBy('sort_order')->get();
        $query = mb_strtolower($query);

        $scored = [];
        foreach ($sections as $section) {
            $keywords = array_map('trim', explode(',', mb_strtolower($section->keywords)));
            $score = 0;

            // Always include 'general'
            if ($section->module_key === 'general') {
                $score = 1;
            }

            foreach ($keywords as $keyword) {
                if (!empty($keyword) && str_contains($query, $keyword)) {
                    $score += 3; // Direct keyword match
                }
            }

            // Also check title match
            if (str_contains($query, mb_strtolower($section->title))) {
                $score += 5;
            }

            $scored[] = ['section' => $section, 'score' => $score];
        }

        // Sort by score descending
        usort($scored, fn($a, $b) => $b['score'] - $a['score']);

        // Take top 3 with score > 0
        $relevant = array_filter($scored, fn($s) => $s['score'] > 0);
        $relevant = array_slice($relevant, 0, 3);

        // If no matches, return general + first 2 sections
        if (count($relevant) === 0) {
            $relevant = array_slice($scored, 0, 2);
        }

        return array_map(fn($s) => $s['section'], $relevant);
    }
}
