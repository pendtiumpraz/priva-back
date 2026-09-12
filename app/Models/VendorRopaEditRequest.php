<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Permintaan pihak ketiga untuk membuka kembali RoPA yang sudah mereka kirim.
 *
 * Pihak ketiga tidak punya akun: setelah kiriman terkunci, satu-satunya jalan
 * mengubah adalah meminta akses. Bila pengendali menyetujui, token dirotasi dan
 * tautan baru dikirim ke email kontak TERDAFTAR pihak ketiga — bukan ke alamat
 * yang diketik pada formulir permintaan, supaya pemegang tautan lama tidak bisa
 * mengalihkan akses ke dirinya sendiri.
 */
class VendorRopaEditRequest extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'org_id', 'vendor_ropa_id', 'reason',
        'requested_ip', 'requested_user_agent',
        'status', 'decided_by', 'decided_at', 'decision_notes',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function vendorRopa()
    {
        return $this->belongsTo(VendorRopa::class, 'vendor_ropa_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
