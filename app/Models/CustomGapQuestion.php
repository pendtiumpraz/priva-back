<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sprint B2: Custom Gap Assessment Question
 * Organizations can create custom questions on top of the default template.
 */
class CustomGapQuestion extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'regulation_code',
        'category',
        'subcategory',
        'question',
        'explanation',
        'recommendation',
        'weight',
        'article',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'weight' => 'float',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    // =============================================
    // Relationships
    // =============================================

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    // =============================================
    // Scopes
    // =============================================

    public function scopeForOrg($query, string $orgId)
    {
        return $query->where('org_id', $orgId);
    }

    public function scopeForRegulation($query, string $code)
    {
        return $query->where('regulation_code', $code);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Convert this model to the same format as the default question bank
     * so they can be seamlessly merged in the controller.
     */
    public function toQuestionFormat(): array
    {
        return [
            'id' => 'custom_' . $this->id,
            'category' => $this->category,
            'subcategory' => $this->subcategory ?? '',
            'article' => $this->article ?? '-',
            'weight' => $this->weight,
            'question' => $this->question,
            'explanation' => $this->explanation ?? '',
            'recommendation' => $this->recommendation,
            'is_custom' => true,
            'custom_id' => $this->id,
        ];
    }
}
