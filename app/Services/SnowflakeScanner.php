<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Konektor Snowflake lewat SQL REST API v2.
 *
 * Kenapa REST, bukan driver: Snowflake tidak punya driver PDO, dan satu-satunya
 * alternatifnya adalah ODBC — yang menuntut pemasangan driver di server. Di
 * hosting bersama itu tidak mungkin, dan di lingkungan klien ia menambah satu
 * permintaan lagi ke tim infrastruktur. REST hanya butuh HTTPS 443 keluar, yang
 * di hampir semua jaringan sudah terbuka.
 *
 * Autentikasinya key-pair (JWT RS256), bukan kata sandi. Itu bukan pilihan
 * kami: SQL API v2 memang hanya menerima KEYPAIR_JWT atau OAuth. Kebetulan itu
 * juga yang lebih mudah lolos tinjauan keamanan bank — kunci privatnya tidak
 * pernah dikirim ke mana pun, hanya dipakai menandatangani token berumur pendek.
 *
 * Yang TIDAK dilakukan di sini, dan itu disengaja: tidak ada satu pun perintah
 * yang mengubah data. Pembacaan skema memakai INFORMATION_SCHEMA, dan
 * pengambilan sampel memakai SELECT ber-LIMIT. Batas itu ditegakkan lagi di
 * DatabaseScanner::validateReadOnlyQuery() untuk jalur kueri bebas.
 */
class SnowflakeScanner
{
    /** Umur token JWT. Snowflake menolak yang lebih dari satu jam. */
    private const JWT_TTL = 3540;

    /** Baris sampel per tabel — cukup untuk mengenali pola, bukan menyalin data. */
    private const SAMPLE_LIMIT = 20;

    /**
     * Batas tabel yang diambil sampelnya dalam satu pemindaian.
     *
     * Snowflake menagih per detik warehouse menyala. Gudang data dengan ribuan
     * tabel akan membuat satu pemindaian "sekadar melihat-lihat" berubah
     * menjadi tagihan yang harus dijelaskan ke seseorang. Skema tetap dibaca
     * seluruhnya — yang dibatasi hanya pengambilan sampel isinya.
     */
    private const MAX_SAMPLED_TABLES = 200;

