<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentCollectionPoint;
use App\Models\Guardian;
use App\Models\GuardianConsent;
use App\Services\CaptchaVerifier;
use App\Services\Consent\LayananWali;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Alur wali untuk widget publik — PP 33/2026 Pasal 38.
 *
 *   POST /public/consent/guardian/request          subjek/aplikasi mengajukan
 *   GET  /public/consent/guardian/verify/{token}   wali MELIHAT (tanpa efek)
 *   POST /public/consent/guardian/verify/{token}   wali MENYETUJUI
 *
 * GET dan POST sengaja dipisah. Pemindai tautan di server surel membuka tautan
 * sebelum manusianya; kalau membuka = menyetujui, sebagian "persetujuan wali"
 * di ledger sebenarnya diberikan oleh bot antivirus.
 *
 * Endpoint ini dipanggil lintas origin oleh widget di situs tenant, karena itu
 * setiap respons — termasuk penolakan — membawa header CORS `*`, sama seperti
 * ConsentLogController::capture.
 */
class GuardianConsentPublicController extends Controller
{
    public function __construct(
        private LayananWali $layanan,
        private ?CaptchaVerifier $captcha = null,
    ) {
        $this->captcha = $captcha ?: app(CaptchaVerifier::class);
    }

    public function request(Request $request)
    {
        $data = $request->validate([
            'collection_id' => 'required|string',
            'user_identifier' => 'required|string|max:200',
            // Hanya kelas yang memang menempuh jalur wali. Orang dewasa tidak
            // pernah lewat sini — dan tidak pernah punya baris subjek.
            'subject_class' => 'required|in:anak,disabilitas',
            'consented_items' => 'required|array',
            'policy_version' => 'nullable|string|max:32',
            'guardian' => 'required|array',
            'guardian.name' => 'required|string|max:120',
            'guardian.contact' => 'required|string|max:200',
            'guardian.relationship' => 'required|in:'.implode(',', Guardian::HUBUNGAN),
            'guardian.relationship_note' => 'nullable|string|max:255',
            // Tanggal PERALIHAN (anak genap 18), bukan tanggal lahir — dan
            // harus di masa depan: anak yang sudah dewasa bukan anak.
            'transition_date' => 'nullable|date|after:today',
            'subject_own_channel' => 'nullable|string|max:200',
            'name' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:40',
            'external_user_ref' => 'nullable|string|max:120',
            'source_form' => 'nullable|string|max:120',
            'captcha_token' => 'nullable|string|max:4000',
        ]);

        $collection = ConsentCollectionPoint::where('embed_token', $data['collection_id'])
            ->orWhere('collection_id', $data['collection_id'])
            ->orWhere('id', $data['collection_id'])
            ->first();

        if (! $collection) {
            return $this->json(['error' => 'Titik pengumpulan tidak ditemukan.'], 404);
        }

        if ($collection->captcha_provider
            && ! $this->captcha->verifyForCollection($collection, $request->input('captcha_token'), $request->ip())) {
            return $this->json(['error' => 'Verifikasi captcha gagal.'], 422);
        }

        $kw = $this->layanan->ajukan($collection, $data, (string) $request->ip(), $request->userAgent(), 'widget');

        return $this->json([
            'message' => 'Tautan persetujuan telah dikirim ke wali.',
            'status' => 'menunggu_wali',
            'guardian_consent_id' => $kw->id,
            'expires_at' => $kw->verification_expires_at?->toIso8601String(),
        ], 202);
    }

    /** Wali melihat. Murni baca — tidak ada yang berubah karena halaman ini dibuka. */
    public function show(Request $request, string $token)
    {
        $html = $this->inginHtml($request);
        $kw = GuardianConsent::denganToken($token);

        if (! $kw) {
            return $this->gagal($html, 404, LayananWali::TOKEN_TIDAK_DIKENAL, 'Tautan tidak dikenal atau sudah pernah dipakai.');
        }
        if ($kw->tokenKedaluwarsa()) {
            return $this->gagal($html, 410, LayananWali::TOKEN_KEDALUWARSA, 'Tautan sudah kedaluwarsa. Minta tautan baru dari aplikasi yang mengajukannya.');
        }

        $p = $this->layanan->pratinjau($kw);

        if (! $html) {
            return $this->json($p);
        }

        return response()->view('consent.guardian_verify_page', [
            'state' => 'show',
            'p' => $p,
            'pesan' => null,
            'confirmUrl' => url('/api/public/consent/guardian/verify/'.$token),
        ]);
    }

    /** Wali menyetujui. Di sinilah ledger ditulis. */
    public function confirm(Request $request, string $token)
    {
        $html = $this->inginHtml($request);

        try {
            $log = $this->layanan->konfirmasi($token, (string) $request->ip(), $request->userAgent());
        } catch (HttpResponseException $e) {
            if (! $html) {
                throw $e;
            }
            $isi = json_decode((string) $e->getResponse()->getContent(), true) ?: [];

            return $this->gagal(true, $e->getResponse()->getStatusCode(), (string) ($isi['code'] ?? ''), (string) ($isi['error'] ?? 'Tautan tidak dapat diproses.'));
        }

        if (! $html) {
            return $this->json([
                'message' => 'Persetujuan wali tercatat.',
                'log_id' => $log->id,
                'guardian_consent_id' => $log->guardian_consent_id,
                'subject_class' => $log->subject_class,
            ]);
        }

        $kw = GuardianConsent::withoutGlobalScope('org')->find($log->guardian_consent_id);

        return response()->view('consent.guardian_verify_page', [
            'state' => 'done',
            'p' => $kw ? $this->layanan->pratinjau($kw) : [],
            'pesan' => null,
            'confirmUrl' => null,
        ]);
    }

    // ───────────────────────── bantu ─────────────────────────

    /** Peramban wali → HTML; widget/uji (Accept: application/json) → JSON. Sama dengan DsrPublicController. */
    private function inginHtml(Request $request): bool
    {
        return ! ($request->wantsJson() && ! $request->acceptsHtml());
    }

    private function gagal(bool $html, int $status, string $kode, string $pesan)
    {
        if (! $html) {
            return $this->json(['error' => $pesan, 'code' => $kode], $status);
        }

        return response()->view('consent.guardian_verify_page', [
            'state' => 'error',
            'p' => [],
            'pesan' => $pesan,
            'confirmUrl' => null,
        ], $status);
    }

    /** @param array<string, mixed> $isi */
    private function json(array $isi, int $status = 200)
    {
        return response()->json($isi, $status)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    }
}
