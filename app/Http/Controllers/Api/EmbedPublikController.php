<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dpia;
use App\Models\EmbedToken;
use App\Models\Ropa;
use Illuminate\Http\Request;

/**
 * Endpoint publik untuk tautan embed (tanpa autentikasi).
 *
 * Seluruh penjagaan sudah dilakukan PublicEmbedTokenMiddleware: batas laju,
 * keaktifan token, pemeriksaan origin, dan penetapan tenant context. Di sini
 * tinggal menyajikan data yang SUDAH dibatasi oleh token.
 *
 * Dua lapis penyaringan kolom, dan keduanya perlu: `fields` pada token sudah
 * diiris daftar putih saat penerbitan, tetapi diiris ULANG di sini supaya token
 * lama tetap aman kalau suatu kolom kelak dicabut dari daftar putih.
 */
class EmbedPublikController extends Controller
{
    private function token(Request $request): EmbedToken
    {
        return $request->input('_embedToken');
    }

    /** GET /api/embed-publik/{token} — keterangan tampilan + kolom. */
    public function config(Request $request)
    {
        $embed = $this->token($request);
        $fields = EmbedToken::sanitizeFields($embed->module, $embed->fields);

        return $this->withCors($request, response()->json([
            'data' => [
                'label' => $embed->label,
                'module' => $embed->module,
                // sanitizeFields menjamin $f ada di ALLOWED_FIELDS, dan setiap
                // kolom di sana punya label — pencariannya total.
                'fields' => array_map(fn ($f) => [
                    'key' => $f,
                    'label' => EmbedToken::FIELD_LABELS[$f],
                ], $fields),
                'organization' => $embed->organization?->name,
            ],
        ]));
    }

    /** GET /api/embed-publik/{token}/data — baris terkurasi. */
    public function data(Request $request)
    {
        $embed = $this->token($request);
        $module = $embed->module;
        $fields = EmbedToken::sanitizeFields($module, $embed->fields);
        $filters = EmbedToken::sanitizeFilters($module, $embed->filters);

        $model = $module === EmbedToken::MODULE_DPIA ? new Dpia : new Ropa;

        // org_id disaring eksplisit, tidak bersandar pada scope global saja:
        // jalur publik terlalu mahal untuk dipertaruhkan pada konteks ambient.
        $query = $model->newQuery()->where('org_id', $embed->org_id);
        foreach ($filters as $kolom => $nilai) {
            $query->where($kolom, $nilai);
        }

        $items = $query->orderBy('created_at', 'desc')
            ->paginate(min((int) ($request->per_page ?? 25), 100));

        // Hanya kolom terpilih yang keluar — bukan seluruh baris lalu disaring
        // di klien, karena yang terkirim ke browser sudah tidak bisa ditarik.
        $rows = collect($items->items())->map(function ($row) use ($fields) {
            $out = [];
            foreach ($fields as $f) {
                $nilai = $row->getAttribute($f);
                $out[$f] = $nilai instanceof \DateTimeInterface ? $nilai->toDateString() : $nilai;
            }

            return $out;
        })->values();

        // Jejak pemakaian: berguna saat pemilik menimbang perlu-tidaknya cabut.
        $embed->forceFill([
            'last_used_at' => now(),
            'view_count' => $embed->view_count + 1,
        ])->saveQuietly();

        return $this->withCors($request, response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]));
    }

    /**
     * CORS dijawab sesempit mungkin: origin yang memang terdaftar pada token,
     * bukan '*', kecuali token itu sendiri memang tidak dibatasi.
     */
    private function withCors(Request $request, $response)
    {
        $embed = $this->token($request);
        $origin = $request->headers->get('Origin');
        $daftar = $embed->allowed_origins ?? [];

        return $response
            ->header('Access-Control-Allow-Origin', empty($daftar) ? '*' : (string) $origin)
            ->header('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type');
    }
}
