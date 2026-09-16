<?php

namespace App\Services\Verifikasi;

use App\Models\VerificationMethod;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Driver HTTP generik untuk Dukcapil / e-KYC.
 *
 * Kontrak tiap penyedia berbeda — layanan Dukcapil Kemendagri memakai
 * `user_id`/`password` di badan permintaan dan membalas per bidang
 * ("NAMA_LGKP": "Sesuai"), penyedia e-KYC komersial memakai bearer token dan
 * membalas `{"verified": true}`. Karena itu driver ini tidak menghafal satu
 * pun kontrak; tenant memetakan kontraknya sendiri lewat `config`:
 *
 *   endpoint            URL penyedia (wajib)
 *   method              GET | POST (bawaan POST)
 *   timeout             detik, 1–30 (bawaan 10)
 *   headers             {nama: nilai} — kredensial header (Authorization, X-Api-Key)
 *   body                {bidang: nilai} — kredensial badan + placeholder klaim:
 *                       {nik}  {name}  {birth_date}
 *   birth_date_format   format tanggal lahir yang diminta penyedia (bawaan Y-m-d)
 *   mismatch_any        [{path, equals}] — bila SATU saja terpenuhi → tidak_cocok
 *                       (mis. RESPON = "Data Tidak Ditemukan")
 *   match_all           [{path, equals}] — SEMUA harus terpenuhi → cocok
 *                       (mis. content.0.NAMA_LGKP = "Sesuai")
 *   reference_path      path nomor rujukan penyedia — satu-satunya yang disimpan
 *   reason_path         path pesan penyedia untuk alasan tidak_cocok
 *
 * Urutan penilaian: galat koneksi / bukan JSON → gagal; `mismatch_any` →
 * tidak_cocok; `match_all`: path yang TIDAK ADA → gagal (kontrak berubah atau
 * kredensial ditolak — bukan salah wali), path ada tapi nilainya lain →
 * tidak_cocok; semua terpenuhi → cocok.
 */
final class HttpVerifikasiDriver implements PenyediaVerifikasi
{
    /** Penanda "path tidak ada" — dibedakan dari nilai null yang sah dari penyedia. */
    private const TIDAK_ADA = "\0tidak-ada\0";

