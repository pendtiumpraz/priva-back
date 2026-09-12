<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Tautan berbagi satu record ke lembaga (lihat migrasi 2026_09_12_000007).
 */
class RecordShareLink extends Model
{
    use BelongsToOrg, HasUuids;

    public const MODULE_ROPA = 'ropa';

    public const MODULE_DPIA = 'dpia';

    public const MODULES = [self::MODULE_ROPA, self::MODULE_DPIA];

    public const REASON_MAX_VIEWS = 'max_views';

    public const REASON_MANUAL = 'manual';

    /**
     * Kolom kerja internal yang tidak ikut dibagikan walau penerimanya regulator:
     * penugasan, persetujuan, dan catatan telaah adalah proses internal, bukan
     * isi catatan pemrosesan itu sendiri.
     */
    public const HIDDEN_FIELDS = [
        'assignees', 'assign_group', 'created_by', 'approved_by', 'submitted_by',
        'review_notes', 'approver_id', 'assigned_roles', 'raci_matrix', 'org_id',
    ];

    protected $fillable = [
        'org_id', 'module', 'record_id', 'token', 'password_hash', 'recipient_label',
        'max_views', 'view_count', 'expires_at', 'revoked_at', 'revoked_reason',
        'last_viewed_at', 'created_by',
    ];

    protected $casts = [
        'max_views' => 'integer',
        'view_count' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_viewed_at' => 'datetime',
    ];

    /** Keduanya tidak pernah ikut serialisasi tak sengaja. */
    protected $hidden = ['token', 'password_hash'];

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::withoutGlobalScope('org')->where('token', $token)->exists());

        return $token;
    }

    /**
     * Kata sandi yang bisa didiktekan lewat telepon: tanpa karakter yang mudah
     * tertukar (0/O, 1/I/l), dikelompokkan supaya mudah dibacakan dan disalin.
     */
    public static function generatePassword(): string
    {
        $charset = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $grup = [];
        for ($g = 0; $g < 4; $g++) {
            $bagian = '';
            for ($i = 0; $i < 4; $i++) {
                $bagian .= $charset[random_int(0, strlen($charset) - 1)];
            }
            $grup[] = $bagian;
        }

        return implode('-', $grup);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }

    public function isExhausted(): bool
    {
        return $this->view_count >= $this->max_views;
    }

    public function isActive(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired() && ! $this->isExhausted();
    }

    public function remainingViews(): int
    {
        return max(0, $this->max_views - $this->view_count);
    }

    /**
     * Catat satu pembukaan yang BERHASIL, lalu cabut sendiri kalau jatahnya
     * habis. Dilakukan di satu tempat supaya tidak ada jalur yang menambah
     * hitungan tanpa ikut memeriksa batasnya.
     */
    public function recordSuccessfulView(): void
    {
        $this->view_count = $this->view_count + 1;
        $this->last_viewed_at = now();

        if ($this->isExhausted()) {
            $this->revoked_at = now();
            $this->revoked_reason = self::REASON_MAX_VIEWS;
        }

        $this->save();
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
