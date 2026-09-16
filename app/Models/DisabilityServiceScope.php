<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ragam disabilitas yang DILAYANI sebuah kanal — PP 33/2026 Pasal 39 ayat (1)–(2).
 *
 * Yang didaftarkan adalah KANAL, BUKAN ORANG. Tabel ini tidak pernah menyimpan
 * siapa yang penyandang disabilitas; ia mencatat bahwa "Loket Cabang melayani
 * tunanetra, tunarungu, dan disabilitas fisik".
 *
 * Itu keputusan sadar. Status disabilitas seseorang adalah data pribadi
 * spesifik — menyimpannya untuk semua pengguna demi "kepatuhan" menciptakan
 * risiko yang lebih besar daripada yang diselesaikan. Kalau suatu saat ada yang
 * hendak menambahkan kolom "ragam disabilitas" ke tabel pengguna atau subjek,
 * pertimbangkan dulu: apakah benar-benar perlu, atau cukup dicatat saat subjek
 * SENDIRI memilih jalur pendampingan.
 *
 * @property string|null $org_id
 * @property string|null $channel
 * @property string|null $collection_point_id
 * @property string|null $ragam
 * @property bool $is_served
 * @property string|null $notes
 */
class DisabilityServiceScope extends Model
{
    use BelongsToOrg, HasUuids;

    /**
     * Ragam disabilitas menurut UU 8/2016 Pasal 4: fisik, intelektual, mental,
     * dan sensorik.
     *
     * Sensorik dipecah tiga karena prasarana yang dibutuhkan ketiganya berbeda
     * sama sekali — braille tidak menolong tunarungu, juru bahasa isyarat tidak
     * menolong tunanetra.
     */
    public const RAGAM_FISIK = 'fisik';

    public const RAGAM_INTELEKTUAL = 'intelektual';

    public const RAGAM_MENTAL = 'mental';

    public const RAGAM_NETRA = 'sensorik_netra';

    public const RAGAM_RUNGU = 'sensorik_rungu';

    public const RAGAM_WICARA = 'sensorik_wicara';

    public const RAGAM_GANDA = 'ganda';

    public const RAGAM = [
        self::RAGAM_FISIK, self::RAGAM_INTELEKTUAL, self::RAGAM_MENTAL,
        self::RAGAM_NETRA, self::RAGAM_RUNGU, self::RAGAM_WICARA, self::RAGAM_GANDA,
    ];

    public const LABEL = [
        self::RAGAM_FISIK => 'Disabilitas Fisik',
        self::RAGAM_INTELEKTUAL => 'Disabilitas Intelektual',
        self::RAGAM_MENTAL => 'Disabilitas Mental',
        self::RAGAM_NETRA => 'Sensorik — Netra',
        self::RAGAM_RUNGU => 'Sensorik — Rungu',
        self::RAGAM_WICARA => 'Sensorik — Wicara',
        self::RAGAM_GANDA => 'Disabilitas Ganda',
    ];

    protected $fillable = [
        'org_id', 'channel', 'collection_point_id', 'ragam', 'is_served', 'notes', 'created_by',
    ];

    protected $casts = ['is_served' => 'boolean'];

    /** @return BelongsTo<ConsentCollectionPoint, $this> */
    public function collectionPoint(): BelongsTo
    {
        return $this->belongsTo(ConsentCollectionPoint::class, 'collection_point_id');
    }

    /**
     * Bolehkah subjek dengan ragam ini memberi persetujuan SENDIRI?
     *
     * Aturan ini ditaruh di sini sejak awal supaya ia hidup di SATU tempat —
     * bukan tersebar di form, validator, dan widget yang lambat laun berbeda.
     *
     * Penjelasan Pasal 39 ayat (1) tegas: untuk disabilitas MENTAL, pengendali
     * tidak boleh meminta persetujuan langsung kepada yang bersangkutan.
     *
     * Untuk ragam lain jawabannya YA — dan itu sama pentingnya. Kapasitas hukum
     * penyandang disabilitas TIDAK otomatis hilang; mayoritas memberi
     * persetujuan sendiri dan yang mereka butuhkan adalah penyajian yang dapat
     * diakses, bukan wali. Memperlakukan semua ragam sebagai "harus lewat wali"
     * berarti mencabut kapasitas hukum orang yang memilikinya.
     */
    public static function bolehMandiri(string $ragam): bool
    {
        return $ragam !== self::RAGAM_MENTAL;
    }
}
