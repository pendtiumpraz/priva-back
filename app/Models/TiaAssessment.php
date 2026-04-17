<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class TiaAssessment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'title', 'linked_cross_border_id',
        'transfer_details', 'legal_framework', 'risk_assessment', 'supplementary_measures',
        'overall_risk_level', 'status',
        'wizard_data', 'created_by',
    ];

    protected $casts = [
        'transfer_details' => 'array',
        'legal_framework' => 'array',
        'risk_assessment' => 'array',
        'supplementary_measures' => 'array',
        'wizard_data' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function crossBorder()
    {
        return $this->belongsTo(CrossBorderTransfer::class, 'linked_cross_border_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
