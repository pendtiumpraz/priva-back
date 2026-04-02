<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrossBorderTransfer extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'destination_country',
        'destination_entity',
        'transfer_purpose',
        'data_categories',
        'legal_basis',
        'safeguards',
        'status',
        'tia_summary',
        'tia_answers',
        'risk_score',
        'risk_level',
        'approved_at',
        'review_due_at',
        'notes',
    ];

    protected $casts = [
        'data_categories' => 'array',
        'safeguards' => 'array',
        'tia_answers' => 'array',
        'risk_score' => 'integer',
        'approved_at' => 'date',
        'review_due_at' => 'date',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
