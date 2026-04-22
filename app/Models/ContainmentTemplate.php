<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContainmentTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'case_type', 'label', 'description',
        'steps', 'is_default', 'is_system', 'usage_count', 'created_by',
    ];

    protected $casts = [
        'steps' => 'array',
        'is_default' => 'boolean',
        'is_system' => 'boolean',
        'usage_count' => 'integer',
    ];

    /**
     * Map each step's `category` to a default RACI assignment. Tenants
     * override this per-breach via the containment_checklist payload.
     * Values reference ROLES (not user IDs) — runtime assignee picker
     * lets admin pick specific users within each role.
     *
     * R = Responsible (executes the step), A = Accountable (signs off),
     * C = Consulted (advice needed), I = Informed (kept in the loop).
     */
    public const CATEGORY_RACI = [
        'isolation' =>       ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo'],           'informed' => ['direksi']],
        'forensics' =>       ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo', 'legal'],  'informed' => ['direksi']],
        'analysis' =>        ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo'],           'informed' => []],
        'assessment' =>      ['responsible' => 'dpo',         'accountable' => 'ciso',   'consulted' => ['legal'],         'informed' => ['direksi']],
        'communication' =>   ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => ['pr', 'legal'],   'informed' => ['all-staff']],
        'legal' =>           ['responsible' => 'legal',       'accountable' => 'direksi','consulted' => ['dpo'],           'informed' => ['ciso']],
        'remediation' =>     ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['dpo'],           'informed' => []],
        'eradication' =>     ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],                'informed' => []],
        'recovery' =>        ['responsible' => 'it-operations','accountable'=> 'ciso',   'consulted' => [],                'informed' => ['dpo']],
        'monitoring' =>      ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => [],                'informed' => ['dpo']],
        'prevention' =>      ['responsible' => 'ciso',        'accountable' => 'direksi','consulted' => ['dpo'],           'informed' => ['all-staff']],
        'investigation' =>   ['responsible' => 'it-security', 'accountable' => 'ciso',   'consulted' => ['legal', 'hr'],   'informed' => ['direksi']],
        'closure' =>         ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => ['ciso'],          'informed' => ['all-staff']],
        'administration' =>  ['responsible' => 'dpo',         'accountable' => 'direksi','consulted' => [],                'informed' => []],
        'general' =>         ['responsible' => 'dpo',         'accountable' => 'ciso',   'consulted' => [],                'informed' => []],
    ];

    /** Flip step schema into checklist state (per-step rich object). */
    public function buildChecklistState(?array $tenantRaciOverride = null): array
    {
        $out = [];
        foreach ($this->steps as $step) {
            $key = $step['key'] ?? $step['label'];
            $category = $step['category'] ?? 'general';
            $defaultRaci = self::CATEGORY_RACI[$category] ?? self::CATEGORY_RACI['general'];
            // Tenant matrix override if provided (keyed by category).
            if ($tenantRaciOverride && isset($tenantRaciOverride[$category])) {
                $defaultRaci = array_merge($defaultRaci, $tenantRaciOverride[$category]);
            }
            $out[$key] = [
                'label' => $step['label'],
                'category' => $category,
                'requires_evidence' => (bool) ($step['requires_evidence'] ?? false),
                'hint' => $step['hint'] ?? null,
                // Per-step RACI — editable at runtime; falls back to category default.
                'raci' => $defaultRaci,
                // Runtime assignee (user IDs or group IDs). Admin picks specific
                // person within the "responsible" role. Notification fires to
                // assignee_user_id on assignment or step flip.
                'assignee_user_id' => null,
                'assignee_group' => null,  // optional role/department tag
                // State tracking
                'done' => false,
                'notes' => null,
                'evidence_files' => [],
                'completed_by' => null,
                'completed_at' => null,
            ];
        }
        return $out;
    }

    /**
     * Pick best template for a case type. Tenant custom templates take
     * precedence over system defaults. Returns null if no template exists.
     */
    public static function forCase(?string $orgId, string $caseType): ?self
    {
        return self::where('case_type', $caseType)
            ->where(function ($q) use ($orgId) {
                $q->where('org_id', $orgId)->orWhereNull('org_id');
            })
            ->orderByRaw('(org_id IS NULL) asc') // tenant first (null-org last)
            ->orderByDesc('is_default')
            ->first();
    }
}
