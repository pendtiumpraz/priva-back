<?php

namespace App\Services;

use App\Models\DiscoveryCandidate;
use App\Models\InformationSystem;

/**
 * Penemuan data store dari jejak yang SUDAH ada, tanpa menyentuh jaringan.
 *
 * Inilah mode bawaan, dan alasannya praktis: di lingkungan perbankan,
 * memindai jaringan untuk mencari basis data yang tidak terdaftar hampir
 * selalu dibatasi kebijakan keamanan internal dan akan memicu alarm SOC klien
 * sendiri. Padahal jawabannya sudah tersedia di tempat lain — ekspor CMDB,
 * berkas konfigurasi aplikasi, dan log koneksi basis data semuanya menyebutkan
 * host dan porta yang benar-benar dipakai produksi.
 *
 * Penguraiannya deterministik: pola yang sama menghasilkan temuan yang sama,
 * dapat diulang, dan dapat dijelaskan baris per baris kepada pemeriksa.
 */
class PassiveDiscoveryService
{
    /**
     * Pola string koneksi yang dikenali, beserta petunjuk jenis layanannya.
     *
     * Sengaja luas: satu organisasi biasanya memakai beberapa gaya sekaligus
     * karena aplikasinya lahir di masa berbeda, dan memaksa satu bentuk berarti
     * sebagian sistem tidak akan pernah tertemukan.
     */
    private const PATTERNS = [
        // mysql://user:pass@host:3306/db  ·  postgres://…  ·  mongodb://…
        [
            'regex' => '#\b(mysql|mariadb|postgres(?:ql)?|mongodb(?:\+srv)?|redis|cassandra)://(?:[^@/\s]+@)?([A-Za-z0-9._\-]+)(?::(\d{2,5}))?#i',
            'host' => 2, 'port' => 3, 'service' => 1,
        ],
        // jdbc:postgresql://host:5432/db  ·  jdbc:sqlserver://host:1433
        [
            'regex' => '#jdbc:([a-z0-9]+)://([A-Za-z0-9._\-]+)(?::(\d{2,5}))?#i',
            'host' => 2, 'port' => 3, 'service' => 1,
        ],
        // Server=host,1433;Database=…   (gaya .NET / SQL Server)
        [
            'regex' => '#\bServer\s*=\s*([A-Za-z0-9._\-]+)\s*[,:]\s*(\d{2,5})#i',
            'host' => 1, 'port' => 2, 'service' => null, 'service_fixed' => 'mssql',
        ],
        // host=… port=…   (gaya libpq)
        [
            'regex' => '#\bhost\s*=\s*([A-Za-z0-9._\-]+)[\s;,]+.{0,40}?\bport\s*=\s*(\d{2,5})#is',
            'host' => 1, 'port' => 2, 'service' => null,
        ],
        // DB_HOST=… DB_PORT=…   (gaya .env)
        [
            'regex' => '#\bDB_HOST\s*=\s*["\']?([A-Za-z0-9._\-]+)["\']?[\s\S]{0,200}?\bDB_PORT\s*=\s*["\']?(\d{2,5})#i',
            'host' => 1, 'port' => 2, 'service' => null,
        ],
        // host:port telanjang di log koneksi
        [
            'regex' => '#\b((?:\d{1,3}\.){3}\d{1,3}|[A-Za-z0-9][A-Za-z0-9._\-]{2,})\:(\d{4,5})\b#',
            'host' => 1, 'port' => 2, 'service' => null,
        ],
    ];

    /** Porta yang dianggap milik data store; sisanya diabaikan. */
    private const DB_PORTS = [
        1433 => 'mssql', 1521 => 'oracle', 3306 => 'mysql', 5432 => 'postgresql',
        6379 => 'redis', 9042 => 'cassandra', 27017 => 'mongodb', 5984 => 'couchdb',
        9200 => 'elasticsearch', 8086 => 'influxdb', 11211 => 'memcached',
    ];

