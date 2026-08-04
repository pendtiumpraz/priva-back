<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Naskah satu bahasa untuk satu versi pemberitahuan privasi.
 *
 * Tidak memakai soft delete: naskah tidak dihapus tersendiri, melainkan ikut
 * hidup dan mati bersama versinya.
 */
class PrivacyNoticeContent extends Model
{
    use BelongsToOrg, HasUuids;

    protected $fillable = [
        'org_id', 'version_id', 'locale', 'title', 'body', 'summary',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(PrivacyNoticeVersion::class, 'version_id');
    }
}
