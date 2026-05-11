<?php

namespace App\Services;

/**
 * IP allowlist untuk platform-level roles (root, superadmin). Mitigasi
 * credential theft — bahkan kalau password + 2FA bocor, attacker dari IP
 * di luar allowlist tidak bisa login.
 *
 * Format entry per role: array of IP atau CIDR string. Contoh:
 *   ["192.168.1.0/24", "203.0.113.42", "2001:db8::/32"]
 *
 * Default OFF dengan empty list. Admin enable + isi list saat siap. Hati-
 * hati: kalau enable + list salah, admin bisa lock dirinya sendiri. Best
 * practice: tambahin IP sekarang DULU, save, verify masih bisa akses, BARU
 * toggle enabled = true.
 */
class IpAllowlistService
{
    /** Cek apakah role butuh IP allowlist enforcement. */
    public function isEnforcedFor(string $role): bool
    {
        return match ($role) {
            'root' => (bool) config('security.ip_allowlist_enabled_for_root', false),
            'superadmin' => (bool) config('security.ip_allowlist_enabled_for_superadmin', false),
            default => false,
        };
    }

    /** @return list<string> */
    public function listFor(string $role): array
    {
        $list = match ($role) {
            'root' => config('security.ip_allowlist_root', []),
            'superadmin' => config('security.ip_allowlist_superadmin', []),
            default => [],
        };
        return is_array($list) ? array_values(array_filter($list, fn ($x) => is_string($x) && $x !== '')) : [];
    }

    /**
     * Apakah IP request match allowlist untuk role tertentu. Kalau enforcement
     * mati, selalu return true (allow). Kalau list kosong tapi enforcement
     * aktif, return false (deny all — fail-safe).
     */
    public function isAllowed(string $role, string $clientIp): bool
    {
        if (! $this->isEnforcedFor($role)) return true;

        $list = $this->listFor($role);
        if (empty($list)) return false; // Fail closed — kalau diaktifkan tanpa isi, deny semua

        foreach ($list as $entry) {
            if ($this->ipMatches($clientIp, $entry)) return true;
        }
        return false;
    }

    /**
     * Match IP terhadap entry (IP literal atau CIDR notation).
     * Support IPv4 dan IPv6.
     */
    private function ipMatches(string $ip, string $entry): bool
    {
        if (! str_contains($entry, '/')) {
            // IP literal — direct compare. Normalize via inet_pton.
            return @inet_pton($ip) !== false && @inet_pton($entry) !== false
                && inet_pton($ip) === inet_pton($entry);
        }

        // CIDR
        [$subnet, $bits] = explode('/', $entry, 2);
        $bits = (int) $bits;
        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton($subnet);
        if ($ipPacked === false || $subnetPacked === false) return false;
        if (strlen($ipPacked) !== strlen($subnetPacked)) return false; // IPv4 vs IPv6 mismatch

        $bytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($bytes > 0 && substr($ipPacked, 0, $bytes) !== substr($subnetPacked, 0, $bytes)) {
            return false;
        }

        if ($remBits === 0) return true;

        $mask = chr(0xff << (8 - $remBits) & 0xff);
        return (substr($ipPacked, $bytes, 1) & $mask) === (substr($subnetPacked, $bytes, 1) & $mask);
    }
}
