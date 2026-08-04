<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DiscoveryCandidate;
use App\Models\DiscoveryProbeConfig;
use App\Models\InformationSystem;
use App\Models\SystemSetting;

/**
 * Penemuan data store dengan menyambung ke jaringan klien.
 *
 * Ini pemindaian jaringan sungguhan, dan karena itu dijaga BERLAPIS. Tidak ada
 * satu pun lapis yang punya nilai bawaan yang membolehkan:
 *
 *   1. Saklar platform `discovery.active_scan_allowed` — di tangan superadmin,
 *      bawaan FALSE. Pemindaian dijalankan infrastruktur kami terhadap jaringan
 *      klien, jadi penyedia platform tidak boleh menanggung akibat tindakan
 *      yang tidak pernah ia setujui.
 *   2. Mode tenant harus `active` DAN persetujuan tenant tercatat beserta
 *      siapa yang menyetujui dan kapan.
 *   3. Rentang IP harus disebut satu per satu. Tidak ada bawaan, tidak ada
 *      penemuan otomatis rentangnya.
 *   4. Batas jumlah host, supaya satu kekeliruan menuliskan CIDR tidak berubah
 *      menjadi pemindaian belasan juta alamat.
 *
 * Yang dilakukan hanya percobaan sambungan TCP lalu segera diputus — tidak ada
 * pengiriman muatan, tidak ada percobaan otentikasi, tidak ada pengambilan
 * banner. Membedakannya penting: yang pertama setara mengetuk pintu, dan itulah
 * batas yang dapat dipertanggungjawabkan pada jaringan milik orang lain.
 */
class ActiveDiscoveryService
{
    /** Porta basis data yang lazim, dipakai bila tenant tidak menyebutkannya. */
    private const DEFAULT_PORTS = DiscoveryProbeConfig::DEFAULT_PORTS;

    private const PORT_SERVICE = [
        1433 => 'mssql', 1521 => 'oracle', 3306 => 'mysql', 5432 => 'postgresql',
        6379 => 'redis', 9042 => 'cassandra', 27017 => 'mongodb',
    ];

    /**
     * Periksa apakah pemindaian aktif boleh dijalankan, dan jelaskan bila tidak.
     *
     * Dipisah dari eksekusi supaya antarmuka dapat menampilkan alasannya
     * sebelum tombol ditekan — bukan setelah pengguna menunggu lalu ditolak.
     *
     * @return array{allowed: bool, reason: ?string, gate: string}
     */
    public function gate(DiscoveryProbeConfig $config): array
    {
        if (! (bool) SystemSetting::get('discovery.active_scan_allowed', false)) {
            return [
                'allowed' => false,
                'gate' => 'platform',
                'reason' => 'Pemindaian aktif dimatikan pada tingkat platform. '
                    .'Hubungi pengelola platform bila memang dibutuhkan.',
            ];
        }

        if ($config->mode !== DiscoveryProbeConfig::MODE_ACTIVE) {
            return [
                'allowed' => false,
                'gate' => 'tenant_mode',
                'reason' => 'Mode penemuan organisasi ini masih pasif. Ubah ke aktif lebih dulu.',
            ];
        }

        if (! $config->active_scan_approved_at || ! $config->active_scan_approved_by) {
            return [
                'allowed' => false,
                'gate' => 'approval',
                'reason' => 'Pemindaian aktif belum disetujui. Persetujuan dicatat beserta siapa dan kapan, '
                    .'karena memindai jaringan adalah tindakan yang harus dapat dipertanggungjawabkan.',
            ];
        }

        if (empty($config->cidr_ranges)) {
            return [
                'allowed' => false,
                'gate' => 'scope',
                'reason' => 'Rentang IP belum ditentukan. Rentang harus disebut satu per satu — '
                    .'tidak ada rentang bawaan.',
            ];
        }

        return ['allowed' => true, 'gate' => 'ok', 'reason' => null];
    }

