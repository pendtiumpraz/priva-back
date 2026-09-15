<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tulang punggung wali — PP 33/2026 Pasal 38 (Anak) & 39 (Penyandang Disabilitas).
 *
 * Fase 1a dari rancangan "Consent Anak & Disabilitas". Belum ada UI, belum ada
 * driver verifikasi kuat; yang dipasang di sini adalah bentuk datanya, supaya
 * dua modul di atasnya bisa berdiri tanpa menduplikasi ledger consent.
 *
 * TIGA KEPUTUSAN BENTUK YANG PERLU DIBACA SEBELUM MENGUBAH APA PUN:
 *
 * 1. `guardians` berdiri sendiri, tidak ditempel ke consent.
 *    Satu wali menaungi banyak persetujuan — dan bisa menaungi anak MAUPUN
 *    penyandang disabilitas. Menempelkannya ke tiap baris consent akan
 *    menduplikasi orang yang sama berkali-kali, lalu menyulitkan saat
 *    kewenangannya harus dicabut sekaligus.
 *
 * 2. Kontaknya terenkripsi, dan ADA `contact_hash` di sebelahnya.
 *    `Crypt::encryptString` memakai IV acak — dua enkripsi atas nilai sama
 *    menghasilkan sandi berbeda, sehingga `WHERE contact = ?` TIDAK PERNAH
 *    cocok. Tanpa hash, pencarian wali yang sama akan selalu gagal dan sistem
 *    membuat wali duplikat tiap kali. Hash-nya SHA-256 atas nilai ternormalkan;
 *    ia bisa dicari dan diindeks tanpa memaparkan kontaknya.
 *
 * 3. Yang disimpan dari verifikasi adalah HASILNYA, bukan datanya.
 *    `verification_reference` diisi nomor rujukan penyedia — BUKAN NIK, BUKAN
 *    foto KTP. Alat pelindung privasi yang menimbun data identitas seluruh wali
 *    anak Indonesia adalah kontradiksi, sekaligus target serangan yang luar
 *    biasa. Aturan ini berlaku untuk driver dukcapil dan e-KYC nanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('guardians')) {
            Schema::create('guardians', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();

                // Terenkripsi di lapisan model (cast EncryptedString).
                $t->text('name');
                $t->string('contact_type', 16);          // email | phone
                $t->text('contact');

                // SHA-256 atas kontak ternormalkan — satu-satunya jalan mencari
                // wali yang sama. Lihat catatan (2) di atas.
                $t->string('contact_hash', 64);

                // Hubungan DINYATAKAN SENDIRI oleh yang bersangkutan. Disimpan
                // sebagai pernyataan, bukan sebagai fakta terverifikasi —
                // verifikasinya urusan guardian_consents.
                $t->string('relationship', 32);          // orang_tua | wali_sah | pendamping | lainnya
                $t->string('relationship_note')->nullable();

                $t->uuid('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                // Satu kontak = satu wali per organisasi. Inilah yang dijaga
                // contact_hash; tanpa indeks ini, duplikat tetap bisa masuk
                // lewat balapan dua permintaan bersamaan.
                $t->unique(['org_id', 'contact_hash'], 'guardians_org_contact_unq');
            });
        }

        if (! Schema::hasTable('verification_methods')) {
            Schema::create('verification_methods', function (Blueprint $t) {
                $t->uuid('id')->primary();

                // NULL = bawaan platform, terlihat semua tenant. Karena itu
                // model-nya sengaja TIDAK memakai BelongsToOrg: global scope
                // `org` akan menyaring baris NULL dan bawaan platform lenyap.
                $t->uuid('org_id')->nullable()->index();

                $t->string('code', 48);
                $t->string('label');
                $t->string('driver', 24);                // otp | dukcapil | ekyc
                $t->string('confidence', 16);            // rendah | sedang | tinggi
                $t->boolean('is_active')->default(true);

                // "Mempertimbangkan teknologi yang tersedia" (Pasal 38 ayat 4)
                // adalah standar BERGERAK. Tanpa tanggal tinjau ulang, satu
                // metode akan disetel sekali lalu basi diam-diam.
                $t->date('review_at')->nullable();

                // Kredensial milik TENANT (mis. API key Dukcapil), terenkripsi
                // di lapisan model. Privasimu tidak menjadi perantara identitas
                // untuk semua tenant sekaligus.
                $t->text('config')->nullable();

                $t->timestamps();
                $t->unique(['org_id', 'code'], 'verification_methods_org_code_unq');
            });
        }

        if (! Schema::hasTable('guardian_consents')) {
            Schema::create('guardian_consents', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();
                $t->uuid('consent_record_id');
                $t->uuid('guardian_id');

                // Rujukan LONGGAR ke verification_methods, bukan foreign key:
                // metode bisa dinonaktifkan atau dihapus tenant, sementara
                // bukti verifikasi yang sudah terjadi tidak boleh ikut hilang.
                $t->string('verification_method_code', 48)->nullable();
                $t->string('verification_driver', 24)->nullable();
                $t->string('verification_confidence', 16)->nullable();
                $t->timestamp('verified_at')->nullable();

                // Nomor rujukan dari penyedia. BUKAN NIK, BUKAN foto KTP.
                $t->string('verification_reference')->nullable();

                // Teks persis yang dilihat wali saat menyetujui. Inilah bukti
                // bahwa yang disetujui memang spesifik — bukan "saya setuju".
                $t->text('statement_shown')->nullable();

                $t->string('ip_address', 64)->nullable();
                $t->text('user_agent')->nullable();

                // Pemutusan kewenangan. Dipakai DUA hal yang berbeda:
                // peralihan anak dewasa (otomatis, Pasal 38 ayat 8), dan
                // pemutusan manual oleh pengendali — wali meninggal, hak asuh
                // dicabut, anak pindah wali. PP tidak mengatur yang kedua, tapi
                // pasti terjadi.
                $t->timestamp('revoked_at')->nullable();
                $t->string('revoke_reason')->nullable();

                $t->timestamps();

                $t->index(['org_id', 'consent_record_id'], 'gc_org_consent_idx');
                $t->index(['org_id', 'guardian_id'], 'gc_org_guardian_idx');

                $t->foreign('consent_record_id')->references('id')->on('consent_records')->cascadeOnDelete();
                $t->foreign('guardian_id')->references('id')->on('guardians')->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('consent_records')) {
            Schema::table('consent_records', function (Blueprint $t) {
                if (! Schema::hasColumn('consent_records', 'subject_class')) {
                    // Bawaannya 'dewasa' — seluruh baris lama tetap berperilaku
                    // persis seperti sebelum migrasi ini.
                    $t->string('subject_class', 16)->default('dewasa');
                }
                if (! Schema::hasColumn('consent_records', 'transition_date')) {
                    // TANGGAL PERALIHAN, bukan tanggal lahir. Cukup untuk
                    // Pasal 38 ayat (8), dan tanggal lahir anak tidak pernah
                    // masuk sistem.
                    $t->date('transition_date')->nullable();
                }
                if (! Schema::hasColumn('consent_records', 'transition_state')) {
                    // null | menunggu_konfirmasi | dikonfirmasi | ditarik
                    $t->string('transition_state', 24)->nullable();
                }
                if (! Schema::hasColumn('consent_records', 'subject_own_channel')) {
                    // Kanal milik SUBJEK sendiri, terenkripsi. Untuk consent
                    // anak, `subject_identifier` biasanya berisi kontak ORANG
                    // TUANYA — sehingga saat anak itu dewasa sistem tidak punya
                    // cara menghubunginya. Kolom inilah jalan keluarnya.
                    $t->text('subject_own_channel')->nullable();
                }
            });

            if (! $this->punyaIndeks('consent_records', 'consent_records_transisi_idx')) {
                Schema::table('consent_records', function (Blueprint $t) {
                    // Antrean peralihan memindai berdasarkan tanggal + status.
                    $t->index(['transition_date', 'transition_state'], 'consent_records_transisi_idx');
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('guardian_consents');
        Schema::dropIfExists('verification_methods');
        Schema::dropIfExists('guardians');

        if (Schema::hasTable('consent_records')) {
            if ($this->punyaIndeks('consent_records', 'consent_records_transisi_idx')) {
                Schema::table('consent_records', function (Blueprint $t) {
                    $t->dropIndex('consent_records_transisi_idx');
                });
            }

            Schema::table('consent_records', function (Blueprint $t) {
                foreach (['subject_class', 'transition_date', 'transition_state', 'subject_own_channel'] as $kolom) {
                    if (Schema::hasColumn('consent_records', $kolom)) {
                        $t->dropColumn($kolom);
                    }
                }
            });
        }
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
