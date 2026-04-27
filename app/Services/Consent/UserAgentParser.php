<?php

namespace App\Services\Consent;

/**
 * Lightweight User-Agent parser. Regex-based, no external deps so it ships
 * cleanly into on-prem deploys without composer changes.
 *
 * Returns: ['browser_name','browser_version','os_name','device_type'].
 *
 * Trade-off: regex misses edge cases (rare browsers, embedded webviews).
 * For now this is acceptable — accurate detection (Matomo DeviceDetector
 * or whichbrowser) can replace the implementation later without API change.
 */
class UserAgentParser
{
    /**
     * @return array{browser_name: ?string, browser_version: ?string, os_name: ?string, device_type: ?string}
     */
    public static function parse(?string $ua): array
    {
        $out = [
            'browser_name' => null,
            'browser_version' => null,
            'os_name' => null,
            'device_type' => null,
        ];
        if (! $ua) {
            return $out;
        }
        $ua = substr($ua, 0, 500);

        // Bot detection first (cheap, common)
        if (preg_match('/bot|crawler|spider|crawl|slurp|bingpreview|facebookexternalhit/i', $ua)) {
            $out['device_type'] = 'bot';
            return $out;
        }

        // Browser — order matters: Edge before Chrome, Chrome before Safari
        $browserRules = [
            ['name' => 'Edge', 'pattern' => '/Edg(?:e|A|iOS)?\/([\d.]+)/i'],
            ['name' => 'Opera', 'pattern' => '/(?:OPR|Opera)\/([\d.]+)/i'],
            ['name' => 'Firefox', 'pattern' => '/Firefox\/([\d.]+)/i'],
            ['name' => 'Samsung Internet', 'pattern' => '/SamsungBrowser\/([\d.]+)/i'],
            ['name' => 'Chrome', 'pattern' => '/Chrome\/([\d.]+)/i'],
            ['name' => 'Safari', 'pattern' => '/Version\/([\d.]+).*Safari/i'],
            ['name' => 'IE', 'pattern' => '/(?:MSIE |Trident.*rv:)([\d.]+)/i'],
        ];
        foreach ($browserRules as $r) {
            if (preg_match($r['pattern'], $ua, $m)) {
                $out['browser_name'] = $r['name'];
                $out['browser_version'] = self::truncVersion($m[1] ?? null);
                break;
            }
        }

        // OS
        $osRules = [
            'iOS' => '/iPhone OS|iPad; CPU OS|iPod OS/i',
            'Android' => '/Android/i',
            'Windows' => '/Windows NT/i',
            'macOS' => '/Mac OS X|Macintosh/i',
            'Linux' => '/Linux|X11/i',
            'ChromeOS' => '/CrOS/i',
        ];
        foreach ($osRules as $name => $pat) {
            if (preg_match($pat, $ua)) {
                $out['os_name'] = $name;
                break;
            }
        }

        // Device type
        if (preg_match('/Mobi|iPhone|Android.*Mobile/i', $ua)) {
            $out['device_type'] = 'mobile';
        } elseif (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $ua)) {
            $out['device_type'] = 'tablet';
        } else {
            $out['device_type'] = 'desktop';
        }

        return $out;
    }

    private static function truncVersion(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        // Keep first two octets to limit cardinality (e.g. "129.0.6668" → "129.0")
        $parts = explode('.', $v);
        return implode('.', array_slice($parts, 0, 2));
    }
}
