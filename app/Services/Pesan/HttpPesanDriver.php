<?php

namespace App\Services\Pesan;

use App\Support\NomorTelepon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Driver `http` — gateway SMS/WhatsApp generik.
 *
 * Tiap penyedia punya kontraknya sendiri (Zenziva: userkey/passkey di badan;
 * Fonnte: token di header, `target` + `message`; Twilio: basic auth + form).
 * Driver ini tidak menghafal satu pun; superadmin memetakan kontraknya lewat
 * templat URL / header / badan dengan placeholder {to} {message} {secret}
 * {sender}. Placeholder diganti pada NILAI yang sudah didekode — bukan pada
 * teks JSON mentah — supaya tanda kutip dalam pesan tidak merusak JSON.
 *
 * Sukses = HTTP 2xx, dan bila `http_success_path` diisi, nilai di path itu
 * harus ada dan (bila `http_success_equals` diisi) sama dengannya.
 */
final class HttpPesanDriver implements PengirimPesan
{
    public const TIMEOUT_MAKS = 30;

    public function kirim(PesanSingkat $pesan, array $cfg): HasilKirim
    {
        $url = trim((string) ($cfg['http_url'] ?? ''));
        if ($url === '') {
            return HasilKirim::gagal('URL gateway belum diatur.');
        }

        $bentuk = (string) ($cfg['to_format'] ?? 'e164');
        $ganti = [
            '{to}' => NomorTelepon::format($pesan->tujuan, in_array($bentuk, NomorTelepon::BENTUK, true) ? $bentuk : 'e164'),
            '{message}' => $pesan->teks,
            '{secret}' => (string) ($cfg['http_secret'] ?? ''),
            '{sender}' => (string) ($cfg['sender_name'] ?? ''),
        ];

        $url = strtr($url, $ganti);
        $headers = $this->petaString($this->peta($cfg['http_headers'] ?? null, $ganti));
        $body = $this->peta($cfg['http_body'] ?? null, $ganti);
        $method = strtoupper((string) ($cfg['http_method'] ?? 'POST')) === 'GET' ? 'GET' : 'POST';
        $timeout = max(1, min(self::TIMEOUT_MAKS, (int) ($cfg['timeout'] ?? 10)));

        try {
            $req = Http::timeout($timeout)->acceptJson()->withHeaders($headers);
            $res = $method === 'GET' ? $req->get($url, $body) : $req->asJson()->post($url, $body);
        } catch (ConnectionException $e) {
            return HasilKirim::gagal('Gateway tidak dapat dihubungi: '.$e->getMessage());
        }

        $status = $res->status();
        if (! $res->successful()) {
            return HasilKirim::gagal("Gateway membalas HTTP {$status}.", $status);
        }

        $path = trim((string) ($cfg['http_success_path'] ?? ''));
        if ($path !== '') {
            $json = $res->json();
            $nilai = is_array($json) ? data_get($json, $path) : null;
            $harap = $cfg['http_success_equals'] ?? null;
            $cocok = ($harap === null || $harap === '')
                ? ! in_array($nilai, [null, false, 'false', 0, '0', ''], true)
                : mb_strtolower(trim((string) (is_scalar($nilai) ? $nilai : ''))) === mb_strtolower(trim((string) $harap));

            if (! $cocok) {
                return HasilKirim::gagal("Gateway menolak: `{$path}` = ".json_encode($nilai), $status);
            }
        }

        return HasilKirim::berhasil($status, $this->referensi($res));
    }

    /**
     * Templat dari config: array, atau string JSON objek. Placeholder diganti
     * pada tiap nilai string, rekursif.
     *
     * @param  array<string, string>  $ganti
     * @return array<int|string, mixed>
     */
    private function peta(mixed $mentah, array $ganti): array
    {
        if (is_string($mentah)) {
            $mentah = trim($mentah) === '' ? [] : json_decode($mentah, true);
        }
        if (! is_array($mentah)) {
            return [];
        }

        $isi = function ($v) use (&$isi, $ganti) {
            if (is_array($v)) {
                return array_map($isi, $v);
            }

            return is_string($v) ? strtr($v, $ganti) : $v;
        };

        return $isi($mentah);
    }

    /**
     * @param  array<int|string, mixed>  $peta
     * @return array<string, string>
     */
    private function petaString(array $peta): array
    {
        $hasil = [];
        foreach ($peta as $k => $v) {
            if (is_string($k) && $k !== '' && (is_scalar($v) || $v === null)) {
                $hasil[$k] = (string) $v;
            }
        }

        return $hasil;
    }

    /** Nomor rujukan dari gateway bila ada — kunci umum yang dipakai penyedia. */
    private function referensi(Response $res): ?string
    {
        $json = $res->json();
        if (! is_array($json)) {
            return null;
        }
        foreach (['id', 'message_id', 'messageId', 'sid', 'reference', 'data.id', 'data.message_id'] as $path) {
            $v = data_get($json, $path);
            if (is_scalar($v) && (string) $v !== '') {
                return (string) $v;
            }
        }

        return null;
    }
}
