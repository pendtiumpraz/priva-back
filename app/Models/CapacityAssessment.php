<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
 * @property string|null $consent_subject_id
 * @property string|null $dsr_request_id
 * @property string|null $subject_class
 * @property string|null $result
 * @property string|null $reason
 * @property string|null $assessed_by
 * @property string|null $dpia_id
 * @property Carbon|null $assessed_at
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
        // Menunjuk ORANG (consent_subjects), bukan satu baris consent. Penilaian
        // kapasitas melekat pada subjeknya dan berlaku lintas kejadian —
        // mengulang penilaian tiap kali orangnya mengisi formulir adalah
        // hambatan yang justru dilarang Pasal 39. Lihat migrasi 000008.
        'org_id', 'consent_subject_id', 'dsr_request_id',
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

    /** @return BelongsTo<ConsentSubject, $this> */
    public function consentSubject(): BelongsTo
    {
        return $this->belongsTo(ConsentSubject::class);
    }

    /**
     * Penilaian TERBARU atas seorang subjek — satu-satunya yang berlaku.
     *
     * Penilaian tidak ditimpa, ditambah: yang lama tetap ada sebagai riwayat
     * yang bisa ditinjau ulang. Karena itu "yang berlaku" harus dicari, bukan
     * diambil sembarang. Tanpa scope org — dipanggil dari gerbang publik.
     */
    public static function terbaruUntuk(string $orgId, string $consentSubjectId): ?self
    {
        return self::withoutGlobalScope('org')
            ->where('org_id', $orgId)
            ->where('consent_subject_id', $consentSubjectId)
            ->orderByDesc('assessed_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /** @return BelongsTo<User, $this> */
    public function penilai(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
