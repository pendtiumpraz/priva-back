<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Kontrak dengan pihak ketiga (PKS, DPA, NDA, dll.).
 *
 * Satu pihak ketiga bisa punya banyak kontrak dengan masa berlaku berbeda,
 * karena itu masa berlaku disimpan sebagai kolom tanggal yang bisa di-query —
 * bukan sekadar berkas di `vendors.documents`. Lini masa kontrak dan pengingat
 * kedaluwarsa keduanya bertumpu pada kolom ini.
 *
 * Berkas boleh datang dari dua arah: diunggah perusahaan, atau — bila
 * perusahaan tidak mengunggah — oleh pihak ketiga sendiri lewat tautan publik
 * sekali-unggah (`access_token`), pola yang sama dengan tautan asesmen.
 */
class VendorContract extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_TERMINATED = 'terminated';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE, self::STATUS_EXPIRED, self::STATUS_TERMINATED];

    public const TYPE_DPA = 'dpa';

    public const TYPE_NDA = 'nda';

    public const TYPE_MSA = 'msa';

    public const TYPE_SOW = 'sow';

    public const TYPE_OTHER = 'other';

    public const TYPES = [self::TYPE_DPA, self::TYPE_NDA, self::TYPE_MSA, self::TYPE_SOW, self::TYPE_OTHER];

    public const TYPE_LABELS = [
        self::TYPE_DPA => 'Perjanjian Pemrosesan Data (DPA)',
        self::TYPE_NDA => 'Perjanjian Kerahasiaan (NDA)',
        self::TYPE_MSA => 'Perjanjian Induk (MSA)',
        self::TYPE_SOW => 'Lingkup Kerja (SOW)',
        self::TYPE_OTHER => 'Lainnya',
    ];

    /** Sisi yang mengunggah berkas kontrak. */
    public const SIDE_TENANT = 'tenant';

    public const SIDE_THIRD_PARTY = 'third_party';

    protected $fillable = [
        'org_id', 'vendor_id', 'title', 'contract_type', 'contract_number',
        'start_at', 'end_at', 'auto_renew', 'notice_days', 'status',
        'file', 'uploaded_side', 'uploaded_by', 'contract_review_id', 'notes',
    ];

    protected $casts = [
        'start_at' => 'date',
        'end_at' => 'date',
        'auto_renew' => 'boolean',
        'notice_days' => 'integer',
        'file' => 'array',
        'token_expires_at' => 'datetime',
        'token_consumed_at' => 'datetime',
    ];

    protected $appends = ['has_file', 'days_to_expiry'];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    /** Berkasnya ada? Isi `file` tidak pernah dikirim mentah ke klien. */
    public function getHasFileAttribute(): bool
    {
        return is_array($this->file) && ! empty($this->file['path']);
    }

    /** Sisa hari sampai berakhir; negatif berarti sudah lewat. */
    public function getDaysToExpiryAttribute(): ?int
    {
        return $this->end_at ? (int) now()->startOfDay()->diffInDays($this->end_at->startOfDay(), false) : null;
    }

    /** Kontrak yang menaungi tanggal tertentu — dasar gerbang "boleh aktif". */
    public function coversToday(): bool
    {
        if ($this->status === self::STATUS_TERMINATED) {
            return false;
        }
        $today = now()->startOfDay();
        if ($this->start_at && $this->start_at->startOfDay()->greaterThan($today)) {
            return false;
        }
        if ($this->end_at && $this->end_at->startOfDay()->lessThan($today)) {
            return false;
        }

        return true;
    }
}
