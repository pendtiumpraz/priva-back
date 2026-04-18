<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class KnowledgeBaseSection extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'module_key', 'title', 'content', 'keywords', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope: sections visible to a given tenant — includes rows with this
     * org_id AND the shared system rows where org_id IS NULL.
     */
    public function scopeVisibleTo($query, ?string $orgId)
    {
        if (!$orgId) return $query;
        return $query->where(function ($q) use ($orgId) {
            $q->where('org_id', $orgId)->orWhereNull('org_id');
        });
    }

    /**
     * Simple RAG: find relevant sections based on keyword matching.
     * Returns max 3 most relevant + always includes 'general'. If $orgId is
     * provided, tenant-owned sections are considered alongside shared ones
     * and tenant-owned entries beat shared ones on tie-break.
     */
    public static function findRelevant(string $query, ?string $orgId = null): array
    {
        $sections = self::where('is_active', true)
            ->visibleTo($orgId)
            ->orderBy('sort_order')
            ->get();

        $query = mb_strtolower($query);
        $scored = [];
        foreach ($sections as $section) {
            $keywords = array_map('trim', explode(',', mb_strtolower((string) $section->keywords)));
            $score = 0;

            if ($section->module_key === 'general') {
                $score = 1;
            }

            foreach ($keywords as $keyword) {
                if (!empty($keyword) && str_contains($query, $keyword)) {
                    $score += 3;
                }
            }

            if (str_contains($query, mb_strtolower($section->title))) {
                $score += 5;
            }

            // Tenant-owned entries get a small boost so they override shared content.
            if ($section->org_id) $score += 1;

            $scored[] = ['section' => $section, 'score' => $score];
        }

        usort($scored, fn($a, $b) => $b['score'] - $a['score']);
        $relevant = array_filter($scored, fn($s) => $s['score'] > 0);
        $relevant = array_slice($relevant, 0, 3);

        if (count($relevant) === 0) {
            $relevant = array_slice($scored, 0, 2);
        }

        return array_map(fn($s) => $s['section'], $relevant);
    }
}
