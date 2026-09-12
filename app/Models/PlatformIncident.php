<?php

namespace App\Models;

use App\Casts\EncryptedString;
use App\Models\Concerns\LandlordPinned;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Insiden pada PLATFORM ini sendiri — bukan insiden milik tenant.
 *
 * Terhadap tenant kami berkedudukan sebagai Prosesor: satu insiden di sisi
 * kami otomatis menjadi kewajiban pemberitahuan bagi setiap Pengendali yang
 * datanya kami proses (UU PDP Pasal 46 — paling lambat 3x24 jam). Daftar ini
 * yang mengubah kewajiban itu dari niat menjadi tindakan yang tercatat.
 *
 * BUKAN tenant-scoped: tidak ada `org_id`, tidak memakai BelongsToOrg, dan
 * dipatri ke koneksi landlord supaya tetap terbaca saat koneksi bawaan sedang
 * dialihkan ke database tenant (BYODB).
 */
class PlatformIncident extends Model
{
    use HasUuids, LandlordPinned, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_NOTIFIED = 'notified';

    public const STATUS_CLOSED = 'closed';

    public const SCOPE_ALL = 'all';

    public const SCOPE_SELECTED = 'selected';

    /** Tenant yang sudah tidak berjalan — tidak ikut disebari. */
    public const LIFECYCLE_EXCLUDED = ['archived', 'transferred'];

    protected $fillable = [
        'incident_code', 'title', 'description', 'severity', 'status',
        'detected_at', 'affected_scope', 'affected_org_ids',
        'fanned_out_at', 'fanout_results', 'remediation',
        'created_by', 'closed_at',
    ];

    protected $casts = [
        'affected_org_ids' => 'array',
        'fanout_results' => 'array',
        'detected_at' => 'datetime',
        'fanned_out_at' => 'datetime',
        'closed_at' => 'datetime',
        // Uraian insiden dapat memuat rincian yang sensitif — diperlakukan
        // sama dengan BreachIncident::description.
        'description' => EncryptedString::class,
    ];

    public function sudahDisebar(): bool
    {
        return $this->fanned_out_at !== null;
    }
}
