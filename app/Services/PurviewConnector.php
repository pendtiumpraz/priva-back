<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Konektor Microsoft Purview lewat Data Map REST API.
 *
 * Sebelumnya katalog hanya menerima ekspor Purview yang ditempel manual. Itu
 * berfungsi, tetapi searah dan sekali jalan: begitu aset berubah di Purview,
 * katalog di sini tidak ikut berubah, dan tidak ada yang tahu sejak kapan.
 *
 * Autentikasinya service principal Azure AD (OAuth2 client credentials).
 * Kunci yang dibutuhkan klien: `tenant_id`, `client_id`, `client_secret`, dan
 * service principal itu harus diberi peran **Data Reader** pada koleksi
 * Purview yang bersangkutan. Tanpa peran itu tokennya terbit tetapi setiap
 * permintaan dibalas 403 — dan pesan galat Azure tidak pernah menyebut peran
 * sebagai penyebabnya, jadi di sini disebutkan terang-terangan.
 *
 * Jaringannya hanya menuntut HTTPS 443 keluar ke `*.purview.azure.com` dan
 * `login.microsoftonline.com`. Jauh lebih mudah lolos daripada porta basis
 * data, yang di kebanyakan jaringan korporat memang tertutup.
 *
 * Yang diambil hanya METADATA — nama aset, tipe, klasifikasi, pemilik, dan
 * silsilah. Tidak ada satu pun nilai data yang dibaca; Purview sendiri memang
 * tidak menyimpannya.
 */
class PurviewConnector
{
    /** Aset per halaman pencarian. Purview membatasi 1000; 500 lebih aman terhadap timeout. */
    private const PAGE_SIZE = 500;

    /** Batas aset per sinkronisasi, supaya katalog raksasa tidak menggantung permintaan. */
    private const MAX_ASSETS = 10000;

