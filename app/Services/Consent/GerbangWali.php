<?php

namespace App\Services\Consent;

use App\Models\ConsentCollectionPoint;
use App\Models\GuardianConsent;
use App\Support\KelasSubjek;
use App\Support\KunciPencarian;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Gerbang wali — PP 33/2026 Pasal 38 ayat (2).
 *
 * SATU tempat untuk satu pertanyaan: "bolehkah penangkapan consent ini ditulis
 * ke ledger?" Dipanggil kedua jalur tangkap (widget publik dan Partner API v1)
 * SEBELUM `ConsentLog::create`. Pintu masuk yang berbeda tidak boleh punya
 * penjagaan yang berbeda — kalau satu jalur lolos, aturannya hanya menyulitkan
 * tenant yang jujur.
 *
 * Aturannya:
 *
 *   - `anak`        → wajib ada kewenangan wali yang SAH (lihat kewenanganSah).
 *   - `disabilitas` → TIDAK wajib wali. Mayoritas penyandang disabilitas memberi
 *                     persetujuan sendiri; yang mereka butuhkan adalah penyajian
 *                     yang dapat diakses (Pasal 39 ayat 3). Jalur wali untuk
 *                     ragam mental datang bersama penilaian kapasitas (Fase 3).
 *   - `dewasa`      → seperti sebelum fitur ini ada.
 *   - kelas kosong  → `dewasa`, KECUALI titik pengumpulannya menyalakan
 *                     `settings.guardian_mode`; saat itu kelas wajib dinyatakan.
 *
 * Soal `guardian_mode`: sakelar itu sudah ada di layar pengaturan titik
 * pengumpulan jauh sebelum fase ini, tersimpan dan diekspor, tapi tidak
 * mengubah perilaku apa pun. Di sinilah ia akhirnya berarti sesuatu.
 *
 * Penolakan dilempar sebagai respons 422 dengan `code` yang stabil, supaya
 * widget dan integrasi tenant bisa bercabang tanpa mengurai kalimat.
 */
final class GerbangWali
{
    public const KELAS_WAJIB = 'KELAS_SUBJEK_WAJIB';

    public const KELAS_TIDAK_DIKENAL = 'KELAS_SUBJEK_TIDAK_DIKENAL';

    public const WALI_WAJIB = 'KEWENANGAN_WALI_WAJIB';

    public const WALI_TIDAK_SAH = 'KEWENANGAN_WALI_TIDAK_SAH';

    /**
     * @return array{subject_class: string, guardian_consent_id: string|null}
     */
    public function periksa(
        ConsentCollectionPoint $cp,
        string $userIdentifier,
        ?string $subjectClass,
        ?string $guardianConsentId,
    ): array {
        $kelas = trim((string) $subjectClass);

        if ($kelas === '') {
            if (($cp->settings['guardian_mode'] ?? false) === true) {
                $this->tolak(
                    self::KELAS_WAJIB,
                    'Titik pengumpulan ini mewajibkan kelas subjek (dewasa, anak, atau disabilitas) pada setiap penangkapan consent.',
                    'subject_class',
                );
            }
            $kelas = KelasSubjek::DEWASA;
        }

        if (! in_array($kelas, KelasSubjek::SEMUA, true)) {
            $this->tolak(self::KELAS_TIDAK_DIKENAL, 'Kelas subjek tidak dikenal.', 'subject_class');
        }

        $kewenangan = null;
        $id = trim((string) $guardianConsentId);
        if ($id !== '') {
            $kewenangan = $this->kewenanganSah($cp, $userIdentifier, $id);
        }

        if ($kelas === KelasSubjek::ANAK && $kewenangan === null) {
            $this->tolak(
                self::WALI_WAJIB,
                'Persetujuan untuk anak harus diberikan oleh orang tua atau wali (PP 33/2026 Pasal 38). Ajukan verifikasi wali lebih dulu, lalu sertakan guardian_consent_id.',
                'guardian_consent_id',
            );
        }

        return [
            'subject_class' => $kelas,
            'guardian_consent_id' => $kewenangan?->id,
        ];
    }

    /**
     * Kewenangan yang SAH: milik tenant ini, terverifikasi, belum dicabut, dan
     * memang untuk subjek yang sedang ditangkap.
     *
     * Syarat terakhir yang paling sering terlupa. Tanpa itu, satu kewenangan
     * wali atas anak A bisa dipakai menangkap consent atas anak B — cukup
     * dengan menyalin id-nya.
     */
    private function kewenanganSah(ConsentCollectionPoint $cp, string $userIdentifier, string $id): GuardianConsent
    {
        $kw = GuardianConsent::withoutGlobalScope('org')
            ->where('id', $id)
            ->where('org_id', $cp->org_id)
            ->with('consentSubject')
            ->first();

        // Tidak ada, atau milik tenant lain — SATU pesan yang sama untuk
        // keduanya, supaya id kewenangan tenant lain tidak bisa diraba-raba
        // dari perbedaan jawabannya.
        if (! $kw) {
            $this->tolak(self::WALI_TIDAK_SAH, 'Kewenangan wali tidak ditemukan.', 'guardian_consent_id');
        }
        if ($kw->revoked_at !== null) {
            $this->tolak(self::WALI_TIDAK_SAH, 'Kewenangan wali sudah dicabut.', 'guardian_consent_id');
        }
        if ($kw->verified_at === null) {
            $this->tolak(self::WALI_TIDAK_SAH, 'Kewenangan wali belum terverifikasi.', 'guardian_consent_id');
        }

        $hash = KunciPencarian::hash($userIdentifier);
        if ($kw->consentSubject === null || $hash === null || $kw->consentSubject->subject_hash !== $hash) {
            $this->tolak(self::WALI_TIDAK_SAH, 'Kewenangan wali ini bukan untuk subjek yang dimaksud.', 'guardian_consent_id');
        }

        return $kw;
    }

    private function tolak(string $kode, string $pesan, string $bidang): never
    {
        throw new HttpResponseException(
            response()->json([
                'error' => $pesan,
                'code' => $kode,
                'errors' => [$bidang => [$pesan]],
            ], 422)
                // Endpoint tangkap adalah endpoint publik untuk widget di situs
                // tenant — penolakannya pun harus bisa dibaca lintas origin.
                ->header('Access-Control-Allow-Origin', '*')
        );
    }
}
