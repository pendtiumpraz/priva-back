<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AiAgentController;
use Tests\TestCase;

/**
 * Penerjemah galat provider AI.
 *
 * Yang digantikannya: isi mentah respons provider dilempar apa adanya ke
 * gelembung chat, LENGKAP dengan blok DEBUG yang memuat 8 karakter pertama dan
 * 4 karakter terakhir API key, panjang kunci, org_id, URL provider, dan nama
 * model. Kebocoran kredensial ke layar, dibungkus sebagai pesan galat.
 *
 * Dua hal yang dikunci di sini:
 *   1. TIDAK ADA isi mentah provider yang ikut keluar — apa pun bentuknya;
 *   2. tiap jenis galat menghasilkan saran tindakan yang BERBEDA, karena
 *      jawabannya memang berbeda: saldo habis harus dibayar, rate limit harus
 *      ditunggu, kunci salah harus diganti admin.
 */
class AiAgentGalatProviderTest extends TestCase
{
    /** Bahan rahasia yang TIDAK BOLEH muncul di keluaran, apa pun galatnya. */
    private const BOCORAN = [
        'sk-17871', '0d47', 'api.deepseek.com', 'deepseek-chat',
        'Authorization', 'Bearer', '019d2d8f-3c37-70a4-8d52-b1eecf021052',
    ];

    private function isiMentah(string $pesan): string
    {
        return json_encode([
            'error' => ['message' => $pesan, 'type' => 'unknown_error', 'code' => 'invalid_request_error'],
            'model' => 'deepseek-chat',
            'url' => 'https://api.deepseek.com/v1/chat/completions',
            'key_start' => 'sk-17871',
            'key_end' => '0d47',
            'org_id' => '019d2d8f-3c37-70a4-8d52-b1eecf021052',
        ]);
    }

    public function test_tidak_pernah_meneruskan_isi_mentah_provider(): void
    {
        /*
         * Pesannya DIVARIASIKAN, bukan hanya statusnya.
         *
         * Versi pertama uji ini memakai satu isi ("Insufficient Balance") untuk
         * semua status, dan setiap status itu jatuh ke cabang yang sudah
         * terklasifikasi. Cabang TERAKHIR — "tidak dikenal", satu-satunya yang
         * tergoda meneruskan isi mentah karena tidak tahu harus bilang apa —
         * tidak pernah tersentuh. Mutasi yang menambahkan `substr($body, 0, 300)`
         * ke cabang itu lolos hijau. Sekarang tiap kombinasi status x pesan diuji.
         */
        $pesan = ['Insufficient Balance', 'invalid api key', 'rate limit exceeded', 'sesuatu yang tidak dikenal'];

        foreach ([400, 401, 402, 403, 418, 429, 500, 503] as $status) {
            foreach ($pesan as $p) {
                $hasil = (string) json_encode(AiAgentController::galatProvider($status, $this->isiMentah($p)));

                foreach (self::BOCORAN as $rahasia) {
                    $this->assertStringNotContainsString(
                        $rahasia,
                        $hasil,
                        "Status {$status} + \"{$p}\" meneruskan bahan yang tidak boleh sampai ke layar: {$rahasia}",
                    );
                }
            }
        }
    }

    public function test_saldo_habis_dikenali_walau_statusnya_bukan_402(): void
    {
        // DeepSeek mengirim "Insufficient Balance" dengan status 400/401, bukan
        // 402. Memutuskan hanya dari status akan salah menamainya "kunci tidak
        // sah" — dan admin lalu mengganti kunci yang sebenarnya tidak apa-apa.
        foreach ([400, 401, 402] as $status) {
            $g = AiAgentController::galatProvider($status, $this->isiMentah('Insufficient Balance'));
            $this->assertSame('saldo_habis', $g['kode'], "Status {$status} salah diklasifikasi.");
            $this->assertFalse($g['bisa_ulang'], 'Mengulang permintaan tidak akan mengisi saldo.');
        }
    }

    public function test_tiap_jenis_galat_punya_kode_dan_saran_sendiri(): void
    {
        $kasus = [
            ['status' => 401, 'body' => '{"error":"invalid api key"}', 'kode' => 'kunci_tidak_sah', 'ulang' => false],
            ['status' => 429, 'body' => '{"error":"rate limit exceeded"}', 'kode' => 'terlalu_sering', 'ulang' => true],
            ['status' => 503, 'body' => 'upstream unavailable', 'kode' => 'penyedia_bermasalah', 'ulang' => true],
            ['status' => 418, 'body' => 'sesuatu yang aneh', 'kode' => 'tidak_dikenal', 'ulang' => true],
        ];

        $saran = [];
        foreach ($kasus as $k) {
            $g = AiAgentController::galatProvider($k['status'], $k['body']);
            $this->assertSame($k['kode'], $g['kode']);
            $this->assertSame($k['ulang'], $g['bisa_ulang']);
            $this->assertNotSame('', trim($g['judul']));
            $this->assertNotSame('', trim($g['saran']));
            $saran[] = $g['saran'];
        }

        // Saran yang sama untuk empat masalah berbeda = tidak ada saran.
        $this->assertCount(count($saran), array_unique($saran));
    }

    public function test_hanya_yang_masuk_akal_diulang_yang_menawarkan_coba_lagi(): void
    {
        // Menawarkan "Coba lagi" untuk saldo habis atau kunci salah hanya
        // memancing orang menekan tombol yang pasti gagal lagi.
        $this->assertFalse(AiAgentController::galatProvider(402, 'insufficient balance')['bisa_ulang']);
        $this->assertFalse(AiAgentController::galatProvider(403, 'unauthorized')['bisa_ulang']);
        $this->assertTrue(AiAgentController::galatProvider(429, 'rate limit')['bisa_ulang']);
        $this->assertTrue(AiAgentController::galatProvider(500, 'boom')['bisa_ulang']);
    }
}
