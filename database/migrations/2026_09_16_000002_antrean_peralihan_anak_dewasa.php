<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 — antrean peralihan anak → dewasa (PP 33/2026 Pasal 38 ayat 8).
 *
 * Saat anak genap 18, yang BERAKHIR adalah kewenangan walinya — bukan dasar
 * hukum consent-nya. Persetujuan yang diperoleh secara sah tidak hilang
 * karena ulang tahun. Yang terjadi: kewenangan wali dicabut, subjek diberi
 * tahu lewat kanal MILIKNYA SENDIRI, dan ia memutuskan: melanjutkan, atau
 * menarik. Yang tidak menanggapi masuk antrean kerja pengendali — bukan
 * dicabut otomatis, karena menghapus persetujuan yang sah tanpa diminta
 * bukan perlindungan, melainkan keputusan sepihak atas nama orang lain.
 *
 * Token tautan disimpan HASH (sama alasannya dengan guardian_consents):
 * kebocoran basis data tidak boleh berubah jadi tumpukan tautan yang bisa
 * menarik consent orang lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_subjects', function (Blueprint $t) {
            if (! Schema::hasColumn('consent_subjects', 'transition_token_hash')) {
                $t->string('transition_token_hash', 64)->nullable()->after('transition_state');
            }
            if (! Schema::hasColumn('consent_subjects', 'transition_token_expires_at')) {
                $t->timestamp('transition_token_expires_at')->nullable()->after('transition_token_hash');
            }
            if (! Schema::hasColumn('consent_subjects', 'transition_notified_at')) {
                // Kapan tautan dikirim ke kanal milik subjek. NULL + state
                // menunggu_konfirmasi = tidak punya kanal = antrean kerja.
                $t->timestamp('transition_notified_at')->nullable()->after('transition_token_expires_at');
            }
            if (! Schema::hasColumn('consent_subjects', 'transition_confirmed_at')) {
                // Kapan subjek memutuskan — melanjutkan ATAU menarik; keduanya
                // keputusan, dan keduanya menutup antrean.
                $t->timestamp('transition_confirmed_at')->nullable()->after('transition_notified_at');
            }
        });

        if (! $this->punyaIndeks('consent_subjects', 'consent_subjects_transisi_token_idx')) {
            Schema::table('consent_subjects', function (Blueprint $t) {
                $t->index('transition_token_hash', 'consent_subjects_transisi_token_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->punyaIndeks('consent_subjects', 'consent_subjects_transisi_token_idx')) {
            Schema::table('consent_subjects', function (Blueprint $t) {
                $t->dropIndex('consent_subjects_transisi_token_idx');
            });
        }

        Schema::table('consent_subjects', function (Blueprint $t) {
            foreach (['transition_confirmed_at', 'transition_notified_at', 'transition_token_expires_at', 'transition_token_hash'] as $kolom) {
                if (Schema::hasColumn('consent_subjects', $kolom)) {
                    $t->dropColumn($kolom);
                }
            }
        });
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