    public function periksa(VerificationMethod $metode, KlaimIdentitas $klaim): HasilVerifikasi
    {
        $cfg = $metode->config ?? [];

        $endpoint = trim((string) ($cfg['endpoint'] ?? ''));
        if ($endpoint === '') {
            return HasilVerifikasi::terganggu('Endpoint penyedia belum diatur pada metode ini.');
        }

        $aturanCocok = $this->aturan($cfg['match_all'] ?? null);
        if ($aturanCocok === []) {
            return HasilVerifikasi::terganggu('Aturan kecocokan (match_all) belum diatur pada metode ini.');
        }

        $method = strtoupper((string) ($cfg['method'] ?? 'POST')) === 'GET' ? 'GET' : 'POST';
        $timeout = max(1, min(VerificationMethod::TIMEOUT_MAKS, (int) ($cfg['timeout'] ?? VerificationMethod::TIMEOUT_BAWAAN)));
        $headers = $this->petaString($cfg['headers'] ?? []);
        $body = $this->isiPlaceholder(
            is_array($cfg['body'] ?? null) ? $cfg['body'] : [],
            $klaim,
            (string) ($cfg['birth_date_format'] ?? 'Y-m-d'),
        );

        try {
            $req = Http::timeout($timeout)->acceptJson()->withHeaders($headers);
            $res = $method === 'GET' ? $req->get($endpoint, $body) : $req->asJson()->post($endpoint, $body);
        } catch (ConnectionException $e) {
            // Pesan pengecualian klien HTTP tidak memuat badan permintaan —
            // hanya URL dan sebab jaringan — jadi aman diteruskan ke admin.
            return HasilVerifikasi::terganggu('Penyedia tidak dapat dihubungi: '.$e->getMessage());
        }

        return $this->nilai($res, $cfg, $aturanCocok);
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  list<array{path: string, equals: mixed}>  $aturanCocok
     */
    private function nilai(Response $res, array $cfg, array $aturanCocok): HasilVerifikasi
    {
        $status = $res->status();
        $json = $res->json();

        if (! is_array($json)) {
            return HasilVerifikasi::terganggu("Penyedia membalas HTTP {$status} tanpa badan JSON.", $status);
        }

        $alasan = $this->teksPada($json, $cfg['reason_path'] ?? null);

        foreach ($this->aturan($cfg['mismatch_any'] ?? null) as $a) {
            $nilai = data_get($json, $a['path'], self::TIDAK_ADA);
            if ($nilai !== self::TIDAK_ADA && $this->sama($nilai, $a['equals'])) {
                return HasilVerifikasi::ditolak($alasan ?? 'Data tidak ditemukan atau tidak sesuai menurut penyedia.', $status);
            }
        }

        foreach ($aturanCocok as $a) {
            $nilai = data_get($json, $a['path'], self::TIDAK_ADA);
            if ($nilai === self::TIDAK_ADA) {
                return HasilVerifikasi::terganggu(
                    "Bidang `{$a['path']}` tidak ada dalam respons penyedia (HTTP {$status})".($alasan ? ": {$alasan}" : '.'),
                    $status,
                );
            }
            if (! $this->sama($nilai, $a['equals'])) {
                return HasilVerifikasi::ditolak($alasan ?? 'Data tidak sesuai menurut penyedia.', $status);
            }
        }

        return HasilVerifikasi::terbukti($this->teksPada($json, $cfg['reference_path'] ?? null), $status);
    }

    // ───────────────────────── bantu ─────────────────────────

    /**
     * Normalisasi daftar aturan {path, equals}; entri rusak diabaikan.
     *
     * @return list<array{path: string, equals: mixed}>
     */
    private function aturan(mixed $mentah): array
    {
        if (! is_array($mentah)) {
            return [];
        }
        // Satu objek {path, equals} juga diterima, bukan hanya daftar.
        if (isset($mentah['path'])) {
            $mentah = [$mentah];
        }

        $hasil = [];
        foreach ($mentah as $a) {
            if (is_array($a) && isset($a['path']) && is_string($a['path']) && $a['path'] !== '' && array_key_exists('equals', $a)) {
                $hasil[] = ['path' => $a['path'], 'equals' => $a['equals']];
            }
        }

        return $hasil;
    }

    /**
     * Perbandingan yang memaafkan perbedaan tipe di JSON penyedia: `true`,
     * "true", 1, "1" sama; "Sesuai" dan "sesuai " sama. Yang TIDAK dimaafkan:
     * nilai kosong dianggap sama dengan apa pun.
     */
    private function sama(mixed $nilai, mixed $harap): bool
    {
        if (is_bool($harap)) {
            return is_bool($nilai) ? $nilai === $harap
                : filter_var($nilai, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === $harap;
        }
        if (is_int($harap) || is_float($harap)) {
            return is_numeric($nilai) && (float) $nilai === (float) $harap;
        }
        if (is_array($nilai) || is_object($nilai) || $nilai === null) {
            return false;
        }

        return mb_strtolower(trim((string) $nilai)) === mb_strtolower(trim((string) $harap));
    }

    /** @param  array<string, mixed>  $json */
    private function teksPada(array $json, mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        $v = data_get($json, $path);
        if ($v === null || is_array($v) || is_object($v)) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /**
     * @param  array<int|string, mixed>  $template
     * @return array<int|string, mixed>
     */
    private function isiPlaceholder(array $template, KlaimIdentitas $klaim, string $formatTanggal): array
    {
        $ganti = [$klaim->nik(), $klaim->nama(), $klaim->tanggalLahir($formatTanggal !== '' ? $formatTanggal : 'Y-m-d')];

        $isi = function ($v) use (&$isi, $ganti) {
            if (is_array($v)) {
                return array_map($isi, $v);
            }
            if (is_string($v)) {
                return str_replace(VerificationMethod::PLACEHOLDER_KLAIM, $ganti, $v);
            }

            return $v;
        };

        return $isi($template);
    }

    /** @return array<string, string> */
    private function petaString(mixed $mentah): array
    {
        if (! is_array($mentah)) {
            return [];
        }
        $peta = [];
        foreach ($mentah as $k => $v) {
            if (is_string($k) && $k !== '' && (is_scalar($v) || $v === null)) {
                $peta[$k] = (string) $v;
            }
        }

        return $peta;
    }
}
