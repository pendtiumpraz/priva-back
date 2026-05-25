<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class KnowledgeBaseSection extends Model
{
    use HasUuids;

    protected $fillable = [
        'org_id', 'module_key', 'title', 'content', 'summary', 'keywords',
        'feature_tags', 'category', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope: sections visible to a given tenant — includes rows with this
     * org_id AND the shared system rows where org_id IS NULL.
     *
     * P1 security fix: kalau $orgId null (SuperAdmin atau caller tanpa
     * org context), HANYA return shared rows (org_id IS NULL). Sebelumnya
     * return semua (no filter) yang berisiko cross-tenant leak — SuperAdmin
     * yang minta KB context bisa accidentally include private tenant KB
     * di prompt mereka.
     *
     * Untuk eksplisit cross-tenant view (mis. SuperAdmin manage all KB),
     * caller harus pakai `withoutGlobalScope` atau query langsung tanpa scope.
     */
    public function scopeVisibleTo($query, ?string $orgId)
    {
        if (!$orgId) {
            // Fail-closed: cuma shared platform-level KB yang visible
            return $query->whereNull('org_id');
        }
        return $query->where(function ($q) use ($orgId) {
            $q->where('org_id', $orgId)->orWhereNull('org_id');
        });
    }

    /**
     * Simple RAG: find relevant sections based on keyword matching.
     * Returns max $limit most relevant + always includes 'general'. If
     * $orgId is provided, tenant-owned sections are considered alongside
     * shared ones and tenant-owned entries beat shared ones on tie-break.
     *
     * @param  string       $query       Free-text user query
     * @param  string|null  $orgId       Tenant scope (null = system-only)
     * @param  string|null  $featureTag  Filter to sections tagged for this AI feature
     *                                   (e.g. 'ropa_autofill', 'contract_review')
     * @param  int          $limit       Max sections returned (default 3)
     */
    public static function findRelevant(
        string $query,
        ?string $orgId = null,
        ?string $featureTag = null,
        int $limit = 3
    ): array {
        $q = self::where('is_active', true)->visibleTo($orgId)->orderBy('sort_order');

        // Feature-tag filter — only consider sections relevant to this AI feature
        if ($featureTag) {
            $q->where(function ($sub) use ($featureTag) {
                $sub->where('feature_tags', 'like', "%{$featureTag}%")
                    ->orWhereNull('feature_tags')
                    ->orWhere('module_key', 'general');
            });
        }

        $sections = $q->get();
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

            // Feature-tagged sections get a boost when filter is active
            if ($featureTag && str_contains((string) $section->feature_tags, $featureTag)) {
                $score += 2;
            }

            $scored[] = ['section' => $section, 'score' => $score];
        }

        usort($scored, fn($a, $b) => $b['score'] - $a['score']);
        $relevant = array_filter($scored, fn($s) => $s['score'] > 0);
        $relevant = array_slice($relevant, 0, $limit);

        if (count($relevant) === 0) {
            $relevant = array_slice($scored, 0, min(2, $limit));
        }

        return array_map(fn($s) => $s['section'], $relevant);
    }

    /**
     * Build grounding context string ready to inject into LLM system prompt.
     *
     * Token budget modes:
     *   - 'summary'  : use only summary field (~50-200 tokens per section)
     *                  Best for tight-budget features (tool calling, small models)
     *   - 'full'     : use full content (~500-2000 tokens per section)
     *                  Best for chat & long-context models
     *   - 'adaptive' : prefer full, fallback to summary if content missing
     *
     * Returns markdown-formatted context for direct prompt injection.
     */
    public static function buildContext(
        string $query,
        ?string $orgId = null,
        ?string $featureTag = null,
        string $mode = 'adaptive',
        int $limit = 3
    ): string {
        $sections = self::findRelevant($query, $orgId, $featureTag, $limit);
        if (empty($sections)) return '';

        $out = "## Knowledge Base Context (grounded from Privasimu KB)\n\n";
        foreach ($sections as $s) {
            $out .= "### {$s->title}\n\n";
            $body = match ($mode) {
                'summary' => (string) ($s->summary ?: $s->content),
                'full'    => (string) $s->content,
                default   => (string) ($s->content ?: $s->summary),
            };
            $out .= trim($body) . "\n\n---\n\n";
        }
        return $out;
    }
}
