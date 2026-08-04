<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Satu siklus persetujuan atas naskah pemberitahuan privasi.
 *
 * Persetujuan berlaku atas versi secara UTUH — seluruh bahasa sekaligus.
 * Kalau tiap bahasa disetujui sendiri-sendiri, naskah Indonesia bisa terbit
 * sementara naskah Inggris masih tertahan, dan situs klien akan menyajikan dua
 * naskah yang tidak setara secara hukum.
 */
class PrivacyNoticeVersion extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    /** Status yang naskahnya masih boleh disunting. */
    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    protected $fillable = [
        'org_id', 'privacy_notice_id', 'version_number', 'status', 'change_note',
        'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'rejected_by', 'rejected_at', 'reject_reason',
        'publish_at', 'published_at', 'superseded_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'publish_at' => 'datetime',
        'published_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function notice(): BelongsTo
    {
        return $this->belongsTo(PrivacyNotice::class, 'privacy_notice_id');
    }

    public function contents(): HasMany
    {
        return $this->hasMany(PrivacyNoticeContent::class, 'version_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }
}