    /**
     * Cerna teks bebas (berkas konfigurasi, log koneksi) menjadi kandidat.
     *
     * @return array{found: int, new: int, known: int}
     */
    public function ingestText(string $orgId, string $text, string $source, ?string $label = null): array
    {
        $endpoints = [];

        foreach (self::PATTERNS as $pattern) {
            if (! preg_match_all($pattern['regex'], $text, $matches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($matches as $match) {
                $host = trim($match[$pattern['host']] ?? '');
                $port = isset($pattern['port']) ? (int) ($match[$pattern['port']] ?? 0) : 0;
                $service = $pattern['service_fixed']
                    ?? ($pattern['service'] ? strtolower($match[$pattern['service']] ?? '') : null);

                if ($host === '' || $this->isLoopback($host)) {
                    continue;
                }

                // Porta kosong diisi dari petunjuk layanan; kalau keduanya
                // tidak diketahui, temuannya dilewati. Endpoint tanpa porta
                // tidak dapat ditindaklanjuti maupun dicocokkan.
                if ($port === 0 && $service) {
                    $port = (int) array_search($this->normalizeService($service), self::DB_PORTS, true);
                }
                if ($port === 0 || ! isset(self::DB_PORTS[$port])) {
                    continue;
                }

                // Pola diperiksa dari yang paling spesifik ke yang paling
                // umum, dan yang PERTAMA cocok dipertahankan. Tanpa aturan itu,
                // pola "host:port" telanjang di urutan terakhir akan menimpa
                // hasil pola string koneksi — dan bukti temuannya kehilangan
                // konteks yang justru membuatnya berguna saat ditelusuri.
                $key = $host.':'.$port;
                if (isset($endpoints[$key])) {
                    continue;
                }

                $endpoints[$key] = [
                    'host' => $host,
                    'port' => $port,
                    'service_hint' => $service ? $this->normalizeService($service) : self::DB_PORTS[$port],
                    'evidence' => $this->snippet($match[0], $label),
                ];
            }
        }

        return $this->record($orgId, array_values($endpoints), $source);
    }

    /**
     * Cerna baris ekspor CMDB.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{found: int, new: int, known: int}
     */
    public function ingestCmdb(string $orgId, array $rows): array
    {
        $endpoints = [];

        foreach ($rows as $row) {
            $host = trim((string) ($row['host'] ?? $row['hostname'] ?? $row['fqdn'] ?? $row['ip'] ?? ''));
            $port = (int) ($row['port'] ?? 0);
            $service = $row['type'] ?? $row['service'] ?? $row['engine'] ?? null;

            if ($host === '' || $this->isLoopback($host)) {
                continue;
            }
            if ($port === 0 && $service) {
                $port = (int) array_search($this->normalizeService((string) $service), self::DB_PORTS, true);
            }
            if ($port === 0) {
                continue;
            }

            $endpoints[$host.':'.$port] = [
                'host' => $host,
                'port' => $port,
                'service_hint' => $service ? $this->normalizeService((string) $service) : (self::DB_PORTS[$port] ?? null),
                'name' => $row['name'] ?? $row['application'] ?? null,
                'evidence' => 'Baris CMDB: '.json_encode(array_slice($row, 0, 6), JSON_UNESCAPED_UNICODE),
            ];
        }

        return $this->record($orgId, array_values($endpoints), 'cmdb');
    }

    /**
     * Simpan temuan, tandai mana yang sudah terdaftar.
     *
     * Endpoint yang cocok dengan sistem terdaftar TETAP disimpan, berstatus
     * `registered`. Membuangnya akan menghilangkan bukti bahwa sistem itu
     * memang ditemukan — dan pemeriksa menanyakan cakupan penemuan, bukan
     * hanya temuannya.
     *
     * @param  array<int, array<string, mixed>>  $endpoints
     * @return array{found: int, new: int, known: int}
     */
    private function record(string $orgId, array $endpoints, string $source): array
    {
        $registered = $this->registeredEndpoints($orgId);
        $now = now();
        $new = 0;
        $known = 0;

        foreach ($endpoints as $endpoint) {
            $key = strtolower($endpoint['host']).':'.$endpoint['port'];
            $match = $registered[$key] ?? null;

            $existing = DiscoveryCandidate::withoutGlobalScope('org')
                ->where('org_id', $orgId)
                ->where('host', $endpoint['host'])
                ->where('port', $endpoint['port'])
                ->first();

            $attrs = [
                'service_hint' => $endpoint['service_hint'] ?? null,
                'name' => $endpoint['name'] ?? null,
                'source' => $source,
                'evidence' => $endpoint['evidence'] ?? null,
                'last_seen_at' => $now,
            ];

            if ($match) {
                $attrs['status'] = 'registered';
                $attrs['matched_system_id'] = $match;
                $known++;
            } else {
                // Status `ignored` yang sudah ditetapkan manusia tidak
                // dikembalikan ke `new` oleh penemuan berikutnya — kalau tidak,
                // daftar yang sudah ditinjau akan penuh lagi setiap kali
                // penemuan dijalankan, dan orang berhenti membacanya.
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
                    'org_id' => $orgId,
                    'host' => $endpoint['host'],
                    'port' => $endpoint['port'],
                    'first_seen_at' => $now,
                ]));
            }
        }

        return ['found' => count($endpoints), 'new' => $new, 'known' => $known];
    }

    /**
     * Endpoint milik sistem yang sudah terdaftar, dari connection_config.
     *
     * @return array<string, string> "host:port" (huruf kecil) → id sistem
     */
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
            if (! $host || $port === 0) {
                continue;
            }
            $map[strtolower((string) $host).':'.$port] = $system->id;
        }

        return $map;
    }

    private function normalizeService(string $service): string
    {
        $s = strtolower(trim($service));

        return match (true) {
            str_contains($s, 'maria'), str_contains($s, 'mysql') => 'mysql',
            str_contains($s, 'postgres') => 'postgresql',
            str_contains($s, 'sqlserver'), str_contains($s, 'mssql') => 'mssql',
            str_contains($s, 'mongo') => 'mongodb',
            str_contains($s, 'oracle'), str_contains($s, 'oci') => 'oracle',
            str_contains($s, 'redis') => 'redis',
            str_contains($s, 'cassandra') => 'cassandra',
            default => $s,
        };
    }

    /**
     * Loopback dan alamat tautan-lokal dibuang: keduanya menunjuk mesin yang
     * sedang membaca berkasnya, bukan data store terpisah yang perlu didaftarkan.
     */
    private function isLoopback(string $host): bool
    {
        $h = strtolower($host);

        return in_array($h, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)
            || str_starts_with($h, '127.')
            || str_starts_with($h, '169.254.');
    }

    private function snippet(string $raw, ?string $label): string
    {
        // Kredensial di dalam string koneksi disamarkan sebelum disimpan:
        // bukti temuan tidak boleh berubah menjadi tempat penyimpanan kata
        // sandi produksi.
        $safe = preg_replace('#://([^:@/\s]+):([^@/\s]+)@#', '://$1:****@', $raw) ?? $raw;
        $safe = preg_replace('#(password|pwd|secret)\s*=\s*\S+#i', '$1=****', $safe) ?? $safe;
        $safe = mb_substr(trim($safe), 0, 200);

        return $label ? "[{$label}] {$safe}" : $safe;
    }
}
