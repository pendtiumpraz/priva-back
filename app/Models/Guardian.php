<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Wali / orang tua / pendamping — PP 33/2026 Pasal 38 & 39.
 *
 * Berdiri sendiri, tidak ditempel ke consent: satu wali menaungi banyak
 * persetujuan, dan bisa menaungi anak MAUPUN penyandang disabilitas.
 *
 * Hubungan (`relationship`) adalah PERNYATAAN SENDIRI, bukan fakta
 * terverifikasi. Yang diverifikasi adalah penguasaan atas kanal kontaknya, dan
 * itu dicatat di GuardianConsent — bukan di sini.
 *
 * @property string|null $org_id
 * @property string|null $contact
 * @property string|null $contact_type
 */
class Guardian extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const HUBUNGAN = ['orang_tua', 'wali_sah', 'pendamping', 'lainnya'];

    public const KONTAK = ['email', 'phone'];

    protected $fillable = [
        'org_id', 'name', 'contact_type', 'contact', 'contact_hash',
        'relationship', 'relationship_note', 'created_by',
    ];

    protected $casts = [
        'name' => EncryptedString::class,
        'contact' => EncryptedString::class,
    ];

    /**
     * Normalkan kontak sebelum di-hash.
     *
     * Tanpa normalisasi, "Budi@Contoh.ID " dan "budi@contoh.id" menghasilkan
     * hash berbeda dan sistem membuat DUA wali untuk orang yang sama — persis
     * yang hendak dicegah indeks unik di migrasi.
     */
    public static function normalkan(string $kontak): string
    {
        $k = trim(mb_strtolower($kontak));

        // Nomor telepon: buang segala pemisah, samakan awalan Indonesia.
        if (! str_contains($k, '@')) {
            $k = preg_replace('/[^0-9+]/', '', $k) ?? $k;
            if (str_starts_with($k, '+62')) {
                $k = '0'.substr($k, 3);
            } elseif (str_starts_with($k, '62')) {
                $k = '0'.substr($k, 2);
            }
        }

        return $k;
    }

    /** Kunci pencarian wali. Deterministik — tidak seperti kontaknya yang terenkripsi. */
    public static function hashKontak(string $kontak): string
    {
        return hash('sha256', self::normalkan($kontak));
    }

    /**
     * Cari wali dengan kontak ini, atau buat baru.
     *
     * SATU-SATUNYA jalan yang benar untuk mendapatkan wali. Mencari lewat
     * `where('contact', ...)` tidak akan pernah cocok: kolomnya terenkripsi
     * dengan IV acak, sehingga dua enkripsi atas nilai sama menghasilkan sandi
     * berbeda.
     *
     * @param  array<string, mixed>  $atribut
     */
    public static function temukanAtauBuat(string $orgId, string $kontak, array $atribut = []): self
    {
        $hash = self::hashKontak($kontak);

        $wali = self::where('org_id', $orgId)->where('contact_hash', $hash)->first();
        if ($wali) {
            return $wali;
        }

        return self::create(array_merge([
            'org_id' => $orgId,
            'contact' => $kontak,
            'contact_hash' => $hash,
            'contact_type' => str_contains($kontak, '@') ? 'email' : 'phone',
        ], $atribut));
    }

    /** @return HasMany<GuardianConsent, $this> */
    public function guardianConsents(): HasMany
    {
        return $this->hasMany(GuardianConsent::class);
    }

    /** Persetujuan yang kewenangannya masih berjalan. */
    public function kewenanganAktif(): HasMany
    {
        return $this->guardianConsents()->whereNull('revoked_at');
    }
}
