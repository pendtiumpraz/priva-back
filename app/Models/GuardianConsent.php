<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Kewenangan seorang wali atas seorang SUBJEK — inti Pasal 38 ayat (2) & (4).
 *
 * SATUANNYA ORANG, BUKAN KEJADIAN. Bentuk pertama (migrasi 000005) mengikat
 * kewenangan ke satu baris consent; itu keliru. Tiap kali subjek mengubah
 * preferensi akan lahir baris kewenangan baru yang MENYALIN hasil verifikasi,
 * dan saat kewenangan wali harus dicabut — hak asuh pindah, wali meninggal —
 * pencabutan harus menyentuh semua salinan. Satu terlewat berarti wali yang
 * sudah tidak berwenang masih terbaca berwenang, tepat pada kasus yang paling
 * perlu benar. Lihat migrasi 2026_09_15_000008.
 *
 * Yang dicatat di sini adalah VERIFIKASI dan PENCABUTAN, bukan identitas wali
 * (itu di Guardian) dan bukan keadaan subjeknya (itu di ConsentSubject). Dan
 * yang disimpan dari verifikasi adalah HASILNYA: terverifikasi kapan, dengan
 * metode apa, nomor rujukan penyedia berapa. BUKAN NIK, BUKAN foto KTP.
 *
 * @property string|null $org_id
 * @property Carbon|null $verified_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 */
class GuardianConsent extends Model
{
    use BelongsToOrg, HasUuids;

    /** Alasan pencabutan yang dikenali sistem. `manual` diisi bebas oleh pengendali. */
    public const ALASAN_CABUT = ['peralihan_dewasa', 'manual'];

    protected $fillable = [
        'org_id', 'consent_subject_id', 'guardian_id',
        'verification_method_code', 'verification_driver', 'verification_confidence',
        'verified_at', 'verification_reference', 'statement_shown',
        'ip_address', 'user_agent',
        'revoked_at', 'revoke_reason',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /** @return BelongsTo<ConsentSubject, $this> */
    public function consentSubject(): BelongsTo
    {
        return $this->belongsTo(ConsentSubject::class);
    }

    /**
     * Penangkapan consent yang dipayungi kewenangan ini.
     *
     * @return HasMany<ConsentLog, $this>
     */
    public function consentLogs(): HasMany
    {
        return $this->hasMany(ConsentLog::class, 'guardian_consent_id');
    }

    /**
     * Kewenangan ini masih berjalan?
     *
     * Terverifikasi DAN belum dicabut — keduanya wajib. Kewenangan yang belum
     * terverifikasi bukan kewenangan; ia baru niat.
     */
    public function masihBerlaku(): bool
    {
        return $this->verified_at !== null && $this->revoked_at === null;
    }

    /**
     * Cabut kewenangan.
     *
     * Dipakai dua jalur: peralihan anak dewasa (otomatis, Pasal 38 ayat 8) dan
     * pemutusan manual oleh pengendali — wali meninggal, hak asuh dicabut, anak
     * pindah wali. PP tidak mengatur yang kedua, tapi pasti terjadi.
     */
    public function cabut(string $alasan): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->forceFill([
            'revoked_at' => now(),
            'revoke_reason' => $alasan,
        ])->save();
    }
}
