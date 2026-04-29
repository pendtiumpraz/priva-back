<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrossBorderTransfer extends Model
{
    use HasFactory, HasUuids, SoftDeletes, BelongsToOrg;

    /** Volume bands — drives risk_data_leak metric in TIA. */
    public const VOLUME_BANDS = ['small', 'medium', 'large', 'mass'];
    public const VOLUME_BAND_LABELS = [
        'small' => 'Kecil (<1.000 record)',
        'medium' => 'Sedang (1k–100k record)',
        'large' => 'Besar (100k–1jt record)',
        'mass' => 'Massal (>1jt record)',
    ];

    public const FREQUENCIES = ['one_time', 'monthly', 'weekly', 'daily', 'realtime'];
    public const FREQUENCY_LABELS = [
        'one_time' => 'Sekali (one-time)',
        'monthly' => 'Bulanan',
        'weekly' => 'Mingguan',
        'daily' => 'Harian',
        'realtime' => 'Real-time / Streaming',
    ];

    public const SENSITIVITIES = ['general', 'personal', 'sensitive_specific', 'extra_sensitive'];
    public const SENSITIVITY_LABELS = [
        'general' => 'Umum (non-PDP)',
        'personal' => 'Pribadi (UU PDP Pasal 4 ayat 1)',
        'sensitive_specific' => 'Spesifik / Sensitif (Pasal 4 ayat 2 — kesehatan, biometrik, anak, finansial)',
        'extra_sensitive' => 'Sangat Sensitif (gabungan kategori spesifik)',
    ];

    public const MECHANISMS = ['api', 'batch_export', 'replication', 'manual_email', 'cloud_sync', 'file_share'];
    public const MECHANISM_LABELS = [
        'api' => 'API / Webhook',
        'batch_export' => 'Batch Export (file periodik)',
        'replication' => 'DB Replication / CDC',
        'manual_email' => 'Email Manual',
        'cloud_sync' => 'Cloud Storage Sync',
        'file_share' => 'File Share / SFTP',
    ];

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
        // Phase 1 enrichment
        'transfer_volume_band', 'transfer_frequency', 'data_sensitivity', 'transfer_mechanism',
        'encryption_in_transit', 'encryption_at_rest', 'data_minimization_applied',
        'retention_period_days',
        'recipient_dpo_name', 'recipient_dpo_email',
        'linked_ropa_id',
    ];

    protected $casts = [
        'data_categories' => 'array',
        'safeguards' => 'array',
        'tia_answers' => 'array',
        'risk_score' => 'integer',
        'approved_at' => 'date',
        'review_due_at' => 'date',
        'encryption_in_transit' => 'boolean',
        'encryption_at_rest' => 'boolean',
        'data_minimization_applied' => 'boolean',
        'retention_period_days' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function ropa()
    {
        return $this->belongsTo(Ropa::class, 'linked_ropa_id');
    }

    /**
     * Convenience: resolve the country adequacy record for this transfer.
     * Returns null when destination_country is empty or unrecognized — UI
     * should treat that as Tier "unknown" and ask for safeguards.
     */
    public function adequacy(): ?CountryAdequacy
    {
        return CountryAdequacy::resolve($this->destination_country);
    }
}
