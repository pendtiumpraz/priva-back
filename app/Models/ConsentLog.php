<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Identifiable consent capture (app_consent kind) — register/checkout/newsletter
 * forms where we have email/name/phone tied to the choice.
 *
 * For ANONYMOUS visitor consent (cookie banner), see CookieLog.
 *
 * `user_identifier` is preserved for backwards-compat with embed v1; new code
 * should write to `email` directly.
 */
class ConsentLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'org_id',
        'collection_id',
        'user_identifier',
        // Identifiable subject
        'email',
        'name',
        'phone',
        'user_id',
        'external_user_ref',
        // Choices
        'consented_items',
        'purpose_keys',
        'policy_version',
        'source_form',
        // Network / client
        'ip_address',
        'ip_country',
        'user_agent',
        'browser_name',
        'browser_version',
        'os_name',
        'device_type',
    ];

    protected $casts = [
        'consented_items' => 'array',
        'purpose_keys' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function collectionPoint()
    {
        return $this->belongsTo(ConsentCollectionPoint::class, 'collection_id');
    }
}
