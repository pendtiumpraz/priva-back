<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Policy Generator output. Multi-tenant scoped via BelongsToOrg.
 *
 * `audience`      ∈ { customer, employee, job_applicant, external }  (Phase 1: customer)
 * `language`      ∈ { id, en }                                       (Phase 1: id)
 * `status`        ∈ { draft, finalized }
 * `ai_output`     follows the canonical sections shape (shared with GeneratedDocument):
 *   { "title": "...", "metadata": {...}, "sections": [ { "type": "...", ... } ] }
 * `ai_metadata`   holds: coverage (15-element map), clause_sources (legal-safety trail),
 *                  provider/model, self_check result.
 */
class GeneratedPolicy extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const AUDIENCE_CUSTOMER = 'customer';

    public const AUDIENCE_EMPLOYEE = 'employee';

    public const AUDIENCE_JOB_APPLICANT = 'job_applicant';

    public const AUDIENCE_EXTERNAL = 'external';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_FINALIZED = 'finalized';

    protected $fillable = [
        'org_id', 'created_by',
        'audience', 'language', 'document_type', 'status', 'title',
        'wizard_inputs', 'ai_output', 'ai_metadata',
        'ai_provider', 'ai_model', 'credits_used',
    ];

    protected $casts = [
        'wizard_inputs' => 'array',
        'ai_output' => 'array',
        'ai_metadata' => 'array',
        'credits_used' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForOrg(Builder $q, string $orgId): Builder
    {
        return $q->where('org_id', $orgId);
    }
}
