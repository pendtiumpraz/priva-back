<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\LandlordPinned;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, LandlordPinned, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'org_id',
        'role',
        'phone',
        'avatar_url',
        'position',
        'department_id',
        'position_id',
        'is_active',
        'settings',
        'tenant_role_id',
        'locale',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // 2FA secrets — JANGAN bocor ke API response, even untuk owner.
        // Ditampilkan hanya saat /auth/2fa/setup (one-time, plain text di QR).
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'settings' => 'array',
            // Login lockout (added 2026-05-10) — datetime cast supaya
            // Carbon comparison di LoginAttemptService gampang.
            'last_failed_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            // 2FA (added 2026-05-11) — confirmed_at datetime; secret +
            // recovery_codes tetap text/encrypted, gak di-cast karena
            // Crypt::decryptString manual di service (kalau cast pakai
            // 'encrypted' Laravel built-in, akan double-decrypt).
            'two_factor_confirmed_at' => 'datetime',
            'password_changed_at' => 'datetime',
            // PII Encryption — AES-256-CBC
            'name' => EncryptedString::class,
            'phone' => EncryptedString::class,
            'anonymized_at' => 'datetime',
        ];
    }

    /** Sudah dianonimkan? Datanya tidak bisa dikembalikan. */
    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /**
     * Hapus data pribadi pengguna, pertahankan barisnya (UU PDP Pasal 43 & 44).
     *
     * Baris sengaja TIDAK dihapus: `users.id` dirujuk jejak audit, assignees
     * RoPA/DPIA, dan penerbit tautan. Menghapusnya akan memutus bukti kepatuhan
     * — menaati satu pasal dengan melanggar yang lain.
     *
     * Surel diganti alamat unik di TLD `.invalid` (RFC 2606: dijamin tidak
     * pernah routable), memakai id pengguna supaya kendala unik pada kolom
     * email tetap terpenuhi tanpa kemungkinan bentrok.
     *
     * Kredensial ikut dimusnahkan — sandi diacak, rahasia 2FA dan token
     * diingat dikosongkan — supaya akun tidak bisa dipakai lagi dengan cara
     * apa pun, bukan sekadar tidak bisa dicari.
     */
    public function anonymize(): void
    {
        $this->forceFill([
            'name' => 'Pengguna Dihapus',
            'email' => 'anonim+'.$this->id.'@privasimu.invalid',
            'phone' => null,
            'avatar_url' => null,
            'position' => null,
            'settings' => null,
            'is_active' => false,
            'password' => bcrypt(Str::random(64)),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'anonymized_at' => now(),
        ])->saveQuietly();
    }

    /**
     * Get the organization that the user belongs to.
     */
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /**
     * Divisi tempat user ini bernaung — penentu baris mana yang boleh dilihatnya
     * (lihat App\Support\AssignmentScope). Nullable: user tanpa divisi hanya
     * melihat baris "(All Group)", yang ditugaskan langsung kepadanya, dan yang
     * dibuatnya sendiri.
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function positionRef()
    {
        return $this->belongsTo(Position::class, 'position_id');
    }

    /** @return BelongsTo<TenantRole, $this> */
    public function tenantRole(): BelongsTo
    {
        return $this->belongsTo(TenantRole::class, 'tenant_role_id');
    }
}
