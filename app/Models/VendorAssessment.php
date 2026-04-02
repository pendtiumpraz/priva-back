<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VendorAssessment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'vendor_id',
        'org_id',
        'assessed_by',
        'answers',
        'score',
        'risk_level',
        'recommendations',
        'notes',
    ];

    protected $casts = [
        'answers' => 'array',
        'recommendations' => 'array',
        'score' => 'integer',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function assessor()
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
