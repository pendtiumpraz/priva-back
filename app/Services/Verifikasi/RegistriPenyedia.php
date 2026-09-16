<?php

namespace App\Services\Verifikasi;

use App\Models\VerificationMethod;
use Illuminate\Support\Facades\Log;

/**
 * Peta driver → penyedia, plus satu pintu `periksa()` yang tidak pernah
 * melempar: apa pun yang meledak di dalam driver pulang sebagai `gagal`,
 * karena bagi pemanggil (alur wali) semua kegagalan teknis berarti satu hal —
 * "kami tidak tahu, coba lagi atau pakai verifikasi surel".
 */
final class RegistriPenyedia
{
    public function __construct(
        private readonly HttpVerifikasiDriver $http,
        private readonly MockVerifikasiDriver $mock,
    ) {}

    /**
     * Satu-satunya tempat "produksi" diputuskan untuk fitur ini. Membaca
     * `config('app.env')` (bukan `app()->environment()`) supaya uji bisa
     * mensimulasikan produksi tanpa mengubah koneksi basis data landlord.
     */
    public static function produksi(): bool
    {
        return config('app.env') === 'production';
    }

    /**
     * Driver yang boleh dipilih tenant untuk metode kuat. `mock` hanya di
     * luar produksi.
     *
     * @return list<string>
     */
    public static function driverKuat(): array
    {
        $d = [VerificationMethod::DRIVER_DUKCAPIL, VerificationMethod::DRIVER_EKYC];
        if (! self::produksi()) {
            $d[] = VerificationMethod::DRIVER_MOCK;
        }

        return $d;
    }

    public function untuk(VerificationMethod $metode): ?PenyediaVerifikasi
    {
        return match ($metode->driver) {
            VerificationMethod::DRIVER_DUKCAPIL, VerificationMethod::DRIVER_EKYC => $this->http,
            VerificationMethod::DRIVER_MOCK => self::produksi() ? null : $this->mock,
            default => null,
        };
    }

    public function periksa(VerificationMethod $metode, KlaimIdentitas $klaim): HasilVerifikasi
    {
        $penyedia = $this->untuk($metode);
        if (! $penyedia) {
            return HasilVerifikasi::terganggu("Driver `{$metode->driver}` tidak tersedia di lingkungan ini.");
        }

        try {
            return $penyedia->periksa($metode, $klaim);
        } catch (\Throwable $e) {
            // Yang dicatat: metode dan kelas galatnya. BUKAN klaimnya, BUKAN
            // pesan galat mentah (bisa memuat badan permintaan dari klien HTTP).
            Log::warning('Verifikasi identitas wali gagal di driver', [
                'method_code' => $metode->code,
                'driver' => $metode->driver,
                'exception' => $e::class,
            ]);

            return HasilVerifikasi::terganggu('Penyedia verifikasi mengalami gangguan ('.class_basename($e).').');
        }
    }
}
