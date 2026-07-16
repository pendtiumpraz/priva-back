<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConsentItem extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'collection_point_id', 'title', 'description', 'specific_purpose', 'full_text',
        'category', 'cookie_keys',
        'version', 'is_required', 'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'cookie_keys' => 'array',
    ];

    public const CATEGORIES = ['essential', 'analytics', 'marketing', 'personalization', 'functional', 'third_party', 'other'];

    /**
     * Categories typically shown in cookie consent banner (anonymous visitors).
     * Other categories (third_party with sensitive data, biometric, etc) are
     * meant for logged-in flows where klien explicitly trigger consent UI.
     */
    public const COOKIE_CATEGORIES = ['essential', 'analytics', 'marketing', 'functional'];

    /**
     * Categories valid for a given collection kind. A collection is single-kind
     * (cookie_banner OR app_consent), so its items are physically partitioned by
     * the parent's kind — this returns the allowed category set for that kind:
     *   - cookie_banner → COOKIE_CATEGORIES (essential/analytics/marketing/functional)
     *   - app_consent   → the NON-cookie categories (personalization/third_party/other)
     */
    public static function categoriesForKind(string $kind): array
    {
        if ($kind === ConsentCollectionPoint::KIND_COOKIE) {
            return self::COOKIE_CATEGORIES;
        }

        return array_values(array_diff(self::CATEGORIES, self::COOKIE_CATEGORIES));
    }

    protected static function booted(): void
    {
        // Item writes change the config payload too → bust the parent's cache.
        $bust = function (self $item) {
            if ($point = $item->collectionPoint()->withoutGlobalScopes()->first()) {
                $point->bustConsentCache();
            }
        };
        static::saved($bust);
        static::deleted($bust);
    }

    public function collectionPoint()
    {
        return $this->belongsTo(ConsentCollectionPoint::class , 'collection_point_id');
    }

    /**
     * Build an [item_id => title] lookup for one or more collection points.
     * Includes trashed items so historical consent logs that reference a
     * since-deleted item still resolve to a readable title instead of a UUID.
     */
    public static function titleMap(array|string $collectionIds): array
    {
        $ids = array_values(array_filter((array) $collectionIds));
        if (empty($ids)) {
            return [];
        }

        return static::withTrashed()
            ->whereIn('collection_point_id', $ids)
            ->pluck('title', 'id')
            ->all();
    }
}
