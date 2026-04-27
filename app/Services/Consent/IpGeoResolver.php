<?php

namespace App\Services\Consent;

use Illuminate\Support\Facades\Cache;

/**
 * IP → country/city resolver. Three implementations supported, picked at
 * runtime based on what's configured:
 *
 *   1. MaxMind GeoLite2 (.mmdb file at storage/app/private/geoip/GeoLite2-City.mmdb)
 *      — preferred for on-prem; no network calls; install via
 *        `composer require geoip2/geoip2` + drop the .mmdb file in place.
 *   2. None (returns nulls). Default safe behavior so the consent flow never
 *      blocks on geo failure.
 *
 * Result is cached per IP for 24h.
 */
class IpGeoResolver
{
    private const CACHE_TTL = 86400; // 24h
    private const MMDB_PATH = 'app/private/geoip/GeoLite2-City.mmdb';

    /**
     * @return array{country: ?string, city: ?string}
     */
    public static function resolve(?string $ip): array
    {
        $empty = ['country' => null, 'city' => null];
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $empty;
        }
        // Skip private / localhost
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $empty;
        }

        $cacheKey = 'geoip:'.md5($ip);
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($ip, $empty) {
            return self::resolveMaxMind($ip) ?? $empty;
        });
    }

    private static function resolveMaxMind(string $ip): ?array
    {
        $mmdbPath = storage_path(self::MMDB_PATH);
        if (! file_exists($mmdbPath)) {
            return null;
        }
        if (! class_exists(\GeoIp2\Database\Reader::class)) {
            // geoip2/geoip2 not installed — silent skip
            return null;
        }
        try {
            $reader = new \GeoIp2\Database\Reader($mmdbPath);
            $rec = $reader->city($ip);
            return [
                'country' => $rec->country->isoCode ?: null,
                'city' => $rec->city->name ?: null,
            ];
        } catch (\Throwable $e) {
            // Don't surface to user — geo is best-effort
            \Log::debug('GeoIP lookup failed: '.$e->getMessage());
            return null;
        }
    }
}
