<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Document Maker output. Multi-tenant scoped via BelongsToOrg.
 *
 * `kind` ∈ { policy, contract }
 * `document_type` ∈ schema-defined granular type (nda, msa, privacy_policy, ...)
 * `ai_output` follows the canonical sections shape:
 *   {
 *     "title": "...",
 *     "metadata": { ... },
 *     "sections": [
 *       { "type": "heading_1|heading_2|heading_3|paragraph|list|table|signature_block", ... }
 *     ]
 *   }
 */
class GeneratedDocument extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const KIND_POLICY = 'policy';

    public const KIND_CONTRACT = 'contract';

    protected $fillable = [
        'org_id', 'user_id',
        'kind', 'document_type', 'title',
        'wizard_inputs', 'ai_output',
        'ai_provider', 'ai_model', 'credits_used',
    ];

    protected $casts = [
        'wizard_inputs' => 'array',
        'ai_output' => 'array',
        'credits_used' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeForOrg(Builder $q, string $orgId): Builder
    {
        return $q->where('org_id', $orgId);
    }

    public function scopeOfKind(Builder $q, string $kind): Builder
    {
        return $q->where('kind', $kind);
    }
}
