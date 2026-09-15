<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
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
 * @property array<string, mixed>|null $config
 */
class VerificationMethod extends Model
{
    use HasUuids;

    /** Driver = siapa yang benar-benar melakukan verifikasinya. */
    public const DRIVER_OTP = 'otp';

    public const DRIVER_DUKCAPIL = 'dukcapil';

    public const DRIVER_EKYC = 'ekyc';

    public const DRIVERS = [self::DRIVER_OTP, self::DRIVER_DUKCAPIL, self::DRIVER_EKYC];

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
}
