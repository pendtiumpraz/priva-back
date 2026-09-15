<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsentRecord extends Model
{
    use HasUuids;

    /** Kelas subjek — menentukan alur mana yang berlaku. Bawaannya dewasa. */
    public const KELAS_DEWASA = 'dewasa';

    public const KELAS_ANAK = 'anak';

    public const KELAS_DISABILITAS = 'disabilitas';

    public const KELAS = [self::KELAS_DEWASA, self::KELAS_ANAK, self::KELAS_DISABILITAS];

    /**
     * Keadaan peralihan anak → dewasa (Pasal 38 ayat 8).
     *
     * NULL berarti belum relevan. `menunggu_konfirmasi` BUKAN berarti consent
     * batal — dasar hukumnya diperoleh secara sah dan tidak hilang karena ulang
     * tahun; yang berakhir adalah kewenangan walinya.
     */
    public const TRANSISI_MENUNGGU = 'menunggu_konfirmasi';

    public const TRANSISI_DIKONFIRMASI = 'dikonfirmasi';

    public const TRANSISI_DITARIK = 'ditarik';

    public const TRANSISI = [self::TRANSISI_MENUNGGU, self::TRANSISI_DIKONFIRMASI, self::TRANSISI_DITARIK];

    protected $fillable = [
        'consent_item_id', 'collection_point_id', 'subject_identifier',
        'subject_name', 'channel', 'is_granted', 'ip_address', 'user_agent',
        'proof', 'granted_at', 'revoked_at', 'revoke_reason', 'recorded_by',
        // Pasal 38 & 39 — lihat migrasi 2026_09_15_000005.
        'subject_class', 'transition_date', 'transition_state', 'subject_own_channel',
    ];

    protected $casts = [
        'is_granted' => 'boolean', 'granted_at' => 'datetime', 'revoked_at' => 'datetime',
        'transition_date' => 'date',
        // PII Encryption — AES-256-CBC
        'subject_identifier' => EncryptedString::class,
        'subject_name' => EncryptedString::class,
        'ip_address' => EncryptedString::class,
        // Kanal milik subjek sendiri — untuk consent anak, `subject_identifier`
        // biasanya berisi kontak ORANG TUANYA.
        'subject_own_channel' => EncryptedString::class,
    ];

    /** @return HasMany<GuardianConsent, $this> */
    public function guardianConsents()
    {
        return $this->hasMany(GuardianConsent::class);
    }

    /**
     * Kewenangan wali yang masih berjalan atas persetujuan ini.
     *
     * Terverifikasi DAN belum dicabut — keduanya wajib.
     */
    public function waliBerwenang()
    {
        return $this->guardianConsents()->whereNotNull('verified_at')->whereNull('revoked_at');
    }
}
