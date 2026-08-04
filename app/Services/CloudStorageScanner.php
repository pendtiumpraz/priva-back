<?php

namespace App\Services;

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pemindaian penyimpanan objek: AWS S3, Google Cloud Storage, dan Azure Blob.
 *
 * Sebelumnya seluruh berkas ini adalah simulasi — daftar berkas ditulis
 * langsung di kode, dan temuan PII-nya dibangkitkan dengan rand(). Pada
 * evaluasi PoC, hasil semacam itu jauh lebih merusak daripada fitur yang belum
 * ada: pengujinya mengambil kesimpulan di atas temuan yang tidak pernah
 * berasal dari bucket miliknya, dan tiap pemindaian ulang memberi angka
 * berbeda tanpa ada yang berubah.
 *
 * Ketiganya kini menyambung sungguhan:
 *
 *   S3    — aws-sdk-php (sudah menjadi dependensi), termasuk endpoint
 *           S3-compatible seperti MinIO, Wasabi, dan DigitalOcean Spaces.
 *   GCS   — antarmuka interoperability Google yang berbicara protokol S3
 *           dengan kunci HMAC. Itu jalur resmi Google, bukan akal-akalan.
 *   Azure — REST API dengan SAS token yang disediakan klien, sehingga kunci
 *           akun penuh tidak perlu disimpan di sistem ini.
 *
 * Isi berkas TIDAK diunduh seluruhnya. Yang dipindai adalah metadata objek
 * ditambah sampel kecil berkas teks — sama seperti perkakas DSPM pada umumnya,
 * karena mengunduh bucket berukuran terabyte bukan pilihan yang nyata.
 */
class CloudStorageScanner
{
    /** Berapa objek yang isinya benar-benar diambil untuk dipindai. */
    private const CONTENT_SAMPLE_LIMIT = 10;

    /** Batas ukuran potongan objek yang diunduh untuk pemindaian isi. */
    private const MAX_SAMPLE_BYTES = 262144; // 256 KB

    /** Berapa objek yang didaftar per bucket. */
    private const LIST_LIMIT = 1000;

    /** Ekstensi yang isinya layak dibaca sebagai teks. */
    private const TEXT_EXTENSIONS = ['csv', 'txt', 'json', 'log', 'tsv', 'xml', 'sql', 'yml', 'yaml', 'md'];

    // =====================================================================
    // AWS S3 dan endpoint S3-compatible
    // =====================================================================

    public static function scanS3(array $config): array
    {
        return self::scanViaS3Protocol($config, 'real_aws_s3');
    }

    // =====================================================================
    // Google Cloud Storage — lewat antarmuka interoperability (protokol S3)
    // =====================================================================

    public static function scanGcs(array $config): array
    {
        if (empty($config['access_key'] ?? $config['key'] ?? null)) {
            return [
                'tables' => [],
                'error' => 'GCS dipindai lewat antarmuka interoperability Google, sehingga dibutuhkan '
                    .'kunci HMAC (access key dan secret). Buat di Cloud Storage → Settings → Interoperability.',
            ];
        }

        return self::scanViaS3Protocol(array_merge($config, [
            'endpoint' => $config['endpoint'] ?? 'https://storage.googleapis.com',
            'region' => $config['region'] ?? 'auto',
            'use_path_style_endpoint' => true,
        ]), 'real_gcs');
    }

    /** Jalur bersama S3 dan GCS — keduanya berbicara protokol yang sama. */
    private static function scanViaS3Protocol(array $config, string $engine): array
    {
        if (! class_exists(S3Client::class)) {
            return self::driverMissing('penyimpanan objek', 'aws/aws-sdk-php');
        }

        $bucket = $config['bucket'] ?? null;
        if (! $bucket) {
            return ['tables' => [], 'error' => 'Nama bucket wajib diisi.'];
        }

        try {
            $client = new S3Client(array_filter([
                'version' => 'latest',
                'region' => $config['region'] ?? 'ap-southeast-1',
                'endpoint' => $config['endpoint'] ?? null,
                'use_path_style_endpoint' => (bool) ($config['use_path_style_endpoint'] ?? false),
                'credentials' => [
                    'key' => $config['access_key'] ?? $config['key'] ?? '',
                    'secret' => $config['secret_key'] ?? $config['secret'] ?? '',
                ],
            ], fn ($v) => $v !== null));

            $objects = [];
            $token = null;
            do {
                $result = $client->listObjectsV2(array_filter([
                    'Bucket' => $bucket,
                    'Prefix' => $config['prefix'] ?? null,
                    'MaxKeys' => 1000,
                    'ContinuationToken' => $token,
                ], fn ($v) => $v !== null));

                foreach ($result['Contents'] ?? [] as $obj) {
                    $objects[] = [
                        'key' => $obj['Key'],
                        'size' => (int) ($obj['Size'] ?? 0),
                        'modified' => isset($obj['LastModified']) ? (string) $obj['LastModified'] : null,
                    ];
                    if (count($objects) >= self::LIST_LIMIT) {
                        break 2;
                    }
                }
                $token = $result['NextContinuationToken'] ?? null;
            } while ($token);

            $fetch = fn (string $key) => (string) $client->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Range' => 'bytes=0-'.(self::MAX_SAMPLE_BYTES - 1),
            ])['Body'];

