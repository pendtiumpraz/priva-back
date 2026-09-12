<?php

namespace App\Models;

use App\Casts\EncryptedString;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * RoPA yang diisi sendiri oleh pihak ketiga lewat tautan publik.
 *
 * Bukan bagian dari register RoPA tenant (lihat migrasi
 * 2026_09_12_000002_create_vendor_ropa_tables): ini keterangan pihak ketiga
 * tentang pemrosesan yang mereka lakukan, yang lalu ditinjau pengendali dan
 * boleh ditautkan ke satu/lebih RoPA tenant.
 *
 * Tidak memakai trait BelongsToOrg — sama seperti Vendor, penyaringan org
 * dilakukan eksplisit oleh pemanggil. Alur publik berjalan tanpa login, jadi
 * global scope tidak bisa diandalkan sebagai pagar tenant.
 */
class VendorRopa extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_RETURNED = 'returned';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SUBMITTED, self::STATUS_ACCEPTED, self::STATUS_RETURNED];

    protected $fillable = [
        'org_id', 'vendor_id',
        'processing_activity', 'purpose', 'legal_basis', 'role',
        'data_categories', 'data_subjects', 'retention_period',
        'storage_locations', 'cross_border', 'cross_border_countries',
        'sub_processors', 'security_measures', 'notes',
        'pic_name', 'pic_email', 'pic_phone',
        'status', 'review_notes', 'reviewed_by', 'reviewed_at', 'created_by',
    ];

    protected $casts = [
        'data_categories' => 'array',
        'data_subjects' => 'array',
        'storage_locations' => 'array',
        'cross_border' => 'boolean',
        'cross_border_countries' => 'array',
        'sub_processors' => 'array',
        'security_measures' => 'array',
        'token_expires_at' => 'datetime',
        'token_consumed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        // PII pengisi di sisi pihak ketiga — AES-256-CBC, sama seperti Vendor.
        'pic_name' => EncryptedString::class,
        'pic_email' => EncryptedString::class,
        'pic_phone' => EncryptedString::class,
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function editRequests()
    {
        return $this->hasMany(VendorRopaEditRequest::class, 'vendor_ropa_id')->orderBy('created_at', 'desc');
    }

    /**
     * Kegiatan pemrosesan milik tenant yang dilayani oleh RoPA pihak ketiga ini.
     * Inilah jalur yang membuat "insiden di pihak ketiga" bisa ditelusuri ke
     * kegiatan pemrosesan kita sendiri.
     */
    public function ropas()
    {
        return $this->belongsToMany(Ropa::class, 'ropa_vendor_ropa', 'vendor_ropa_id', 'ropa_id')
            ->withPivot('notes', 'org_id')
            ->withTimestamps();
    }

    /** Sudah dikirim dan karenanya terkunci dari perubahan lewat tautan publik. */
    public function isLocked(): bool
    {
        return $this->token_consumed_at !== null;
    }
}
