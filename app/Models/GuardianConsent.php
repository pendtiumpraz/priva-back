<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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
 * @property string|null $collection_point_id
 * @property Carbon|null $verified_at
 * @property Carbon|null $verification_expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoke_reason
 * @property array<string, mixed>|null $pending_capture
 */
class GuardianConsent extends Model
{
    use BelongsToOrg, HasUuids;

    /** Alasan pencabutan yang dikenali sistem. `manual` diisi bebas oleh pengendali. */
    public const ALASAN_CABUT = ['peralihan_dewasa', 'manual'];

    protected $fillable = [
        'org_id', 'consent_subject_id', 'guardian_id', 'collection_point_id',
        'verification_method_code', 'verification_driver', 'verification_confidence',
        'verified_at', 'verification_reference', 'statement_shown',
        'verification_token_hash', 'verification_expires_at', 'pending_capture',
        'ip_address', 'user_agent',
        'revoked_at', 'revoke_reason',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'verification_expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        // Pilihan yang menunggu wali — tersandi; berisi penanda subjek dan
        // pilihan consent-nya, dan tidak boleh terbaca dari dump basis data.
        'pending_capture' => 'encrypted:array',
    ];

    /**
     * Dua-duanya rahasia operasional. Hash token yang bocor lewat respons API
     * memang tidak bisa dibalik, tapi tidak ada satu pun alasan ia perlu
     * keluar; dan pending_capture berisi pilihan yang BELUM disetujui siapa
     * pun — menampilkannya seolah ia keadaan consent adalah kekeliruan.
     */
    protected $hidden = ['verification_token_hash', 'pending_capture'];

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /** @return BelongsTo<ConsentCollectionPoint, $this> */
    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(ConsentCollectionPoint::class, 'collection_point_id');
    }

    // ───────────── Token tautan verifikasi ─────────────

    public static function hashToken(string $mentah): string
    {
        return hash('sha256', $mentah);
    }

    /**
     * Terbitkan token tautan baru, simpan HASH-nya, kembalikan yang mentah.
     *
     * Yang mentah hanya hidup di surel wali. Kalau basis data bocor, yang bocor
     * adalah hash — bukan tumpukan tautan persetujuan yang bisa diklik siapa
     * saja. Menerbitkan ulang mematikan token sebelumnya.
     */
    public function terbitkanToken(int $berlakuJam = 24): string
    {
        $mentah = Str::random(64);

        $this->forceFill([
            'verification_token_hash' => self::hashToken($mentah),
            'verification_expires_at' => now()->addHours($berlakuJam),
        ])->save();

        return $mentah;
    }

    /**
     * Cari kewenangan dari token mentah di tautan.
     *
     * Tanpa scope org: tautan dibuka wali dari kotak surelnya, di luar konteks
     * tenant mana pun. Token 64 karakter acak sudah cukup jadi kuncinya.
     */
    public static function denganToken(string $mentah): ?self
    {
        $mentah = trim($mentah);
        if ($mentah === '') {
            return null;
        }

        return self::withoutGlobalScope('org')
            ->where('verification_token_hash', self::hashToken($mentah))
            ->first();
    }

    public function tokenKedaluwarsa(): bool
    {
        return $this->verification_expires_at === null || $this->verification_expires_at->isPast();
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
