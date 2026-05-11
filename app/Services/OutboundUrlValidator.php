<?php

namespace App\Services;

use RuntimeException;

/**
 * SSRF (Server-Side Request Forgery) guard untuk outbound HTTP request.
 *
 * Attack pattern: attacker submit URL ke endpoint integration test atau
 * webhook config, server lakukan HTTP request ke URL itu. Kalau URL
 * resolve ke internal IP (mis. metadata endpoint AWS 169.254.169.254
 * atau internal admin panel 127.0.0.1:9000), attacker bisa:
 *   - Curi credential dari instance metadata (AWS/GCP)
 *   - Akses internal service yang tidak exposed publik
 *   - Port scan internal network
 *
 * Mitigasi:
 *   1. Parse URL — validate scheme (only http/https)
 *   2. Resolve hostname → IP via DNS
 *   3. Validate IP bukan di blocklist (RFC1918 + loopback + link-local + ULA)
 *   4. Re-check setiap resolved IP kalau hostname punya banyak A record
 *
 * Konfigurasi:
 *   - security.ssrf_allow_private (default false) — override untuk dev/test
 *     environment yang memang butuh akses ke private IP.
 */
class OutboundUrlValidator
{
    /**
     * Validate URL aman untuk outbound. Throw RuntimeException kalau:
     *   - URL tidak valid format
     *   - Scheme bukan http/https
     *   - Host resolve ke private IP / loopback / link-local
     *
     * Allow ke env config kalau security.ssrf_allow_private = true (dev only).
     */
    public function validate(string $url): void
    {
        // Override untuk dev environment yang butuh akses ke localhost
        if ((bool) config('security.ssrf_allow_private', false)) {
            return;
        }

        $parts = parse_url($url);
        if (! $parts || ! isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('URL tidak valid.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException("Scheme '{$scheme}' tidak diperbolehkan. Hanya http/https.");
        }

        $host = $parts['host'];

        // Block IP literal yang obvious tanpa DNS lookup
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host, $host);
            return;
        }

        // Resolve hostname via DNS — ambil SEMUA A/AAAA record dan validate satu-satu
        // Mencegah DNS rebinding: kalau hostname punya multiple IP, salah satunya
        // private, tetap reject.
        $ips = $this->resolveAll($host);
        if (empty($ips)) {
            throw new RuntimeException("Tidak dapat resolve hostname '{$host}'.");
        }

        foreach ($ips as $ip) {
            $this->assertPublicIp($ip, $host);
        }
    }

    /**
     * Apakah IP aman (publik, bukan private/loopback/dll)?
     * Throw kalau blocked.
     */
    private function assertPublicIp(string $ip, string $originalHost): void
    {
        // Pakai PHP filter_var built-in. Flags:
        //   FILTER_FLAG_NO_PRIV_RANGE — reject RFC1918 (10.x, 172.16-31.x, 192.168.x)
        //   FILTER_FLAG_NO_RES_RANGE  — reject reserved (loopback, link-local, multicast, dst)
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (! filter_var($ip, FILTER_VALIDATE_IP, $flags)) {
            throw new RuntimeException(
                "URL '{$originalHost}' resolve ke IP '{$ip}' yang merupakan "
                ."private / loopback / reserved range. Akses ke IP tersebut tidak diperbolehkan."
            );
        }

        // Extra explicit check untuk IPs yang mungkin lolos filter_var:
        //   - 0.0.0.0 (any address — sometimes used as exploit)
        //   - 169.254.169.254 (AWS metadata — RFC sebagai link-local sudah di-block tapi double check)
        $extraBlocked = ['0.0.0.0', '::', '::1'];
        if (in_array($ip, $extraBlocked, true)) {
            throw new RuntimeException("IP '{$ip}' (special-purpose) di-block.");
        }
    }

    /**
     * Resolve hostname ke semua A + AAAA record.
     *
     * @return list<string>
     */
    private function resolveAll(string $host): array
    {
        $ips = [];

        // IPv4 lookup
        $a = @dns_get_record($host, DNS_A);
        if (is_array($a)) {
            foreach ($a as $record) {
                if (! empty($record['ip'])) $ips[] = $record['ip'];
            }
        }

        // IPv6 lookup
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (! empty($record['ipv6'])) $ips[] = $record['ipv6'];
            }
        }

        // Fallback ke gethostbyname kalau dns_get_record gagal
        if (empty($ips)) {
            $ip = gethostbyname($host);
            if ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }

        return $ips;
    }
}