    /**
     * Jalankan pemindaian.
     *
     * @return array{scanned_hosts: int, open: int, new: int, known: int, skipped_reason: ?string}
     */
    public function run(DiscoveryProbeConfig $config, string $actorId): array
    {
        $gate = $this->gate($config);
        if (! $gate['allowed']) {
            return [
                'scanned_hosts' => 0, 'open' => 0, 'new' => 0, 'known' => 0,
                'skipped_reason' => $gate['reason'],
            ];
        }

        $maxHosts = (int) SystemSetting::get('discovery.active_scan_max_hosts', 1024);
        $timeoutMs = (int) SystemSetting::get('discovery.active_scan_timeout_ms', 300);
        $ports = ! empty($config->ports) ? array_map('intval', $config->ports) : self::DEFAULT_PORTS;

        $hosts = [];
        foreach ($config->cidr_ranges as $cidr) {
            foreach ($this->expand((string) $cidr, $maxHosts - count($hosts)) as $ip) {
                $hosts[] = $ip;
                if (count($hosts) >= $maxHosts) {
                    break 2;
                }
            }
        }

        // Jejak audit ditulis SEBELUM pemindaian, bukan sesudah. Kalau proses
        // terhenti di tengah, yang perlu terjawab tetap terjawab: siapa yang
        // memerintahkan, kapan, dan terhadap rentang mana.
        AuditLog::log('data-discovery', $config->org_id, 'active_scan_started', [
            'cidr_ranges' => $config->cidr_ranges,
            'ports' => $ports,
            'host_count' => count($hosts),
            'approved_by' => $config->active_scan_approved_by,
            'actor' => $actorId,
        ], 'discovery');

        $registered = $this->registeredEndpoints($config->org_id);
        $now = now();
        $open = 0;
        $new = 0;
        $known = 0;

        foreach ($hosts as $host) {
            foreach ($ports as $port) {
                if (! $this->probe($host, $port, $timeoutMs)) {
                    continue;
                }
                $open++;

                $matched = $registered[strtolower($host).':'.$port] ?? null;
                $existing = DiscoveryCandidate::withoutGlobalScope('org')
                    ->where('org_id', $config->org_id)
                    ->where('host', $host)->where('port', $port)->first();

                $attrs = [
                    'service_hint' => self::PORT_SERVICE[$port] ?? null,
                    'source' => 'network_scan',
                    'evidence' => "Porta {$port} menerima sambungan TCP pada {$now->toDateTimeString()}.",
                    'last_seen_at' => $now,
                ];

                if ($matched) {
                    $attrs['status'] = 'registered';
                    $attrs['matched_system_id'] = $matched;
                    $known++;
                } else {
                    // Status `ignored` yang sudah ditetapkan manusia tidak
                    // dikembalikan ke `new` — kalau tidak, daftar yang sudah
                    // ditinjau akan penuh lagi setiap pemindaian dan orang
                    // berhenti membacanya.
                    if (! $existing || $existing->status === 'new') {
                        $attrs['status'] = 'new';
                    }
                    if (! $existing || $existing->status !== 'registered') {
                        $new++;
                    }
                }

                if ($existing) {
                    $existing->update($attrs);
                } else {
                    DiscoveryCandidate::withoutGlobalScope('org')->create(array_merge($attrs, [
                        'org_id' => $config->org_id,
                        'host' => $host,
                        'port' => $port,
                        'first_seen_at' => $now,
                    ]));
                }
            }
        }

        $config->update(['last_run_at' => $now]);

        AuditLog::log('data-discovery', $config->org_id, 'active_scan_finished', [
            'scanned_hosts' => count($hosts),
            'open' => $open,
            'new' => $new,
        ], 'discovery');

        return [
            'scanned_hosts' => count($hosts),
            'open' => $open,
            'new' => $new,
            'known' => $known,
            'skipped_reason' => null,
        ];
    }

    /**
     * Uraikan CIDR menjadi daftar alamat.
     *
     * Hanya IPv4 dan hanya prefiks /16 ke atas. Batas itu bukan keterbatasan
     * teknis melainkan pengaman: /8 berisi 16 juta alamat, dan tidak ada
     * keadaan wajar di mana seseorang benar-benar bermaksud memindainya.
     *
     * @return array<int, string>
     */
    public function expand(string $cidr, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $cidr = trim($cidr);

        // Alamat tunggal tanpa prefiks tetap diterima.
        if (! str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$cidr] : [];
        }

        [$base, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        if (! filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 16 || $bits > 32) {
            return [];
        }

        $baseLong = ip2long($base);
        if ($baseLong === false) {
            return [];
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
        $network = $baseLong & $mask;
        $size = 2 ** (32 - $bits);

        // Alamat jaringan dan siaran dilewati pada blok yang punya keduanya.
        $start = $size > 2 ? $network + 1 : $network;
        $end = $size > 2 ? $network + $size - 2 : $network + $size - 1;

        $out = [];
        for ($ip = $start; $ip <= $end && count($out) < $limit; $ip++) {
            $out[] = long2ip($ip);
        }

        return $out;
    }

    /**
     * Ketuk satu porta lalu segera putus.
     *
     * Tidak mengirim muatan apa pun dan tidak membaca banner. Yang dijawab
     * hanya "ada yang mendengarkan di sini atau tidak".
     */
    protected function probe(string $host, int $port, int $timeoutMs): bool
    {
        $errno = 0;
        $errstr = '';
        $handle = @fsockopen($host, $port, $errno, $errstr, max(0.05, $timeoutMs / 1000));

        if ($handle === false) {
            return false;
        }
        fclose($handle);

        return true;
    }

    /** @return array<string, string> "host:port" (huruf kecil) → id sistem */
    private function registeredEndpoints(string $orgId): array
    {
        $map = [];
        $systems = InformationSystem::withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->get(['id', 'connection_config']);

        foreach ($systems as $system) {
            $config = $system->connection_config ?? [];
            $host = $config['host'] ?? null;
            $port = (int) ($config['port'] ?? 0);
            if ($host && $port) {
                $map[strtolower((string) $host).':'.$port] = $system->id;
            }
        }

        return $map;
    }
}
