<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penilaian kapasitas subjek — PP 33/2026 Pasal 38 ayat (5)–(6).
 *
 * Dipakai saat ANAK SENDIRI yang mengajukan haknya, dan saat menentukan jalur
 * persetujuan bagi penyandang disabilitas.
 *
 * TERTAUT KE DPIA sejak awal, bukan ditambahkan belakangan: Penjelasan ayat
 * (5)–(6) sendiri yang menaruh penilaian ini di dalam penilaian dampak.
 *
 * `reason` WAJIB diisi. Penilaian kapasitas tanpa alasan tertulis bukan
 * penilaian — ia keputusan yang tidak bisa ditinjau ulang, tidak bisa dibantah
 * subjeknya, dan tidak bisa dipertanggungjawabkan saat diaudit.
 *
 * @property string|null $org_id
 */
class CapacityAssessment extends Model
{
    use BelongsToOrg, HasUuids;

    /**
     * Hasil penilaian — TIGA jalur, bukan dua.
     *
     * Ini bentuk yang sama dengan mode subjek di alur disabilitas, dan sengaja
     * begitu: mayoritas orang MAMPU memutuskan sendiri. "Perlu pendampingan"
     * berarti subjeknya tetap yang memutuskan, hanya dibantu memahami —
     * pendampingnya saksi, bukan pengambil keputusan. Hanya jalur ketiga yang
     * memindahkan keputusan ke wali.
     *
     * Menyederhanakannya jadi dua (mampu / tidak mampu) akan mendorong
     * penilai memilih "diwakili wali" untuk kasus yang sebenarnya cukup
     * didampingi — dan itu mencabut hak orang yang memilikinya.
     */
    public const HASIL_MAMPU = 'mampu';

    public const HASIL_PENDAMPINGAN = 'perlu_pendampingan';

    public const HASIL_WALI = 'diwakili_wali';

    public const HASIL = [self::HASIL_MAMPU, self::HASIL_PENDAMPINGAN, self::HASIL_WALI];

    protected $fillable = [
        'org_id', 'consent_record_id', 'dsr_request_id',
        'subject_class', 'result', 'reason',
        'assessed_by', 'assessed_at', 'dpia_id',
    ];

    protected $casts = ['assessed_at' => 'datetime'];

    /** Keputusan tetap di tangan subjeknya — dengan atau tanpa bantuan. */
    public function subjekMemutuskanSendiri(): bool
    {
        return in_array($this->result, [self::HASIL_MAMPU, self::HASIL_PENDAMPINGAN], true);
    }

    /** @return BelongsTo<Dpia, $this> */
    public function dpia(): BelongsTo
    {
        return $this->belongsTo(Dpia::class);
    }

    /** @return BelongsTo<User, $this> */
    public function penilai(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
