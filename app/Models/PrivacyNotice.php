<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Dokumen pemberitahuan privasi yang dikelola terpusat.
 *
 * Naskahnya sendiri tidak tinggal di sini melainkan di versi — lihat
 * PrivacyNoticeVersion. Yang melekat pada dokumen hanyalah identitas dan
 * token penyematan, supaya pemasangan di situs klien tidak perlu disentuh
 * setiap kali naskahnya diperbarui.
 */
class PrivacyNotice extends Model
{
    use BelongsToOrg, HasUuids, SoftDeletes;

    protected $fillable = [
        'org_id', 'code', 'title', 'slug', 'description',
        'embed_token', 'published_version_id', 'default_locale',
        'is_active', 'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $notice) {
            if (empty($notice->embed_token)) {
                $notice->embed_token = self::generateUniqueToken();
            }
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PrivacyNoticeVersion::class)->orderByDesc('version_number');
    }

    public function publishedVersion()
    {
        return $this->belongsTo(PrivacyNoticeVersion::class, 'published_version_id');
    }

    public static function generateUniqueToken(): string
    {
        do {
            $token = Str::random(64);
        } while (self::withoutGlobalScope('org')->where('embed_token', $token)->exists());

        return $token;
    }

    /**
     * Nomor kode berikutnya dalam format PN-YYYY-NNN.
     *
     * Penghitungnya per-org (global scope `org` ikut berlaku di sini) sementara
     * batasan unik pada kolom `code` bersifat GLOBAL. Ketidakcocokan itu
     * disengaja dan sama seperti modul lain — konsekuensinya penyisipan wajib
     * lewat createWithCodeRetry(), bukan create() langsung. Memanggil create()
     * begitu saja akan menghidupkan kembali temuan dataroom F-03.
     */
    public static function nextCode(): string
    {
        $year = date('Y');
        $prefix = 'PN-'.$year.'-';

        $max = 0;
        $codes = self::withTrashed()->where('code', 'like', $prefix.'%')->pluck('code');
        foreach ($codes as $code) {
            $num = (int) substr((string) $code, strrpos((string) $code, '-') + 1);
            $max = max($max, $num);
        }

        return $prefix.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Buat dokumen dengan pengulangan saat kode bentrok.
     *
     * Bentrokan bukan hal teoretis: penghitung per-org di atas batasan unik
     * global berarti dua tenant yang membuat dokumen pada tahun yang sama akan
     * sampai pada nomor yang sama.
     */
    public static function createWithCodeRetry(array $data): self
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $data['code'] = self::nextCode();

                return self::create($data);
            } catch (QueryException $e) {
                $isDuplicate = str_contains(strtolower($e->getMessage()), 'unique')
                    || str_contains(strtolower($e->getMessage()), 'duplicate');
                if (! $isDuplicate || $attempt === 2) {
                    throw $e;
                }
            }
        }

        throw new \RuntimeException('Gagal membuat kode Privacy Notice setelah 3 percobaan.');
    }
}