    public function __construct(
        private string $tenantId,
        private string $clientId,
        private string $clientSecret,
        private string $accountName,
    ) {}

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        foreach (['tenant_id', 'client_id', 'client_secret', 'account_name'] as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Konfigurasi Purview belum lengkap: {$key}");
            }
        }

        return new self(
            (string) $config['tenant_id'],
            (string) $config['client_id'],
            (string) $config['client_secret'],
            (string) $config['account_name'],
        );
    }

    /**
     * Uji kredensial tanpa menarik apa pun.
     *
     * Sengaja memanggil pencarian berukuran 1, bukan sekadar meminta token.
     * Token terbit selama kredensialnya benar — ia tidak membuktikan service
     * principal punya peran Data Reader. Kesalahan peran hanya muncul saat
     * permintaan pertama ke Purview, dan itulah kesalahan yang paling sering
     * terjadi saat penyiapan.
     */
    public function testConnection(): array
    {
        try {
            $start = microtime(true);
            $page = $this->searchPage('', 1, 0);

            return [
                'success' => true,
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'note' => 'Tersambung ke Purview "'.$this->accountName.'". Total aset terlihat: '.($page['@search.count'] ?? 0).'.',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Tarik aset beserta silsilahnya, dalam bentuk yang langsung diterima
     * DataCatalogService::import().
     *
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    public function fetchAssets(?string $keyword = null): array
    {
        $rows = [];
        $offset = 0;
        $truncated = false;

        while (count($rows) < self::MAX_ASSETS) {
            $page = $this->searchPage($keyword ?? '', self::PAGE_SIZE, $offset);
            $items = $page['value'] ?? [];

            if (! $items) {
                break;
            }

            foreach ($items as $item) {
                $rows[] = $this->normalize($item);
            }

            $offset += count($items);

            if (count($items) < self::PAGE_SIZE) {
                break;
            }
            if (count($rows) >= self::MAX_ASSETS) {
                $truncated = true;
                break;
            }
        }

        // Buang rujukan induk yang asetnya tidak ikut tertarik.
        //
        // qualifiedName bersifat hierarkis, jadi memotong ruas terakhir selalu
        // menghasilkan "induk" — termasuk untuk aset teratas, yang induknya
        // (mis. nama server) tidak pernah dikembalikan Purview sebagai aset.
        // Dibiarkan, tiap sinkronisasi menambah tepi silsilah yang menunjuk
        // aset tidak ada: peta terlihat punya simpul yang tidak bisa dibuka,
        // dan tidak ada satu pun pesan galat yang menjelaskannya.
        //
        // Hal yang sama terjadi saat pencarian dipersempit kata kunci atau
        // terpotong batas halaman — induknya nyata di Purview, tetapi tidak
        // ada di tarikan ini.
        $present = array_flip(array_column($rows, 'id'));
        foreach ($rows as $i => $row) {
            if (isset($row['parent_id']) && ! isset($present[$row['parent_id']])) {
                unset($rows[$i]['parent_id']);
            }
        }

        return ['rows' => array_values($rows), 'truncated' => $truncated];
    }

    /**
     * Ubah satu aset Purview menjadi bentuk baku katalog.
     *
     * `parent_id` diisi dari qualifiedName induknya bila ada, sehingga silsilah
     * "sistem → dataset → kolom" terbentuk tanpa memanggil API lineage satu per
     * satu. Untuk katalog besar, memanggil lineage per aset akan menghasilkan
     * ribuan permintaan dan hampir selalu kena batas laju.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalize(array $item): array
    {
        $qualified = (string) ($item['qualifiedName'] ?? '');

        // Klasifikasi Purview berupa daftar; yang menyangkut data pribadi
        // dipetakan ke kosakata kami, sisanya dibiarkan apa adanya supaya
        // istilah milik klien tidak hilang.
        $classifications = array_map('strval', $item['classification'] ?? []);
        $lower = strtolower(implode(' ', $classifications));
        $mapped = null;
        if ($lower !== '') {
            // Dicocokkan dengan nama klasifikasi bawaan Purview, yang berbentuk
            // MICROSOFT.PERSONAL.NATIONAL_ID, MICROSOFT.FINANCIAL.CREDIT_CARD_NUMBER,
            // MICROSOFT.GOVERNMENT.*, dan seterusnya.
            //
            // Urutannya menentukan: hampir SEMUA klasifikasi Purview memuat kata
            // "personal", jadi bila daftar pii diperiksa lebih dulu, NIK pun akan
            // turun menjadi sekadar data pribadi umum. Yang spesifik diperiksa
            // duluan, dan itu penting karena Pasal 4 UU PDP membedakan keduanya
            // beserta kewajiban yang mengikutinya.
            $sensitive = [
                'national', 'nik', 'ktp', 'passport', 'paspor', 'government', 'tax', 'npwp',
                'health', 'healthcare', 'medical', 'kesehatan', 'biometric', 'genetic',
                'financial', 'credit card', 'credit_card', 'bank account', 'bank_account', 'iban', 'swift',
                'driver', 'sim', 'religio', 'ethnic', 'criminal', 'sexual', 'political',
            ];
            $pii = ['person', 'personal', 'pii', 'email', 'phone', 'name', 'address', 'contact', 'ip_address'];
            foreach ($sensitive as $needle) {
                if (str_contains($lower, $needle)) { $mapped = 'sensitive'; break; }
            }
            if (! $mapped) {
                foreach ($pii as $needle) {
                    if (str_contains($lower, $needle)) { $mapped = 'pii'; break; }
                }
            }
        }

        return array_filter([
            // qualifiedName dipakai sebagai kunci, BUKAN GUID — dan ini bukan
            // preferensi gaya. Induk sebuah aset diturunkan dari qualifiedName
            // (lihat parentQualifiedName), jadi memakai GUID sebagai kunci
            // membuat setiap tepi silsilah menunjuk kunci yang tidak pernah
            // ada: hierarkinya terbentuk tetapi seluruhnya menggantung.
            //
            // qualifiedName juga lebih baik sebagai identitas: ia stabil,
            // terbaca manusia, dan tetap sama bila aset dibuat ulang di
            // Purview — sedangkan GUID berubah.
            'id' => $qualified !== '' ? $qualified : (string) ($item['id'] ?? ''),
            'purview_guid' => $item['id'] ?? null,
            'name' => (string) ($item['name'] ?? $qualified),
            'type' => $this->mapAssetType((string) ($item['entityType'] ?? '')),
            'qualified_name' => $qualified ?: null,
            'description' => $item['description'] ?? null,
            'classification' => $mapped,
            'steward' => $this->firstOwner($item),
            'parent_id' => $this->parentQualifiedName($qualified),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Petakan entityType Purview ke kosakata aset kami.
     *
     * Purview punya ratusan tipe khusus per sumber (azure_sql_table,
     * hive_table, s3_bucket, …). Memetakannya satu per satu akan basi begitu
     * Microsoft menambah tipe baru, jadi pemetaannya berdasarkan akhiran.
     */
    private function mapAssetType(string $entityType): string
    {
        $t = strtolower($entityType);

        return match (true) {
            str_ends_with($t, '_column') || str_contains($t, 'column') => 'field',
            str_ends_with($t, '_table') || str_contains($t, 'table') => 'dataset',
            str_contains($t, 'view') => 'dataset',
            str_contains($t, 'file') || str_contains($t, 'blob') || str_contains($t, 'path') => 'file',
            str_contains($t, 'report') || str_contains($t, 'dashboard') => 'report',
            str_contains($t, 'db') || str_contains($t, 'database') || str_contains($t, 'server') || str_contains($t, 'account') => 'system',
            default => 'dataset',
        };
    }

    /**
     * Turunkan qualifiedName induk dengan memotong ruas terakhir.
     *
     * qualifiedName Purview berbentuk hierarkis, mis.
     * mssql://srv/db/dbo/customers#nik → induknya .../customers.
     */
    private function parentQualifiedName(string $qualified): ?string
    {
        if ($qualified === '') {
            return null;
        }

        foreach (['#', '/'] as $sep) {
            $pos = strrpos($qualified, $sep);
            if ($pos !== false && $pos > 8) {
                $parent = substr($qualified, 0, $pos);
                if ($parent !== '' && $parent !== $qualified) {
                    return $parent;
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    private function firstOwner(array $item): ?string
    {
        foreach (['owner', 'contact'] as $key) {
            $value = $item[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value) && $value !== []) {
                $first = reset($value);

                return is_string($first) ? $first : ($first['id'] ?? null);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function searchPage(string $keyword, int $limit, int $offset): array
    {
        $endpoint = "https://{$this->accountName}.purview.azure.com/datamap/api/search/query?api-version=2023-09-01";

        $response = Http::withToken($this->token())
            ->timeout(60)
            ->post($endpoint, array_filter([
                'keywords' => $keyword !== '' ? $keyword : null,
                'limit' => $limit,
                'offset' => $offset,
            ], fn ($v) => $v !== null));

        if ($response->status() === 403) {
            throw new \RuntimeException(
                'Purview menolak (403). Service principal sudah terautentikasi tetapi belum diberi peran '.
                '"Data Reader" pada koleksi Purview. Peran itu diberikan di Purview Studio → Data map → Collections → Role assignments.'
            );
        }

        if (! $response->successful()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new \RuntimeException('Purview menolak permintaan ('.$response->status().'): '.mb_substr((string) $message, 0, 300));
        }

        return $response->json() ?? [];
    }

    /**
     * Token OAuth2 client-credentials, disimpan sementara.
     *
     * Token Azure berumur sekitar satu jam. Menarik ulang tiap permintaan
     * berarti satu bulak-balik tambahan ke login.microsoftonline.com untuk
     * setiap halaman pencarian — pada katalog 10.000 aset itu 20 permintaan
     * yang seluruhnya sia-sia. Kunci cache memuat client_id, jadi dua tenant
     * tidak pernah berbagi token.
     */
    private function token(): string
    {
        $cacheKey = 'purview_token:'.sha1($this->tenantId.'|'.$this->clientId);

        return Cache::remember($cacheKey, 3000, function () {
            $response = Http::asForm()->timeout(30)->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://purview.azure.net/.default',
                ],
            );

            if (! $response->successful()) {
                $message = $response->json('error_description') ?? $response->body();
                throw new \RuntimeException('Azure AD menolak kredensial: '.mb_substr((string) $message, 0, 300));
            }

            $token = $response->json('access_token');
            if (! $token) {
                throw new \RuntimeException('Azure AD tidak mengembalikan access token.');
            }

            return (string) $token;
        });
    }
}
