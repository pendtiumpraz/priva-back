<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tulang punggung aksesibilitas — PP 33/2026 Pasal 38 & 39, Fase 1b.
 *
 * TIGA KEPUTUSAN BENTUK:
 *
 * 1. Yang didaftarkan adalah KANAL, bukan ORANG.
 *    `disability_service_scopes` mencatat ragam disabilitas apa yang DILAYANI
 *    sebuah kanal — bukan status disabilitas tiap pengguna. Status disabilitas
 *    seseorang adalah data pribadi spesifik; menyimpannya untuk semua pengguna
 *    demi "kepatuhan" justru menciptakan risiko yang lebih besar daripada yang
 *    diselesaikan. Keputusan ini sudah disepakati dan tidak boleh dibalik
 *    diam-diam.
 *
 * 2. Prasarana punya TANGGAL UJI, bukan sekadar centang.
 *    Ayat (3) menuntut prasarana yang mudah dipahami. Prasarana yang tidak
 *    pernah diuji ulang adalah KLAIM, bukan fakta — dan klaim itulah yang
 *    dibawa ke audit. `last_tested_at` yang kosong harus terbaca sebagai
 *    "belum terbukti", bukan sebagai "belum sempat".
 *
 * 3. Penilaian kapasitas menempel ke DPIA.
 *    Penjelasan Pasal 38 ayat (5)–(6) sendiri menaruhnya di penilaian dampak,
 *    jadi `dpia_id` ada sejak awal — bukan ditambahkan belakangan saat orang
 *    sadar keduanya berhubungan.
 *
 * Ragam disabilitas mengikuti UU 8/2016 Pasal 4: fisik, intelektual, mental,
 * dan sensorik. Sensorik dipecah tiga (netra, rungu, wicara) karena prasarana
 * yang dibutuhkan ketiganya berbeda sama sekali — braille tidak menolong
 * tunarungu, juru bahasa isyarat tidak menolong tunanetra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accessibility_provisions')) {
            Schema::create('accessibility_provisions', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();

                // Kanal diberi nama tenant sendiri ("Loket Cabang", "Portal
                // DSR"). Bila kanalnya memang titik pengumpulan consent, id-nya
                // ikut ditautkan supaya prasarana dan widgetnya tidak terpisah.
                $t->string('channel');
                $t->uuid('collection_point_id')->nullable();

                $t->string('format', 32);              // lihat AccessibilityProvision::FORMAT
                $t->string('format_note')->nullable(); // dipakai saat format = lainnya

                $t->boolean('is_available')->default(false);

                // Rujukan bukti — id dokumen, tautan laporan uji, atau nomor
                // berita acara. Bukan berkasnya sendiri.
                $t->string('evidence_ref')->nullable();

                $t->date('last_tested_at')->nullable();
                $t->date('next_review_at')->nullable();
                $t->text('notes')->nullable();

                $t->uuid('created_by')->nullable();
                $t->timestamps();

                $t->unique(['org_id', 'channel', 'format'], 'accprov_org_channel_format_unq');
                $t->index(['org_id', 'last_tested_at'], 'accprov_org_tested_idx');
            });
        }

        if (! Schema::hasTable('disability_service_scopes')) {
            Schema::create('disability_service_scopes', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();

                $t->string('channel');
                $t->uuid('collection_point_id')->nullable();

                $t->string('ragam', 32);               // lihat DisabilityServiceScope::RAGAM
                $t->boolean('is_served')->default(false);
                $t->text('notes')->nullable();

                $t->uuid('created_by')->nullable();
                $t->timestamps();

                $t->unique(['org_id', 'channel', 'ragam'], 'dscope_org_channel_ragam_unq');
            });
        }

        if (! Schema::hasTable('capacity_assessments')) {
            Schema::create('capacity_assessments', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();

                // Apa yang memicunya — persetujuan atau permohonan hak. Keduanya
                // nullable: penilaian bisa dilakukan mendahului keduanya.
                $t->uuid('consent_record_id')->nullable();
                $t->uuid('dsr_request_id')->nullable();

                $t->string('subject_class', 16);       // anak | disabilitas
                $t->string('result', 32);              // lihat CapacityAssessment::HASIL
                $t->text('reason');                    // WAJIB — penilaian tanpa alasan bukan penilaian

                $t->uuid('assessed_by')->nullable();
                $t->timestamp('assessed_at')->nullable();

                // Penjelasan ayat (5)–(6) menaruh ini di penilaian dampak.
                $t->uuid('dpia_id')->nullable();

                $t->timestamps();

                $t->index(['org_id', 'consent_record_id'], 'capass_org_consent_idx');
                $t->index(['org_id', 'dsr_request_id'], 'capass_org_dsr_idx');
            });
        }

        if (Schema::hasTable('dsr_requests')) {
            Schema::table('dsr_requests', function (Blueprint $t) {
                if (! Schema::hasColumn('dsr_requests', 'requester_type')) {
                    // Pasal 39 ayat (5): "Penyandang Disabilitas DAN/ATAU wali
                    // ... dapat mengajukan permintaan pemenuhan hak". Kata
                    // dan/atau itu mengunci bawaannya: 'subjek'. Portal DSR
                    // tidak boleh mewajibkan wali, dan jalur diri-sendiri harus
                    // mulus — tanpa pertanyaan tambahan dibanding pemohon lain.
                    $t->string('requester_type', 16)->default('subjek');
                }
                if (! Schema::hasColumn('dsr_requests', 'requester_relation')) {
                    $t->string('requester_relation', 32)->nullable();
                }
                if (! Schema::hasColumn('dsr_requests', 'subject_class')) {
                    $t->string('subject_class', 16)->default('dewasa');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('capacity_assessments');
        Schema::dropIfExists('disability_service_scopes');
        Schema::dropIfExists('accessibility_provisions');

        if (Schema::hasTable('dsr_requests')) {
            Schema::table('dsr_requests', function (Blueprint $t) {
                foreach (['requester_type', 'requester_relation', 'subject_class'] as $kolom) {
                    if (Schema::hasColumn('dsr_requests', $kolom)) {
                        $t->dropColumn($kolom);
                    }
                }
            });
        }
    }
};
