<?php

use App\Support\KunciPencarian;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kunci pencarian untuk `dsr_requests.requester_email` yang tersandi.
 *
 * MEMPERBAIKI BUG YANG DIAM. Dua tempat memeriksa permohonan DSR ganda dengan
 * `where('requester_email', $plaintext)` — DsrPublicController:117 dan
 * V1\DsrApiV1Controller:117. Kolom itu memakai cast EncryptedString
 * (AES-256-CBC, IV acak), sehingga dua penyandian atas surel yang sama
 * menghasilkan sandi berbeda dan perbandingan itu TIDAK PERNAH cocok.
 *
 * Akibatnya pemeriksaan anti-duplikat tidak pernah menemukan apa pun sejak
 * ditulis: subjek yang mengirim permohonan berulang kali menghasilkan
 * permohonan baru terus, masing-masing dengan tenggat 3x24 jam sendiri.
 * Tidak ada galat, tidak ada tanda — query-nya hanya mengembalikan kosong.
 *
 * BACKFILL. Baris lama ikut diisi supaya pemeriksaannya langsung bekerja atas
 * data yang sudah ada — kalau tidak, duplikat lama tetap tak terdeteksi dan
 * perbaikannya baru berlaku untuk permohonan yang datang setelah deploy.
 * Penyandian dibuka di sini, bukan lewat model, supaya migrasi tidak
 * bergantung pada keadaan aplikasi; nilai lama yang terlanjur plaintext
 * (fallback EncryptedString) ikut tertangani.
 *
 * TIDAK unik. Permohonan ganda memang mungkin sah — misalnya permohonan lama
 * yang sudah selesai lalu subjek mengajukan lagi. Yang dicegah controller
 * adalah dua permohonan AKTIF; indeks unik di sini justru akan menolak riwayat
 * yang sah.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dsr_requests')) {
            return;
        }

        if (! Schema::hasColumn('dsr_requests', 'requester_email_hash')) {
            Schema::table('dsr_requests', function (Blueprint $t) {
                $t->string('requester_email_hash', 64)->nullable()->after('requester_email');
                $t->index(['org_id', 'requester_email_hash'], 'dsr_org_email_hash_idx');
            });
        }

        DB::table('dsr_requests')
            ->whereNull('requester_email_hash')
            ->orderBy('id')
            ->chunkById(500, function ($baris) {
                foreach ($baris as $b) {
                    $surel = $this->bukaSandi($b->requester_email ?? null);
                    $hash = KunciPencarian::hash($surel);
                    if ($hash === null) {
                        continue;
                    }

                    DB::table('dsr_requests')->where('id', $b->id)->update([
                        'requester_email_hash' => $hash,
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('dsr_requests') || ! Schema::hasColumn('dsr_requests', 'requester_email_hash')) {
            return;
        }

        Schema::table('dsr_requests', function (Blueprint $t) {
            $t->dropIndex('dsr_org_email_hash_idx');
            $t->dropColumn('requester_email_hash');
        });
    }

    /** Cermin EncryptedString::get — nilai lama yang plaintext dikembalikan apa adanya. */
    private function bukaSandi(?string $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        try {
            return Crypt::decryptString($nilai);
        } catch (Throwable $e) {
            return $nilai;
        }
    }
};
