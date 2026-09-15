<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
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
    use BelongsToOrg, HasFactory, HasUuids;

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
        // Pasal 38 & 39 — lihat migrasi 2026_09_15_000008.
        'guardian_consent_id',
        'subject_class',
    ];

    protected $casts = [
        'consented_items' => 'array',
        'purpose_keys' => 'array',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Kewenangan wali yang memayungi penangkapan ini — NULL untuk subjek dewasa,
     * yaitu mayoritas baris.
     *
     * `subject_class` di baris ini adalah POTRET SAAT PENANGKAPAN dan tidak ikut
     * berubah saat subjeknya dewasa; keadaan sekarang ada di ConsentSubject.
     * Bedanya penting saat diaudit: pertanyaannya "waktu itu ia masih anak?",
     * bukan "sekarang ia anak?".
     */
    public function guardianConsent()
    {
        return $this->belongsTo(GuardianConsent::class, 'guardian_consent_id');
    }

    public function collectionPoint()
    {
        return $this->belongsTo(ConsentCollectionPoint::class, 'collection_id');
    }

    /**
     * [item_id => title] lookup for this log's collection. `consented_items`
     * and `purpose_keys` store item UUIDs as keys; use this to render titles.
     */
    public function itemTitleMap(): array
    {
        return ConsentItem::titleMap([$this->collection_id]);
    }

    /**
     * consented_items with item UUIDs resolved to titles: { title: bool }.
     * Handles both the new map form ({id: bool}) and the legacy array of
     * granted ids. Unknown keys fall back to the raw key.
     */
    public function labeledConsentedItems(): array
    {
        $ci = $this->consented_items ?? [];
        $map = $this->itemTitleMap();
        $out = [];

        if (array_is_list($ci)) {
            foreach ($ci as $id) {
                $out[$map[$id] ?? $id] = true;
            }
        } else {
            foreach ($ci as $id => $val) {
                $out[$map[$id] ?? $id] = (bool) $val;
            }
        }

        return $out;
    }

    /**
     * Titles of the purposes the subject granted (from purpose_keys UUIDs).
     */
    public function grantedPurposeTitles(): array
    {
        $map = $this->itemTitleMap();

        return array_values(array_map(
            fn ($key) => $map[$key] ?? $key,
            $this->purpose_keys ?? []
        ));
    }
}
