<?php

namespace App\Models;

use App\Services\Pesan\KanalPesan;
use App\Services\Verifikasi\RegistriPenyedia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Katalog metode verifikasi wali — Pasal 38 ayat (4).
 *
 * Ayat itu berbunyi "dengan mempertimbangkan teknologi yang tersedia", yang
 * artinya standarnya BERGERAK. Karena itu ini tabel, bukan enum di kode: satu
 * dropdown keras akan disetel sekali lalu basi diam-diam, dan `review_at`
 * memaksa tenant meninjaunya kembali.
 *
 * SENGAJA TIDAK memakai trait BelongsToOrg. Baris dengan `org_id` NULL adalah
 * bawaan platform yang harus terlihat SEMUA tenant; global scope `org` akan
 * menyaringnya habis dan katalognya kosong di mana-mana. Penyaringan tenant
 * dilakukan lewat scope `untukOrg()` di bawah, yang menggabungkan bawaan
 * platform dengan milik tenant sendiri.
 *
 * @property string|null $org_id
 * @property string $code
 * @property string $label
 * @property string $driver
 * @property string $confidence
 * @property bool $is_active
 * @property Carbon|null $review_at
 * @property array<string, mixed>|null $config
 */
class VerificationMethod extends Model
{
    use HasUuids;

    /** Driver = siapa yang benar-benar melakukan verifikasinya. */
    public const DRIVER_OTP = 'otp';

    public const DRIVER_DUKCAPIL = 'dukcapil';

    public const DRIVER_EKYC = 'ekyc';

    /** Simulasi — hanya di luar produksi (lihat RegistriPenyedia). */
    public const DRIVER_MOCK = 'mock';

    /**
     * PERNYATAAN tenant: tenant sudah memverifikasi walinya sendiri (KYC
     * internal bank, akta di sekolah) dan menyatakannya lewat Partner API.
     * Kami tidak memeriksa apa pun — yang tercatat adalah siapa yang
     * menyatakan, dengan keyakinan yang ia nyatakan pada metodenya sendiri.
     * Tidak pernah ditawarkan widget.
     */
    public const DRIVER_TENANT = 'tenant_asserted';

    public const DRIVERS = [self::DRIVER_OTP, self::DRIVER_DUKCAPIL, self::DRIVER_EKYC, self::DRIVER_MOCK, self::DRIVER_TENANT];

    /**
     * Driver KUAT membuktikan IDENTITAS wali lewat penyedia (Dukcapil, e-KYC);
     * OTP hanya membuktikan penguasaan kanal. Bedanya adalah beda keyakinan.
     */
    public const DRIVER_KUAT = [self::DRIVER_DUKCAPIL, self::DRIVER_EKYC, self::DRIVER_MOCK];

    /** Placeholder klaim di templat badan permintaan driver HTTP. */
    public const PLACEHOLDER_KLAIM = ['{nik}', '{name}', '{birth_date}'];

    public const TIMEOUT_BAWAAN = 10;

    public const TIMEOUT_MAKS = 30;

    /** Nilai pengganti kredensial di respons API; bila dikirim balik, nilai lama dipertahankan. */
    public const TERSAMAR = '••••';

    /**
     * Tingkat keyakinan — dinyatakan apa adanya, dan ini penting.
     *
     * OTP ke HP/email TIDAK membuktikan orang itu wali. Yang dibuktikannya:
     * seseorang yang menguasai kanal itu sengaja menyetujui, dan bisa dimintai
     * pertanggungjawaban. Menamainya "terverifikasi" tanpa kualifikasi adalah
     * kebohongan yang sama bentuknya dengan `guardian_mode` dulu.
     */
    public const KEYAKINAN = ['rendah', 'sedang', 'tinggi'];

    protected $fillable = [
        'org_id', 'code', 'label', 'driver', 'confidence',
        'is_active', 'review_at', 'config',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'review_at' => 'date',
    ];

    /**
     * Kredensial milik tenant (mis. API key Dukcapil) — terenkripsi saat
     * disimpan, tidak pernah ikut terserialisasi ke respons API.
     */
    protected $hidden = ['config'];

    public function getConfigAttribute($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $isi = json_decode(Crypt::decryptString($value), true);
        } catch (\Throwable $e) {
            // Baris lama plaintext, atau kunci berubah — jangan jatuhkan aplikasi.
            $isi = json_decode((string) $value, true);
        }

