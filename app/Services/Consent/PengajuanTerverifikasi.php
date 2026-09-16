<?php

namespace App\Services\Consent;

use App\Models\GuardianConsent;

/**
 * Hasil pengajuan wali lewat verifikasi kuat: kewenangan yang SUDAH
 * terverifikasi identitasnya, token sesi untuk langkah "Saya menyetujui",
 * dan pratinjau pernyataan yang harus ditampilkan kepada wali.
 *
 * Token mentahnya hidup hanya di sini dan di respons kepada pihak yang baru
 * saja membuktikan identitasnya — tidak pernah ditulis ke basis data.
 */
final class PengajuanTerverifikasi
{
    /** @param  array<string, mixed>  $pratinjau */
    public function __construct(
        public readonly GuardianConsent $kewenangan,
        public readonly string $token,
        public readonly array $pratinjau,
    ) {}

    /**
     * Bentuk respons yang sama untuk widget dan partner API. `confirm_url`
     * hanya untuk widget (endpoint publik); partner API memakai token lewat
     * `POST /v1/consent/guardian/confirm` yang ditandatangani HMAC.
     *
     * @return array<string, mixed>
     */
    public function toArray(?string $confirmUrl = null): array
    {
        $kw = $this->kewenangan;

        return [
            'message' => 'Identitas wali terverifikasi. Tampilkan pernyataan kepada wali dan minta persetujuannya.',
            'status' => 'identitas_terverifikasi',
            'guardian_consent_id' => $kw->id,
            'confirm_token' => $this->token,
            'confirm_url' => $confirmUrl,
            'expires_at' => $kw->verification_expires_at?->toIso8601String(),
            'verification' => [
                'method_code' => $kw->verification_method_code,
                'driver' => $kw->verification_driver,
                'confidence' => $kw->verification_confidence,
                'reference' => $kw->verification_reference,
                'verified_at' => $kw->verified_at?->toIso8601String(),
            ],
            'preview' => $this->pratinjau,
        ];
    }
}
