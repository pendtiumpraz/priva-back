<?php

namespace App\Services\Consent;

use App\Models\ConsentCollectionPoint;
use Illuminate\Support\Facades\Cache;

/**
 * Config publik widget (`GET /public/consent/config`) di-cache 5 menit per
 * (penanda titik | filter). Perubahan yang harus SEGERA terlihat widget —
 * prasarana aksesibilitas, metode verifikasi wali — menyegarkannya lewat
 * sini; kalau tidak, format atau metode yang baru dinonaktifkan masih
 * dijanjikan sampai 5 menit.
 */
final class CacheConfigPublik
{
    public static function segarkan(?ConsentCollectionPoint $cp): void
    {
        if (! $cp) {
            return;
        }

        foreach (array_filter([$cp->collection_id, $cp->id, $cp->embed_token]) as $kunci) {
            Cache::forget('consent:config:'.sha1((string) $kunci));
            foreach (['all', 'app', 'cookie'] as $filter) {
                Cache::forget('consent:config:'.sha1($kunci.'|'.$filter));
            }
        }
    }

    /** Untuk perubahan yang berlaku di seluruh tenant (metode verifikasi). */
    public static function segarkanOrg(string $orgId): void
    {
        ConsentCollectionPoint::withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->get(['id', 'collection_id', 'embed_token'])
            ->each(fn (ConsentCollectionPoint $cp) => self::segarkan($cp));
    }
}
