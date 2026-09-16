<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentSubject;
use App\Services\Consent\LayananPeralihan;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * Keputusan subjek yang baru genap 18 — PP 33/2026 Pasal 38 ayat (8).
 *
 *   GET  /public/consent/transition/{token}            MELIHAT (tanpa efek)
 *   POST /public/consent/transition/{token}/confirm    melanjutkan persetujuan
 *   POST /public/consent/transition/{token}/withdraw   menarik semua persetujuan
 *
 * GET tidak memutuskan apa pun — pemindai tautan di server surel membuka
 * tautan sebelum manusianya. Keputusan hanya lewat POST.
 */
class TransitionPublicController extends Controller
{
    public function __construct(private LayananPeralihan $layanan) {}

    public function show(Request $request, string $token)
    {
        $html = $this->inginHtml($request);
        $subjek = ConsentSubject::denganTokenPeralihan($token);

        if (! $subjek) {
            return $this->gagal($html, 404, LayananPeralihan::TOKEN_TIDAK_DIKENAL, 'Tautan tidak dikenal atau sudah pernah dipakai.');
        }
        if ($subjek->transition_confirmed_at !== null) {
            return $this->gagal($html, 409, LayananPeralihan::SUDAH_DIPUTUSKAN, 'Keputusan atas peralihan ini sudah tercatat.');
        }
        if ($subjek->tokenPeralihanKedaluwarsa()) {
            return $this->gagal($html, 410, LayananPeralihan::TOKEN_KEDALUWARSA, 'Tautan sudah kedaluwarsa. Hubungi pengendali data untuk tautan baru.');
        }

        $p = $this->layanan->pratinjau($subjek);

        if (! $html) {
            return $this->json($p);
        }

        return response()->view('consent.transition_page', [
            'state' => 'show',
            'p' => $p,
            'pesan' => null,
            'confirmUrl' => url('/api/public/consent/transition/'.$token.'/confirm'),
            'withdrawUrl' => url('/api/public/consent/transition/'.$token.'/withdraw'),
        ]);
    }

    public function confirm(Request $request, string $token)
    {
        return $this->putuskan($request, $token, 'confirmed', fn () => $this->layanan->konfirmasi($token, (string) $request->ip(), $request->userAgent()));
    }

    public function withdraw(Request $request, string $token)
    {
        return $this->putuskan($request, $token, 'withdrawn', fn () => $this->layanan->tarik($token, (string) $request->ip(), $request->userAgent()));
    }

    // ───────────────────────── bantu ─────────────────────────

    /** @param  callable(): ConsentSubject  $aksi */
    private function putuskan(Request $request, string $token, string $stateHtml, callable $aksi)
    {
        $html = $this->inginHtml($request);

        try {
            $subjek = $aksi();
        } catch (HttpResponseException $e) {
            if (! $html) {
                throw $e;
            }
            $isi = json_decode((string) $e->getResponse()->getContent(), true) ?: [];

            return $this->gagal(true, $e->getResponse()->getStatusCode(), (string) ($isi['code'] ?? ''), (string) ($isi['error'] ?? 'Tautan tidak dapat diproses.'));
        }

        if (! $html) {
            return $this->json([
                'message' => $stateHtml === 'confirmed' ? 'Persetujuan dilanjutkan.' : 'Persetujuan ditarik.',
                'state' => $subjek->transition_state,
                'subject_class' => $subjek->subject_class,
            ]);
        }

        return response()->view('consent.transition_page', [
            'state' => $stateHtml,
            'p' => $this->layanan->pratinjau($subjek),
            'pesan' => null,
            'confirmUrl' => null,
            'withdrawUrl' => null,
        ]);
    }

    private function inginHtml(Request $request): bool
    {
        return ! ($request->wantsJson() && ! $request->acceptsHtml());
    }

    private function gagal(bool $html, int $status, string $kode, string $pesan)
    {
        if (! $html) {
            return $this->json(['error' => $pesan, 'code' => $kode], $status);
        }

        return response()->view('consent.transition_page', [
            'state' => 'error',
            'p' => [],
            'pesan' => $pesan,
            'confirmUrl' => null,
            'withdrawUrl' => null,
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
