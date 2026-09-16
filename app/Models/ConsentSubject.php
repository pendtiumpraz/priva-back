<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\BelongsToOrg;
use App\Support\KelasSubjek;
use App\Support\KunciPencarian;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Subjek yang ditempuh lewat jalur wali — PP 33/2026 Pasal 38 & 39.
 *
 * SIAPA YANG PUNYA BARIS DI SINI. Hanya subjek yang benar-benar menempuh jalur
 * wali: anak, dan penyandang disabilitas yang SENDIRI memilih jalur itu. Orang
 * dewasa biasa tidak pernah punya baris. Itu bukan penghematan tempat — status
 * disabilitas adalah data pribadi spesifik, dan alat pelindung privasi yang
 * mendata seluruh penggunanya "demi kepatuhan" sedang menciptakan risiko yang
 * lebih besar daripada yang diselesaikannya.
 *
 * KENAPA TERPISAH DARI GuardianConsent. Seorang anak bisa punya dua wali (ibu
 * dan ayah). Kalau tanggal peralihan menempel di kewenangan, anak itu punya DUA
 * tanggal dewasa yang bisa menyimpang — dan antrean peralihan akan memproses
 * orang yang sama dua kali, atau melewatkannya sama sekali kalau salah satu
 * barisnya dicabut lebih dulu. Satu orang, satu tanggal.
 *
 * KENAPA `subject_hash`, padahal `consent_logs.user_identifier` tidak tersandi.
 * Bukan demi kerahasiaan — menyandikan di sini tidak menambah perlindungan
 * apa pun selama plaintext-nya ada di ledger sebelah. Gunanya NORMALISASI: satu
 * aturan bersama (KunciPencarian) yang menyamakan "Budi@Contoh.ID " dengan
 * "budi@contoh.id", dan "+62812..." dengan "0812...". Tanpa itu satu anak bisa
 * punya dua baris subjek.
 *
 * @property string|null $org_id
 * @property string|null $subject_hash
 * @property string|null $subject_label
 * @property string|null $subject_class
 * @property string|null $subject_own_channel
 * @property string|null $transition_state
 * @property Carbon|null $transition_date
 */
class ConsentSubject extends Model
{
    use BelongsToOrg, HasUuids;

    protected $fillable = [
        'org_id', 'subject_hash', 'subject_label', 'subject_class',
        'transition_date', 'transition_state', 'subject_own_channel', 'created_by',
    ];

    protected $casts = [
        'transition_date' => 'date',
        'subject_label' => EncryptedString::class,
        // Kanal milik SUBJEK sendiri. Untuk consent anak, penanda subjeknya
        // biasanya kontak ORANG TUA — tanpa kolom ini, saat anak itu dewasa
        // sistem tidak punya cara menghubunginya.
        'subject_own_channel' => EncryptedString::class,
    ];

    /**
     * Cari subjek dengan penanda ini, atau buat baru.
     *
     * SATU-SATUNYA jalan yang benar. Mencari lewat `where('subject_label', ...)`
     * tidak akan pernah cocok — kolomnya tersandi dengan IV acak.
     *
     * @param  array<string, mixed>  $atribut
     */
    public static function temukanAtauBuat(string $orgId, string $penanda, array $atribut = []): self
    {
        $hash = (string) KunciPencarian::hash($penanda);

        $subjek = self::where('org_id', $orgId)->where('subject_hash', $hash)->first();
        if ($subjek) {
            return $subjek;
        }

        return self::create(array_merge([
            'org_id' => $orgId,
            'subject_hash' => $hash,
            'subject_label' => $penanda,
            'subject_class' => KelasSubjek::ANAK,
        ], $atribut));
    }

    /** @return HasMany<GuardianConsent, $this> */
    public function guardianConsents(): HasMany
    {
        return $this->hasMany(GuardianConsent::class);
    }

    /**
     * Kewenangan wali yang masih berjalan atas subjek ini.
     *
     * Terverifikasi DAN belum dicabut — keduanya wajib. Kewenangan yang belum
     * terverifikasi bukan kewenangan; ia baru niat.
     */
    public function waliBerwenang(): HasMany
    {
        return $this->guardianConsents()->whereNotNull('verified_at')->whereNull('revoked_at');
    }

    /** @return HasMany<ConsentLog, $this> */
    public function consentLogs(): HasMany
    {
        // Lewat kewenangan, bukan langsung: ledger menunjuk kewenangan yang
        // memayungi tiap penangkapan, dan itulah yang bisa diaudit.
        return $this->hasMany(ConsentLog::class, 'guardian_consent_id', 'id');
    }

    /**
     * Sudah waktunya beralih jadi dewasa? (Pasal 38 ayat 8)
     *
     * Hanya anak yang beralih, dan hanya sekali. Yang sudah punya
     * `transition_state` berarti antreannya sudah menyentuhnya.
     */
    public function siapBeralih(?Carbon $pada = null): bool
    {
        return $this->subject_class === KelasSubjek::ANAK
            && $this->transition_date !== null
            && $this->transition_state === null
            && ! $this->transition_date->isAfter($pada ?? now());
    }
}