            return self::buildResult($bucket, $objects, $engine, $fetch);
        } catch (\Throwable $e) {
            Log::error('Pemindaian penyimpanan objek gagal: '.$e->getMessage());

            return ['tables' => [], 'error' => $e->getMessage()];
        }
    }

    // =====================================================================
    // Azure Blob Storage — REST dengan SAS token
    // =====================================================================

    public static function scanAzureBlob(array $config): array
    {
        $account = $config['account'] ?? null;
        $container = $config['container'] ?? $config['bucket'] ?? null;
        $sas = $config['sas_token'] ?? null;

        if (! $account || ! $container) {
            return ['tables' => [], 'error' => 'Nama account dan container wajib diisi.'];
        }
        if (! $sas) {
            return [
                'tables' => [],
                'error' => 'Azure Blob dipindai memakai SAS token beizin baca dan daftar. SAS dipilih '
                    .'agar kunci akun penuh tidak perlu disimpan di sistem ini.',
            ];
        }

        $sas = ltrim((string) $sas, '?');
        $base = "https://{$account}.blob.core.windows.net/{$container}";

        try {
            $response = Http::timeout(30)->get($base.'?restype=container&comp=list&'.$sas);
            if (! $response->successful()) {
                return ['tables' => [], 'error' => 'Azure menolak permintaan: HTTP '.$response->status()];
            }

            $xml = @simplexml_load_string($response->body());
            if ($xml === false) {
                return ['tables' => [], 'error' => 'Balasan Azure tidak dapat dibaca sebagai XML.'];
            }

            $objects = [];
            foreach ($xml->Blobs->Blob ?? [] as $blob) {
                $objects[] = [
                    'key' => (string) $blob->Name,
                    'size' => (int) ($blob->Properties->{'Content-Length'} ?? 0),
                    'modified' => (string) ($blob->Properties->{'Last-Modified'} ?? ''),
                ];
                if (count($objects) >= self::LIST_LIMIT) {
                    break;
                }
            }

            $fetch = function (string $key) use ($base, $sas) {
                $res = Http::timeout(20)
                    ->withHeaders(['Range' => 'bytes=0-'.(self::MAX_SAMPLE_BYTES - 1)])
                    ->get($base.'/'.ltrim($key, '/').'?'.$sas);

                return $res->successful() ? $res->body() : '';
            };

            return self::buildResult($container, $objects, 'real_azure_blob', $fetch);
        } catch (\Throwable $e) {
            Log::error('Pemindaian Azure Blob gagal: '.$e->getMessage());

            return ['tables' => [], 'error' => $e->getMessage()];
        }
    }

    // =====================================================================
    // Penyusunan hasil
    // =====================================================================

    /**
     * Susun hasil pemindaian dari daftar objek nyata.
     *
     * Bentuk keluarannya sengaja mengikuti bentuk pemindaian basis data —
     * "tabel" berisi "kolom" — supaya konsumen di hilir (ColumnAutoAssigner,
     * klasifikasi manual, ekspor) bekerja tanpa perubahan. Di sini satu folder
     * berperan sebagai tabel, dan satu berkas berperan sebagai kolom.
     *
     * @param  array<int, array{key: string, size: int, modified: ?string}>  $objects
     * @param  callable(string): string  $fetch
     */
    private static function buildResult(string $bucket, array $objects, string $engine, callable $fetch): array
    {
        if (empty($objects)) {
            return [
                'tables' => [],
                'engine' => $engine,
                'error' => 'Tidak ada objek ditemukan. Periksa nama bucket/container, prefiks, dan hak akses kredensialnya.',
            ];
        }

        // Objek teks terkecil dipilih lebih dulu untuk pemindaian isi: berkas
        // besar hampir selalu arsip atau media, dan mengunduhnya menghabiskan
        // jatah sampel tanpa menambah temuan.
        $textCandidates = array_values(array_filter(
            $objects,
            fn ($o) => in_array(strtolower(pathinfo($o['key'], PATHINFO_EXTENSION)), self::TEXT_EXTENSIONS, true)
                && $o['size'] > 0
        ));
        usort($textCandidates, fn ($a, $b) => $a['size'] <=> $b['size']);
        $sampled = array_slice($textCandidates, 0, self::CONTENT_SAMPLE_LIMIT);
        $sampledKeys = array_column($sampled, 'key');

        $contentFindings = [];
        foreach ($sampled as $obj) {
            try {
                $body = $fetch($obj['key']);
                if ($body === '') {
                    continue;
                }
                $finding = ContentPiiScanner::analyzeColumnContent(self::tokenize($body));
                if ($finding) {
                    $contentFindings[$obj['key']] = $finding;
                }
            } catch (\Throwable $e) {
                Log::info('Sampel objek gagal diambil: '.$obj['key'].' — '.$e->getMessage());
            }
        }

        // Kelompokkan objek menurut folder tingkat pertama.
        $groups = [];
        foreach ($objects as $obj) {
            $parts = explode('/', $obj['key']);
            $folder = count($parts) > 1 ? $parts[0] : '(root)';
            $groups[$folder][] = $obj;
        }

        $tables = [];
        foreach ($groups as $folder => $items) {
            $columns = [];
            foreach ($items as $obj) {
                $name = $obj['key'];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'bin';

                // Deteksi berbasis NAMA berkas selalu dijalankan; deteksi
                // berbasis ISI hanya untuk objek yang benar-benar diambil.
                $byName = PiiDetector::analyze(basename($name), $ext);
                $byContent = $contentFindings[$name] ?? null;
                $result = $byContent ?: $byName;
                $wasSampled = in_array($name, $sampledKeys, true);

                $columns[] = [
                    'name' => $name,
                    'alias' => null,
                    'type' => $ext.' ('.self::humanSize($obj['size']).')',
                    'type_length' => null,
                    'nullable' => false,
                    'pii_detected' => $result['is_pii'],
                    'pdp_category' => $result['pdp_category'],
                    'classification' => $result['classification'],
                    'encryption_required' => $result['encryption_required'],
                    'encrypted' => false,
                    'pii_reason' => $result['reason'],
                    // Objek yang isinya tidak diambil ditandai jujur sebagai
                    // BELUM diperiksa, bukan sebagai bersih. Menyamakan
                    // keduanya akan melaporkan bucket sebagai aman padahal
                    // sebagian besar isinya tidak pernah dibaca.
                    'protection_state' => $wasSampled ? 'scanned' : 'not_sampled',
                    'protection_reason' => $wasSampled
                        ? 'Isi berkas diperiksa pada sampel.'
                        : 'Hanya nama berkas yang diperiksa; isinya belum diambil.',
                    'manually_classified' => false,
                    'shadow_detected' => $byContent !== null,
                    'size_bytes' => $obj['size'],
                    'last_modified' => $obj['modified'],
                ];
            }

            $tables[] = [
                'name' => $folder,
                'columns' => $columns,
                'row_count' => count($items),
                'size_mb' => round(array_sum(array_column($items, 'size')) / 1048576, 2),
            ];
        }

        return [
            'tables' => $tables,
            'engine' => $engine,
            'bucket' => $bucket,
            'objects_listed' => count($objects),
            'objects_content_scanned' => count($sampled),
            // Dinyatakan terang-terangan: pemindaian isi bersifat SAMPEL.
            // Angka inilah yang membedakan "bucket bersih" dari "bucket yang
            // sebagian besar isinya belum pernah dibaca".
            'content_scan_note' => count($objects) > count($sampled)
                ? 'Isi diperiksa pada '.count($sampled).' dari '.count($objects)
                    .' objek. Sisanya diklasifikasi berdasarkan nama berkas saja.'
                : null,
        ];
    }

    /**
     * Pecah isi berkas menjadi nilai-nilai yang dapat diuji terhadap pola PII.
     *
     * CSV dipecah per sel karena pola seperti NIK dirancang mencocokkan satu
     * nilai utuh, bukan satu baris penuh. Tanpa pemecahan ini, berkas CSV
     * berisi NIK tidak akan pernah cocok dengan polanya sendiri.
     *
     * @return array<int, string>
     */
    private static function tokenize(string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $body) ?: [];
        $values = [];

        foreach (array_slice($lines, 0, 200) as $line) {
            foreach (preg_split('/[,;\t|"]+/', $line) ?: [] as $cell) {
                $cell = trim($cell);
                if ($cell !== '' && mb_strlen($cell) <= 200) {
                    $values[] = $cell;
                }
            }
            if (count($values) > 2000) {
                break;
            }
        }

        return $values;
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024).' KB';
        }

        return $bytes.' B';
    }

    private static function driverMissing(string $label, string $package): array
    {
        return [
            'tables' => [],
            'error' => "Pustaka untuk {$label} tidak tersedia (dibutuhkan {$package}).",
            'driver_missing' => true,
        ];
    }
}
