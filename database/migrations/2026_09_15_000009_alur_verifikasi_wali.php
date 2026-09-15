<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fase 2 — alur verifikasi wali (PP 33/2026 Pasal 38 ayat 2 & 4).
 *
 * Yang ditambahkan ke `guardian_consents`:
 *
 *   collection_point_id      lewat titik pengumpulan mana kewenangan ini
 *                            diminta — untuk menampilkan pernyataan yang
 *                            benar kepada wali, dan untuk audit.
 *   verification_token_hash  SHA-256 atas token tautan surel. DISIMPAN HASH,
 *                            bukan token mentahnya: kebocoran basis data tidak
 *                            boleh berubah jadi tumpukan tautan persetujuan
 *                            yang bisa diklik siapa saja.
 *   verification_expires_at  24 jam; setelah itu tautan mati dan wali harus
 *                            diminta ulang.
 *   pending_capture          Pilihan consent yang MENUNGGU wali — tersandi.
 *                            Ini SENGAJA tidak ditulis ke `consent_logs` lebih
 *                            dulu: ledger itu "yang terbaru menang", sehingga
 *                            baris "menunggu" akan terbaca sebagai persetujuan
 *                            yang sah oleh ConsentStateResolver. Baris ledger
 *                            baru lahir saat wali benar-benar menyetujui.
 *
 * Yang diseed: dua metode bawaan platform (org_id NULL) untuk driver OTP —
 * `otp_email` dan `otp_phone` — dengan keyakinan `rendah`, apa adanya:
 * menguasai kotak surel membuktikan seseorang sengaja menyetujui dan bisa
 * dimintai tanggung jawab, bukan bahwa ia orang tua si anak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardian_consents', function (Blueprint $t) {
            if (! Schema::hasColumn('guardian_consents', 'collection_point_id')) {
                $t->uuid('collection_point_id')->nullable()->after('guardian_id');
            }
            if (! Schema::hasColumn('guardian_consents', 'verification_token_hash')) {
                $t->string('verification_token_hash', 64)->nullable()->after('verification_reference');
            }
            if (! Schema::hasColumn('guardian_consents', 'verification_expires_at')) {
                $t->timestamp('verification_expires_at')->nullable()->after('verification_token_hash');
            }
            if (! Schema::hasColumn('guardian_consents', 'pending_capture')) {
                $t->text('pending_capture')->nullable()->after('statement_shown');
            }
        });

        if (! $this->punyaIndeks('guardian_consents', 'gc_token_hash_idx')) {
            Schema::table('guardian_consents', function (Blueprint $t) {
                $t->index('verification_token_hash', 'gc_token_hash_idx');
            });
        }

        foreach ([
            ['code' => 'otp_email', 'label' => 'Tautan verifikasi ke surel wali'],
            ['code' => 'otp_phone', 'label' => 'Kode OTP ke telepon wali'],
        ] as $metode) {
            $ada = DB::table('verification_methods')
                ->whereNull('org_id')
                ->where('code', $metode['code'])
                ->exists();

            if (! $ada) {
                DB::table('verification_methods')->insert([
                    'id' => (string) Str::uuid(),
                    'org_id' => null,
                    'code' => $metode['code'],
                    'label' => $metode['label'],
                    'driver' => 'otp',
                    'confidence' => 'rendah',
                    'is_active' => true,
                    'review_at' => null,
                    'config' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if ($this->punyaIndeks('guardian_consents', 'gc_token_hash_idx')) {
            Schema::table('guardian_consents', function (Blueprint $t) {
                $t->dropIndex('gc_token_hash_idx');
            });
        }

        Schema::table('guardian_consents', function (Blueprint $t) {
            foreach (['collection_point_id', 'verification_token_hash', 'verification_expires_at', 'pending_capture'] as $kolom) {
                if (Schema::hasColumn('guardian_consents', $kolom)) {
                    $t->dropColumn($kolom);
                }
            }
        });

        DB::table('verification_methods')
            ->whereNull('org_id')
            ->whereIn('code', ['otp_email', 'otp_phone'])
            ->delete();
    }

    /** SQLite dan Postgres berbeda cara melaporkan indeks — tanya lewat Doctrine-less API. */
    private function punyaIndeks(string $tabel, string $indeks): bool
    {
        try {
            return collect(Schema::getIndexes($tabel))
                ->contains(fn ($i) => ($i['name'] ?? null) === $indeks);
        } catch (Throwable $e) {
            return false;
        }
    }
};
