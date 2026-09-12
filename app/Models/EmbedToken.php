<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Token embed per tampilan (lihat migrasi 2026_09_12_000006).
 *
 * Daftar putih kolom di kelas ini adalah satu-satunya penentu apa yang boleh
 * keluar ke halaman publik. Permintaan penerbitan diiris dengan daftar ini,
 * jadi menambah kolom sensitif ke sebuah token mustahil dilakukan dari klien —
 * harus lewat perubahan kode yang terbaca di ulasan.
 *
 * `wizard_data`, `raci_matrix`, `assignees`, `created_by`, dan `review_notes`
 * TIDAK pernah masuk daftar putih: isinya kerja internal dan kontak orang.
 */
class EmbedToken extends Model
{
    use BelongsToOrg, HasUuids;

    public const MODULE_ROPA = 'ropa';

    public const MODULE_DPIA = 'dpia';

    public const MODULES = [self::MODULE_ROPA, self::MODULE_DPIA];

    /** Kolom yang boleh diminta tampil, per modul. */
    public const ALLOWED_FIELDS = [
        self::MODULE_ROPA => [
            'registration_number', 'processing_activity', 'entity', 'division', 'work_unit',
            'purpose', 'legal_basis', 'risk_level', 'status', 'retention_period',
            'data_categories', 'data_subjects', 'recipients', 'security_measures',
            'created_at', 'updated_at',
        ],
        self::MODULE_DPIA => [
            'registration_number', 'risk_level', 'status', 'description',
            'approved_at', 'created_at', 'updated_at',
        ],
    ];

    /** Bawaan bila penerbit tidak memilih kolom — sengaja sempit. */
    public const DEFAULT_FIELDS = [
        self::MODULE_ROPA => [
            'registration_number', 'processing_activity', 'purpose', 'legal_basis', 'risk_level',
        ],
        self::MODULE_DPIA => [
            'registration_number', 'risk_level', 'status',
        ],
    ];

    /** Penyaring baris yang boleh dipakai, per modul. */
    public const ALLOWED_FILTERS = [
        self::MODULE_ROPA => ['status', 'risk_level', 'division', 'entity'],
        self::MODULE_DPIA => ['status', 'risk_level'],
    ];

    public const FIELD_LABELS = [
        'registration_number' => 'Nomor',
        'processing_activity' => 'Kegiatan Pemrosesan',
        'entity' => 'Entitas',
        'division' => 'Divisi',
        'work_unit' => 'Unit Kerja',
        'purpose' => 'Tujuan',
        'legal_basis' => 'Dasar Hukum',
        'risk_level' => 'Tingkat Risiko',
        'status' => 'Status',
        'retention_period' => 'Masa Retensi',
        'data_categories' => 'Kategori Data',
        'data_subjects' => 'Subjek Data',
        'recipients' => 'Penerima',
        'security_measures' => 'Langkah Keamanan',
        'description' => 'Deskripsi',
        'approved_at' => 'Disetujui',
        'created_at' => 'Dibuat',
        'updated_at' => 'Diperbarui',
    ];

    protected $fillable = [
        'org_id', 'module', 'label', 'token', 'fields', 'filters',
        'allowed_origins', 'expires_at', 'revoked_at', 'last_used_at',
        'view_count', 'created_by',
    ];

    protected $casts = [
        'fields' => 'array',
        'filters' => 'array',
        'allowed_origins' => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
        'view_count' => 'integer',
    ];

    /** Token mentah tidak pernah ikut serialisasi tak sengaja. */
    protected $hidden = ['token'];

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::withoutGlobalScope('org')->where('token', $token)->exists());

        return $token;
    }

    /**
     * Iris daftar kolom permintaan dengan daftar putih modulnya.
     * Mengembalikan bawaan bila tidak ada yang tersisa — sebuah token tanpa
     * kolom sama sekali hanya akan tampak seperti kerusakan bagi pemasangnya.
     *
     * @param  array<int, mixed>|null  $requested
     * @return array<int, string>
     */
    public static function sanitizeFields(string $module, ?array $requested): array
    {
        $allowed = self::ALLOWED_FIELDS[$module] ?? [];
        $bersih = array_values(array_intersect(
            array_map('strval', $requested ?? []),
            $allowed
        ));

        return $bersih ?: (self::DEFAULT_FIELDS[$module] ?? []);
    }

    /**
     * Buang penyaring yang tidak dikenal supaya tidak ada kolom sembarang
     * yang ikut jadi klausa WHERE.
     *
     * @param  array<string, mixed>|null  $requested
     * @return array<string, string>
     */
    public static function sanitizeFilters(string $module, ?array $requested): array
    {
        $allowed = self::ALLOWED_FILTERS[$module] ?? [];
        $bersih = [];
        foreach ($requested ?? [] as $key => $value) {
            if (in_array($key, $allowed, true) && is_scalar($value) && $value !== '') {
                $bersih[$key] = (string) $value;
            }
        }

        return $bersih;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Apakah origin pemasang iframe diizinkan. `allowed_origins` kosong berarti
     * tidak dibatasi — sama seperti bawaan widget DSR.
     */
    public function allowsOrigin(?string $origin): bool
    {
        $daftar = $this->allowed_origins ?? [];
        if (empty($daftar)) {
            return true;
        }

        return $origin !== null && in_array(rtrim($origin, '/'), array_map(
            fn ($o) => rtrim((string) $o, '/'),
            $daftar
        ), true);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