    public static function testConnection(array $config): array
    {
        $missing = self::missingConfig($config);
        if ($missing) {
            return ['success' => false, 'error' => 'Konfigurasi belum lengkap: '.implode(', ', $missing)];
        }

        try {
            $start = microtime(true);
            $res = self::statement($config, 'SELECT CURRENT_VERSION() AS V, CURRENT_ACCOUNT() AS A, CURRENT_ROLE() AS R');
            $row = $res['rows'][0] ?? [];

            return [
                'success' => true,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'note' => 'Tersambung ke Snowflake '.($row['V'] ?? '?').' (akun '.($row['A'] ?? '?').', peran '.($row['R'] ?? '?').')',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Baca skema dan deteksi kolom berisi data pribadi.
     *
     * Bentuk keluarannya sengaja identik dengan konektor PDO lain, sehingga
     * seluruh lapisan di hilir — katalog, RoPA, laporan — tidak perlu tahu
     * sumbernya Snowflake.
     */
    public static function scanSchema(array $config): array
    {
        $missing = self::missingConfig($config);
        if ($missing) {
            return ['tables' => [], 'error' => 'Konfigurasi belum lengkap: '.implode(', ', $missing)];
        }

        try {
            $database = self::ident($config['database']);
            $schema = self::ident($config['schema'] ?? 'PUBLIC');

            $columnRows = self::statement($config, sprintf(
                'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH '.
                'FROM %s.INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s '.
                'ORDER BY TABLE_NAME, ORDINAL_POSITION',
                $database,
                self::quote($config['schema'] ?? 'PUBLIC'),
            ))['rows'];

            if (! $columnRows) {
                return ['tables' => [], 'engine' => 'real_snowflake', 'error' => 'Skema tidak memuat tabel yang dapat dibaca peran ini.'];
            }

            $rowCounts = [];
            try {
                foreach (self::statement($config, sprintf(
                    'SELECT TABLE_NAME, ROW_COUNT FROM %s.INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s',
                    $database,
                    self::quote($config['schema'] ?? 'PUBLIC'),
                ))['rows'] as $r) {
                    $rowCounts[$r['TABLE_NAME']] = (int) ($r['ROW_COUNT'] ?? 0);
                }
            } catch (\Throwable) {
                // Jumlah baris bersifat pelengkap. Peran yang tidak boleh
                // membacanya tidak boleh menggagalkan seluruh pemindaian.
            }

            $grouped = [];
            foreach ($columnRows as $row) {
                $grouped[$row['TABLE_NAME']][] = $row;
            }

            $tables = [];
            $sampled = 0;

            foreach ($grouped as $tableName => $cols) {
                $sampleRows = [];
                if ($sampled < self::MAX_SAMPLED_TABLES) {
                    $sampled++;
                    try {
                        $sampleRows = self::statement($config, sprintf(
                            'SELECT * FROM %s.%s.%s LIMIT %d',
                            $database,
                            $schema,
                            self::ident($tableName),
                            self::SAMPLE_LIMIT,
                        ))['rows'];
                    } catch (\Throwable) {
                        // Tabel yang tidak boleh dibaca peran ini tetap masuk
                        // katalog berdasarkan skemanya — hanya tanpa sinyal isi.
                    }
                }

                $columns = [];
                foreach ($cols as $col) {
                    $colName = (string) $col['COLUMN_NAME'];
                    $type = self::typeLabel($col);

                    $piiResult = PiiDetector::analyze($colName, $type);
                    $shadowDetected = false;
                    $protection = ['protection_state' => 'unknown', 'protection_reason' => 'Tidak ada sampel'];

                    if ($sampleRows) {
                        $values = array_column($sampleRows, $colName);
                        $contentPii = ContentPiiScanner::analyzeColumnContent($values);

                        if ($contentPii) {
                            $piiResult = $contentPii;
                            $shadowDetected = true;
                        }
                        $protection = ContentPiiScanner::detectProtectionState($values);
                    }

                    $typeEncrypted = ContentPiiScanner::looksEncryptedType($type);
                    if ($typeEncrypted && in_array($protection['protection_state'], ['unknown', 'plaintext'], true)) {
                        $protection = ['protection_state' => 'encrypted', 'protection_reason' => 'Tipe biner — kemungkinan besar ciphertext/berkas biner'];
                    }

                    $columns[] = [
                        'name' => $colName,
                        'alias' => null,
                        'type' => $type,
                        'type_length' => $col['CHARACTER_MAXIMUM_LENGTH'] !== null ? (int) $col['CHARACTER_MAXIMUM_LENGTH'] : null,
                        'nullable' => strtoupper((string) ($col['IS_NULLABLE'] ?? 'YES')) === 'YES',
                        'pii_detected' => $piiResult['is_pii'],
                        'pdp_category' => $piiResult['pdp_category'],
                        'classification' => $piiResult['classification'],
                        'encryption_required' => $piiResult['encryption_required'],
                        'encrypted' => $protection['protection_state'] === 'encrypted' || $typeEncrypted,
                        'pii_reason' => $piiResult['reason'],
                        'protection_state' => $protection['protection_state'],
                        'protection_reason' => $protection['protection_reason'],
                        'manually_classified' => false,
                        'shadow_detected' => $shadowDetected,
                    ];
                }

                $tables[] = [
                    'name' => $tableName,
                    'columns' => $columns,
                    'row_count' => $rowCounts[$tableName] ?? 0,
                    'size_mb' => 0,
                ];
            }

            return [
                'tables' => $tables,
                'engine' => 'real_snowflake',
                'sampled_tables' => $sampled,
                'sampling_truncated' => count($grouped) > self::MAX_SAMPLED_TABLES,
            ];
        } catch (\Throwable $e) {
            Log::error('Snowflake scan failed: '.$e->getMessage());

            return ['tables' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Jalankan satu pernyataan dan kembalikan barisnya sebagai peta kolom→nilai.
     *
     * @return array{rows: array<int, array<string, mixed>>}
     */
    public static function statement(array $config, string $sql, int $timeoutSeconds = 60): array
    {
        $account = self::accountLocator($config['account']);
        $url = "https://{$account}.snowflakecomputing.com/api/v2/statements";

        $body = array_filter([
            'statement' => $sql,
            'timeout' => $timeoutSeconds,
            'database' => $config['database'] ?? null,
            'schema' => $config['schema'] ?? null,
            'warehouse' => $config['warehouse'] ?? null,
            'role' => $config['role'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.self::jwt($config),
            'X-Snowflake-Authorization-Token-Type' => 'KEYPAIR_JWT',
            'Accept' => 'application/json',
            'User-Agent' => 'Privasimu/1.0',
        ])->timeout($timeoutSeconds + 15)->post($url, $body);

        // Pernyataan yang belum selesai dijawab 202 beserta handle-nya. Ini
        // lumrah pada gudang data yang sedang dingin: warehouse perlu menyala
        // lebih dulu, dan itu bisa memakan belasan detik.
        if ($response->status() === 202) {
            $handle = $response->json('statementHandle');
            $response = self::poll($config, (string) $handle, $timeoutSeconds);
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?? $response->body();
            throw new \RuntimeException('Snowflake menolak permintaan ('.$response->status().'): '.mb_substr((string) $message, 0, 300));
        }

        $json = $response->json();
        $names = array_map(
            fn ($c) => (string) ($c['name'] ?? ''),
            $json['resultSetMetaData']['rowType'] ?? [],
        );

        $rows = [];
        foreach ($json['data'] ?? [] as $line) {
            $row = [];
            foreach ($names as $i => $name) {
                $row[$name] = $line[$i] ?? null;
            }
            $rows[] = $row;
        }

        return ['rows' => $rows];
    }

    private static function poll(array $config, string $handle, int $timeoutSeconds): \Illuminate\Http\Client\Response
    {
        $account = self::accountLocator($config['account']);
        $deadline = time() + $timeoutSeconds;
        $wait = 1;

        while (time() < $deadline) {
            sleep($wait);
            $wait = min($wait * 2, 8);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.self::jwt($config),
                'X-Snowflake-Authorization-Token-Type' => 'KEYPAIR_JWT',
                'Accept' => 'application/json',
            ])->timeout(30)->get("https://{$account}.snowflakecomputing.com/api/v2/statements/{$handle}");

            if ($response->status() !== 202) {
                return $response;
            }
        }

        throw new \RuntimeException('Snowflake tidak menyelesaikan pernyataan dalam '.$timeoutSeconds.' detik.');
    }

    /**
     * Susun JWT RS256 sesuai ketentuan Snowflake.
     *
     * Penerbitnya (`iss`) harus memuat sidik jari kunci PUBLIK dalam bentuk
     * SHA256 dari DER-nya. Inilah yang paling sering salah saat menyiapkan
     * key-pair: sidik jari dihitung dari kunci publik, bukan privat, dan dari
     * bentuk DER, bukan PEM.
     */
    private static function jwt(array $config): string
    {
        $pem = (string) ($config['private_key'] ?? '');
        $passphrase = $config['private_key_passphrase'] ?? null;

        $key = $passphrase
            ? openssl_pkey_get_private($pem, $passphrase)
            : openssl_pkey_get_private($pem);

        if ($key === false) {
            throw new \RuntimeException('Kunci privat tidak dapat dibaca. Pastikan format PEM (PKCS#8) dan frasa sandinya benar.');
        }

        $details = openssl_pkey_get_details($key);
        if (! $details || empty($details['key'])) {
            throw new \RuntimeException('Kunci publik tidak dapat diturunkan dari kunci privat.');
        }

        $publicDer = self::pemToDer((string) $details['key']);
        $fingerprint = 'SHA256:'.base64_encode(hash('sha256', $publicDer, true));

        $account = strtoupper(self::accountName($config['account']));
        $user = strtoupper((string) ($config['username'] ?? ''));
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => "{$account}.{$user}.{$fingerprint}",
            'sub' => "{$account}.{$user}",
            'iat' => $now,
            'exp' => $now + self::JWT_TTL,
        ];

        $signingInput = self::b64(json_encode($header)).'.'.self::b64(json_encode($payload));

        $signature = '';
        if (! openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Penandatanganan JWT gagal.');
        }

        return $signingInput.'.'.self::b64($signature);
    }

    private static function pemToDer(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END)[^-]+-----/', '', $pem) ?? '';

        return (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Locator untuk URL — memuat wilayah dan cloud, mis. "xy12345.ap-southeast-3.aws".
     */
    private static function accountLocator(string $account): string
    {
        return strtolower(trim($account));
    }

    /**
     * Nama akun untuk JWT — TANPA bagian wilayah/cloud.
     *
     * Snowflake menuntut bentuk yang berbeda di URL dan di token. Menyamakan
     * keduanya adalah penyebab galat "JWT token is invalid" yang paling sering,
     * dan pesan galatnya tidak pernah menyebut penyebabnya.
     */
    private static function accountName(string $account): string
    {
        $account = strtolower(trim($account));
        $dot = strpos($account, '.');

        return $dot === false ? $account : substr($account, 0, $dot);
    }

    /** Pengenal objek — huruf besar dan dikutip ganda, aman untuk nama bercampur huruf. */
    private static function ident(string $name): string
    {
        return '"'.str_replace('"', '""', trim($name)).'"';
    }

    /** Literal string SQL. */
    private static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    private static function typeLabel(array $col): string
    {
        $type = (string) ($col['DATA_TYPE'] ?? 'TEXT');
        $len = $col['CHARACTER_MAXIMUM_LENGTH'] ?? null;

        return $len ? $type.'('.$len.')' : $type;
    }

    /** @return array<int, string> */
    private static function missingConfig(array $config): array
    {
        $missing = [];
        foreach (['account', 'username', 'private_key', 'warehouse', 'database'] as $key) {
            if (empty($config[$key])) {
                $missing[] = $key;
            }
        }

        return $missing;
    }
}
