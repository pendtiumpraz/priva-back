<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vendor extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id',
        'name',
        'type',
        'country',
        'contact_name',
        'contact_email',
        'website',
        'description',
        'dpa_status',
        'dpa_signed_at',
        'dpa_expires_at',
        'risk_score',
        'risk_level',
        'last_assessed_at',
        'data_shared',
        'services_provided',
        'documents',
    ];

    protected $casts = [
        'dpa_signed_at' => 'date',
        'dpa_expires_at' => 'date',
        'last_assessed_at' => 'date',
        'data_shared' => 'array',
        'services_provided' => 'array',
        'documents' => 'array',
        'risk_score' => 'integer',
        // PII Encryption — AES-256-CBC
        'contact_name' => EncryptedString::class,
        'contact_email' => EncryptedString::class,
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function assessments()
    {
        return $this->hasMany(VendorAssessment::class, 'vendor_id')->orderBy('created_at', 'desc');
    }

    public function getLatestAssessment()
    {
        return $this->assessments()->first();
    }
}
