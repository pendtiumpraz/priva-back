<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PERBAIKAN atas migrasi 2026_09_15_000005. Dua kekeliruan, keduanya bentuk.
 *
 * KEKELIRUAN PERTAMA — TABELNYA SALAH.
 * ------------------------------------
 * Fase 1a memasang `subject_class`, `transition_date`, `transition_state`, dan
 * `subject_own_channel` di `consent_records`, lalu menaruh foreign key
 * `guardian_consents.consent_record_id` ke sana.
 *
 * `consent_records` TIDAK PERNAH DITULIS. Penelusuran seluruh app/ menemukan
 * NOL penulis; dua penulis yang ada (`ConsentLogController::capture` dan
 * `ConsentApiV1Controller::capture`) keduanya menulis ke `consent_logs`.
 * ConsentCollectionPoint::logs() dan ConsentStateResolver sudah menyebutkan hal
 * ini sejak sebelum Fase 1a ditulis — saya melewatkannya.
 *
 * Akibatnya kalau dibiarkan: seluruh Fase 2 berdiri di atas tabel yang tidak
 * pernah berisi. Gerbang usia akan memeriksa baris yang tak pernah ada,
 * menyimpulkan "bukan anak", dan MELOLOSKAN setiap penangkapan consent anak —
 * diam-diam, tanpa galat, dengan tampilan yang persis seperti bekerja.
 *
 * KEKELIRUAN KEDUA — SATUAN YANG SALAH.
 * -------------------------------------
 * Bentuk lama mengikat kewenangan wali ke SATU baris consent. Itu mencampur
 * tiga hal yang umurnya berbeda:
 *
 *   - siapa walinya          → tetap, jarang berubah      (guardians)
 *   - siapa yang dilindungi
 *     dan kapan ia dewasa    → satu per orang             (consent_subjects)
 *   - wali boleh bertindak
 *     untuk siapa, terverifikasi
 *     bagaimana              → bisa dicabut & dipulihkan  (guardian_consents)
 *   - apa yang disetujui     → satu per kejadian          (consent_logs)
 *
 * Kalau semuanya satu baris, tiap kali subjek mengubah preferensi lahir baris
 * kewenangan baru yang MENYALIN hasil verifikasi. Lalu saat kewenangan wali
 * dicabut — hak asuh pindah, wali meninggal — pencabutan harus menyentuh semua
 * salinan itu. Satu terlewat = wali yang sudah tidak berwenang masih terbaca
 * berwenang. Dan `transition_date` tersalin ke tiap baris, sehingga seorang
 * anak dengan dua wali punya dua tanggal dewasa yang bisa menyimpang.
 *
 * KENAPA BOLEH MEMBANGUN ULANG, BUKAN MENGUBAH BERTAHAP.
 * -----------------------------------------------------
 * `guardian_consents` dibuat kemarin dan NOL baris di mana pun — tidak ada satu
 * pun penulis di dalam kode, sehingga tidak mungkin ada isinya. Membangun ulang
 * di sini adalah satu-satunya momen ketika biayanya nol.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Subjek yang dilindungi ────────────────────────────────────────
        //
        // Baris di sini HANYA lahir untuk subjek yang benar-benar menempuh jalur
        // wali. Orang dewasa biasa tidak pernah punya baris — itu penting, dan
        // bukan sekadar hemat tempat: status disabilitas adalah data pribadi
        // spesifik, dan alat pelindung privasi yang mendata seluruh penggunanya
        // "demi kepatuhan" sedang menciptakan risiko yang lebih besar daripada
        // yang diselesaikannya. Lihat catatan sama di DisabilityServiceScope.
        if (! Schema::hasTable('consent_subjects')) {
            Schema::create('consent_subjects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id')->index();

                // Hash ternormalkan atas penanda subjek — kunci pencarian.
                //
                // Di sini hash BUKAN demi kerahasiaan: `consent_logs.user_identifier`
                // memang tidak tersandi, jadi menyandikannya di tabel ini tidak
                // menambah perlindungan apa pun. Gunanya NORMALISASI: satu
                // aturan (App\Support\KunciPencarian) yang menyamakan
                // "Budi@Contoh.ID " dengan "budi@contoh.id" dan "+6281..."
                // dengan "081...". Tanpa itu satu anak bisa punya dua baris
                // subjek, masing-masing dengan tanggal peralihannya sendiri.
                $t->string('subject_hash', 64);

                // Penandanya sendiri, tersandi — supaya layar "kewenangan untuk
                // siapa" bisa menampilkan sesuatu sebelum ada satu pun baris
                // consent. Jalur wali dua langkah memang memverifikasi wali
                // LEBIH DULU, baru consent-nya ditangkap.
                $t->text('subject_label');

                // anak | disabilitas | dewasa. Berubah saat peralihan Pasal 38
                // ayat (8) rampung — karena itu ia ada di sini, bukan di ledger.
                $t->string('subject_class', 16);

                // TANGGAL PERALIHAN, bukan tanggal lahir. Cukup untuk Pasal 38
                // ayat (8), dan tanggal lahir anak tidak pernah masuk sistem.
                $t->date('transition_date')->nullable();

                // null | menunggu_konfirmasi | dikonfirmasi | ditarik
                $t->string('transition_state', 24)->nullable();

                // Kanal milik SUBJEK sendiri, tersandi. Untuk consent anak,
                // penanda subjeknya biasanya kontak ORANG TUA — sehingga saat
                // anak itu dewasa sistem tidak punya cara menghubunginya. Kolom
                // inilah jalan keluarnya, dan ia satu per orang, bukan per wali.
                $t->text('subject_own_channel')->nullable();

                $t->uuid('created_by')->nullable();
                $t->timestamps();

                $t->unique(['org_id', 'subject_hash'], 'consent_subjects_org_hash_unq');
                $t->index(['transition_date', 'transition_state'], 'consent_subjects_transisi_idx');
            });
        }

        // ── 2. Kewenangan dibangun ulang di atas subjek ──────────────────────
        Schema::dropIfExists('guardian_consents');

        Schema::create('guardian_consents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->index();
            $t->uuid('consent_subject_id');
            $t->uuid('guardian_id');

            // Rujukan LONGGAR ke verification_methods, bukan foreign key:
            // metode bisa dinonaktifkan atau dihapus tenant, sementara bukti
            // verifikasi yang sudah terjadi tidak boleh ikut hilang.
            $t->string('verification_method_code', 48)->nullable();
            $t->string('verification_driver', 24)->nullable();
            $t->string('verification_confidence', 16)->nullable();
            $t->timestamp('verified_at')->nullable();

            // Nomor rujukan dari penyedia. BUKAN NIK, BUKAN foto KTP.
            $t->string('verification_reference')->nullable();

            // Teks persis yang dilihat wali saat kewenangan ini diberikan, dan
            // dari mana ia mengekliknya.
            $t->text('statement_shown')->nullable();
            $t->string('ip_address', 64)->nullable();
            $t->text('user_agent')->nullable();

            // Pemutusan kewenangan: peralihan anak dewasa (otomatis, Pasal 38
            // ayat 8) dan pemutusan manual — wali meninggal, hak asuh dicabut.
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason')->nullable();

            $t->timestamps();

            $t->index(['org_id', 'consent_subject_id'], 'gc_org_subject_idx');
            $t->index(['org_id', 'guardian_id'], 'gc_org_guardian_idx');

            // SENGAJA TANPA indeks unik (subjek, wali). Kewenangan bisa dicabut
            // lalu dipulihkan — hak asuh kembali, salah cabut. Indeks unik akan
            // memaksa baris pertama ditimpa, dan riwayat pencabutan pertama
            // hilang justru pada kasus yang paling perlu ditelusuri.

            // restrictOnDelete, BUKAN cascade. Ini catatan kepatuhan: menghapus
            // seorang wali tidak boleh ikut menghapus bukti bahwa kewenangannya
            // pernah ada dan pernah dipakai. `guardians` memakai softDeletes,
            // jadi jalur penghapusan normalnya memang tidak menyentuh ini.
            $t->foreign('consent_subject_id')->references('id')->on('consent_subjects')->restrictOnDelete();
            $t->foreign('guardian_id')->references('id')->on('guardians')->restrictOnDelete();
        });

        // ── 3. Ledger yang HIDUP menunjuk kewenangan yang memayunginya ───────
        if (Schema::hasTable('consent_logs')) {
            Schema::table('consent_logs', function (Blueprint $t) {
                if (! Schema::hasColumn('consent_logs', 'guardian_consent_id')) {
                    // Rujukan longgar, tanpa foreign key. `consent_logs` adalah
                    // ledger tambah-saja yang bisa berisi jutaan baris di tenant
                    // besar; memasang foreign key ke sana berarti penguncian
                    // tabel yang lama saat migrasi, dan cascade apa pun ke
                    // arahnya akan memakan baris audit.
                    $t->uuid('guardian_consent_id')->nullable();
                }
                if (! Schema::hasColumn('consent_logs', 'subject_class')) {
                    // POTRET SAAT PENANGKAPAN, bukan keadaan sekarang. Sengaja
                    // berbeda dari consent_subjects.subject_class: yang ini
                    // menjawab "waktu itu ia masih anak?" dan tidak boleh ikut
                    // berubah saat subjeknya dewasa. Bawaannya 'dewasa' supaya
                    // seluruh baris lama berperilaku persis seperti sebelumnya.
                    $t->string('subject_class', 16)->default('dewasa');
                }
            });

            if (! $this->punyaIndeks('consent_logs', 'consent_logs_wali_idx')) {
                Schema::table('consent_logs', function (Blueprint $t) {
                    $t->index(['org_id', 'guardian_consent_id'], 'consent_logs_wali_idx');
                });
            }
        }

        // ── 4. Penilaian kapasitas menunjuk ORANG, bukan kejadian ────────────
        if (Schema::hasTable('capacity_assessments')
            && Schema::hasColumn('capacity_assessments', 'consent_record_id')
            && ! Schema::hasColumn('capacity_assessments', 'consent_subject_id')) {

            if ($this->punyaIndeks('capacity_assessments', 'capass_org_consent_idx')) {
                Schema::table('capacity_assessments', function (Blueprint $t) {
                    $t->dropIndex('capass_org_consent_idx');
                });
            }

            Schema::table('capacity_assessments', function (Blueprint $t) {
                $t->renameColumn('consent_record_id', 'consent_subject_id');
            });

            Schema::table('capacity_assessments', function (Blueprint $t) {
                $t->index(['org_id', 'consent_subject_id'], 'capass_org_subject_idx');
            });
        }

        // ── 5. Tabel mati dikembalikan seperti semula ────────────────────────
        //
        // Kolom yang ditinggalkan di tabel yang tidak pernah ditulis adalah
        // persis jenis cacat yang sedang diburu sepanjang pekerjaan ini: kendali
        // yang ada, kelihatan benar, dan tidak melakukan apa pun. Dibuang.
        if (Schema::hasTable('consent_records')) {
            if ($this->punyaIndeks('consent_records', 'consent_records_transisi_idx')) {
                Schema::table('consent_records', function (Blueprint $t) {
                    $t->dropIndex('consent_records_transisi_idx');
                });
            }

            $buang = array_values(array_filter(
                ['subject_class', 'transition_date', 'transition_state', 'subject_own_channel'],
                fn ($k) => Schema::hasColumn('consent_records', $k),
            ));

            if ($buang !== []) {
                Schema::table('consent_records', function (Blueprint $t) use ($buang) {
                    $t->dropColumn($buang);
                });
            }
        }
    }

    public function down(): void
    {
        // Mengembalikan bentuk 000005 apa adanya, termasuk salahnya — `down()`
        // yang "memperbaiki sambil mundur" membuat maju-mundur tidak simetris
        // dan menyembunyikan migrasi mana yang sebenarnya berjalan.
        if (Schema::hasTable('capacity_assessments') && Schema::hasColumn('capacity_assessments', 'consent_subject_id')) {
            if ($this->punyaIndeks('capacity_assessments', 'capass_org_subject_idx')) {
                Schema::table('capacity_assessments', function (Blueprint $t) {
                    $t->dropIndex('capass_org_subject_idx');
                });
            }
            Schema::table('capacity_assessments', function (Blueprint $t) {
                $t->renameColumn('consent_subject_id', 'consent_record_id');
            });
            Schema::table('capacity_assessments', function (Blueprint $t) {
                $t->index(['org_id', 'consent_record_id'], 'capass_org_consent_idx');
            });
        }

        if (Schema::hasTable('consent_logs')) {
            if ($this->punyaIndeks('consent_logs', 'consent_logs_wali_idx')) {
                Schema::table('consent_logs', function (Blueprint $t) {
                    $t->dropIndex('consent_logs_wali_idx');
                });
            }
            Schema::table('consent_logs', function (Blueprint $t) {
                foreach (['guardian_consent_id', 'subject_class'] as $kolom) {
                    if (Schema::hasColumn('consent_logs', $kolom)) {
                        $t->dropColumn($kolom);
                    }
                }
            });
        }

        Schema::dropIfExists('guardian_consents');
        Schema::dropIfExists('consent_subjects');

        if (Schema::hasTable('consent_records')) {
            Schema::table('consent_records', function (Blueprint $t) {
                if (! Schema::hasColumn('consent_records', 'subject_class')) {
                    $t->string('subject_class', 16)->default('dewasa');
                }
                if (! Schema::hasColumn('consent_records', 'transition_date')) {
                    $t->date('transition_date')->nullable();
                }
                if (! Schema::hasColumn('consent_records', 'transition_state')) {
                    $t->string('transition_state', 24)->nullable();
                }
                if (! Schema::hasColumn('consent_records', 'subject_own_channel')) {
                    $t->text('subject_own_channel')->nullable();
                }
            });

            if (! $this->punyaIndeks('consent_records', 'consent_records_transisi_idx')) {
                Schema::table('consent_records', function (Blueprint $t) {
                    $t->index(['transition_date', 'transition_state'], 'consent_records_transisi_idx');
                });
            }
        }

        Schema::create('guardian_consents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('org_id')->index();
            $t->uuid('consent_record_id');
            $t->uuid('guardian_id');
            $t->string('verification_method_code', 48)->nullable();
            $t->string('verification_driver', 24)->nullable();
            $t->string('verification_confidence', 16)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('verification_reference')->nullable();
            $t->text('statement_shown')->nullable();
            $t->string('ip_address', 64)->nullable();
            $t->text('user_agent')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->string('revoke_reason')->nullable();
            $t->timestamps();
            $t->index(['org_id', 'consent_record_id'], 'gc_org_consent_idx');
            $t->index(['org_id', 'guardian_id'], 'gc_org_guardian_idx');
            $t->foreign('consent_record_id')->references('id')->on('consent_records')->cascadeOnDelete();
            $t->foreign('guardian_id')->references('id')->on('guardians')->cascadeOnDelete();
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