        return is_array($isi) ? $isi : null;
    }

    /** @param array<string, mixed>|null $value */
    public function setConfigAttribute($value): void
    {
        $this->attributes['config'] = $value === null
            ? null
            : Crypt::encryptString(json_encode($value));
    }

    /**
     * Metode yang berlaku untuk satu organisasi: bawaan platform + miliknya.
     *
     * @param  Builder<VerificationMethod>  $query
     */
    public function scopeUntukOrg(Builder $query, ?string $orgId): void
    {
        $query->where(function ($w) use ($orgId) {
            $w->whereNull('org_id');
            if ($orgId !== null) {
                $w->orWhere('org_id', $orgId);
            }
        });
    }

    /** rendah → 0, sedang → 1, tinggi → 2; tak dikenal → 0. Untuk membandingkan dua verifikasi. */
    public static function peringkatKeyakinan(?string $keyakinan): int
    {
        $i = array_search($keyakinan, self::KEYAKINAN, true);

        return $i === false ? 0 : (int) $i;
    }

    public function kuat(): bool
    {
        return in_array($this->driver, self::DRIVER_KUAT, true);
    }

    /** Metode pernyataan tenant — hanya lewat Partner API `guardian/assert`. */
    public function dinyatakanTenant(): bool
    {
        return $this->driver === self::DRIVER_TENANT;
    }

    /**
     * Driver yang boleh dibuat tenant di katalognya: metode kuat (sesuai
     * lingkungan) + pernyataan tenant. OTP milik platform.
     *
     * @return list<string>
     */
    public static function driverDibuatTenant(): array
    {
        return [...RegistriPenyedia::driverKuat(), self::DRIVER_TENANT];
    }

    /** Baris `org_id` NULL: milik platform, terlihat semua tenant, hanya bisa dibaca tenant. */
    public function bawaanPlatform(): bool
    {
        return $this->org_id === null;
    }

    /**
     * Bisa benar-benar dijalankan sistem SAAT INI — bukan sekadar terdaftar.
     *
     * Config publik widget hanya menawarkan yang lolos di sini: `otp_phone`
     * belum punya driver, metode Dukcapil tanpa endpoint belum bisa memeriksa
     * apa pun, dan `mock` tidak ada di produksi. Menawarkan metode yang tidak
     * bisa dijalankan sama dengan `guardian_mode` versi lama: sakelar yang
     * tersimpan tanpa mengubah apa pun.
     */
    public function dapatDijalankan(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($this->driver) {
            // Surel selalu ada; telepon hanya bila kanal pesan platform hidup.
            self::DRIVER_OTP => $this->code === 'otp_email'
                || ($this->code === 'otp_phone' && KanalPesan::tersedia()),
            self::DRIVER_DUKCAPIL, self::DRIVER_EKYC => trim((string) ($this->config['endpoint'] ?? '')) !== ''
                && ! empty($this->config['match_all']),
            self::DRIVER_MOCK => ! RegistriPenyedia::produksi(),
            default => false,
        };
    }

    /**
     * Bentuk config yang boleh keluar lewat API: pemetaan kontrak penyedia,
     * TANPA kredensial. Header selalu disamarkan; nilai badan hanya ditampilkan
     * bila ia placeholder klaim — selebihnya (user_id, password) disamarkan.
     * Daftar NIK simulasi tidak pernah keluar, hanya jumlahnya.
     *
     * @return array<string, mixed>
     */
    public function configPublik(): array
    {
        $c = $this->config ?? [];

        $badan = [];
        foreach ((array) ($c['body'] ?? []) as $k => $v) {
            $badan[(string) $k] = is_string($v) && in_array($v, self::PLACEHOLDER_KLAIM, true) ? $v : self::TERSAMAR;
        }

        return [
            'endpoint' => $c['endpoint'] ?? null,
            'method' => strtoupper((string) ($c['method'] ?? 'POST')),
            'timeout' => (int) ($c['timeout'] ?? self::TIMEOUT_BAWAAN),
            'birth_date_format' => $c['birth_date_format'] ?? 'Y-m-d',
            'match_all' => array_values((array) ($c['match_all'] ?? [])),
            'mismatch_any' => array_values((array) ($c['mismatch_any'] ?? [])),
            'reference_path' => $c['reference_path'] ?? null,
            'reason_path' => $c['reason_path'] ?? null,
            'header_keys' => array_map('strval', array_keys((array) ($c['headers'] ?? []))),
            'body' => $badan,
            'accept_nik_count' => count((array) ($c['accept_nik'] ?? [])),
        ];
    }
}
