<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Prasarana aksesibilitas per kanal — PP 33/2026 Pasal 39 ayat (3).
 *
 * Ayat itu menuntut prasarana yang mudah dipahami, dan Penjelasannya
 * mencontohkan bahasa isyarat, braille, dan komunikasi augmentatif.
 *
 * KENAPA ADA `last_tested_at`. Prasarana yang tidak pernah diuji ulang adalah
 * KLAIM, bukan fakta — dan klaim itulah yang dibawa ke audit. Sebuah kanal yang
 * bertanda "TTS tersedia" tiga tahun lalu, lalu situsnya dirombak dua kali,
 * belum tentu masih bisa dipakai. Karena itu kolom ini ada sejak awal dan
 * `sudahTerbukti()` menolak menganggap centang tanpa tanggal uji sebagai bukti.
 *
 * @property string|null $org_id
 * @property string|null $channel
 * @property string|null $collection_point_id
 * @property string|null $format
 * @property string|null $format_note
 * @property bool $is_available
 * @property string|null $evidence_ref
 * @property string|null $notes
 * @property Carbon|null $last_tested_at
 * @property Carbon|null $next_review_at
 */
class AccessibilityProvision extends Model
{
    use BelongsToOrg, HasUuids;

    /**
     * Format penyajian. Tiga yang pertama disebut langsung di Penjelasan ayat
     * (3); sisanya kebutuhan nyata yang muncul di rapat tim 1 Sep 2026
     * (pembaca layar, TTS, teks besar, kontras, bahasa sederhana).
     */
    public const FORMAT_ISYARAT = 'bahasa_isyarat';

    public const FORMAT_BRAILLE = 'braille';

    public const FORMAT_AAC = 'komunikasi_augmentatif';

    public const FORMAT_TTS = 'tts';

    public const FORMAT_PEMBACA_LAYAR = 'pembaca_layar';

    public const FORMAT_BAHASA_SEDERHANA = 'bahasa_sederhana';

    public const FORMAT_TEKS_BESAR = 'teks_besar';

    public const FORMAT_KONTRAS = 'kontras_tinggi';

    public const FORMAT_LAINNYA = 'lainnya';

    public const FORMAT = [
        self::FORMAT_ISYARAT, self::FORMAT_BRAILLE, self::FORMAT_AAC,
        self::FORMAT_TTS, self::FORMAT_PEMBACA_LAYAR, self::FORMAT_BAHASA_SEDERHANA,
        self::FORMAT_TEKS_BESAR, self::FORMAT_KONTRAS, self::FORMAT_LAINNYA,
    ];

    public const LABEL = [
        self::FORMAT_ISYARAT => 'Bahasa Isyarat',
        self::FORMAT_BRAILLE => 'Braille',
        self::FORMAT_AAC => 'Komunikasi Augmentatif (AAC)',
        self::FORMAT_TTS => 'Pembacaan Suara (TTS)',
        self::FORMAT_PEMBACA_LAYAR => 'Dukungan Pembaca Layar',
        self::FORMAT_BAHASA_SEDERHANA => 'Versi Bahasa Sederhana',
        self::FORMAT_TEKS_BESAR => 'Teks Besar',
        self::FORMAT_KONTRAS => 'Kontras Tinggi',
        self::FORMAT_LAINNYA => 'Lainnya',
    ];

    protected $fillable = [
        'org_id', 'channel', 'collection_point_id',
        'format', 'format_note', 'is_available',
        'evidence_ref', 'last_tested_at', 'next_review_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'last_tested_at' => 'date',
        'next_review_at' => 'date',
    ];

    /** @return BelongsTo<ConsentCollectionPoint, $this> */
    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(ConsentCollectionPoint::class, 'collection_point_id');
    }

    /**
     * Prasarana ini TERBUKTI, bukan sekadar dicentang?
     *
     * Tersedia saja tidak cukup: tanpa tanggal uji, yang ada hanyalah
     * pernyataan tenant tentang dirinya sendiri.
     */
    public function sudahTerbukti(): bool
    {
        return $this->is_available && $this->last_tested_at !== null;
    }

    /**
     * Ujinya sudah kedaluwarsa?
     *
     * Kedaluwarsa dihitung dari `next_review_at` yang ditetapkan tenant. Yang
     * belum pernah diuji sama sekali TIDAK dihitung kedaluwarsa di sini — ia
     * masuk kategori berbeda (belum terbukti), dan mencampur keduanya akan
     * menyamarkan mana yang pernah bekerja lalu basi dengan mana yang memang
     * tidak pernah ada.
     */
    public function ujiKedaluwarsa(): bool
    {
        return $this->last_tested_at !== null
            && $this->next_review_at !== null
            && $this->next_review_at->isPast();
    }
}
