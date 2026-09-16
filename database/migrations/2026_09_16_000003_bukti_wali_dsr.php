<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4 — DSR oleh wali/pendamping (PP 33/2026 Pasal 38 ayat 5–7, Pasal 39 ayat 5).
 *
 * Permohonan yang diajukan WALI atas nama anak / penyandang disabilitas
 * membawa pertanyaan yang tidak ada pada permohonan biasa: benarkah orang ini
 * berwenang? Jawabannya dicatat di sini — bukan disimpulkan dari nama.
 *
 *   subject_identifier        penanda subjek yang diwakili (surel/ID), tersandi;
 *   subject_identifier_hash   kunci pencocokan (KunciPencarian) — untuk mencari
 *                             kewenangan wali yang sudah terverifikasi di modul
 *                             consent (pasangan surel wali × penanda subjek);
 *   guardian_consent_id       kewenangan yang cocok, bila ada → bukti OTOMATIS;
 *   guardian_proof_status     tidak_perlu · otomatis · menunggu · diterima · ditolak;
 *   guardian_proof_*          keputusan DPO: alasan, kapan, siapa.
 *
 * Kolom-kolom bukti SENGAJA TIDAK fillable di model: universal CRUD
 * (PUT /m/dsr/{id}) menyalin payload apa adanya, dan sebuah gerbang keamanan
 * tidak boleh bisa dilepas lewat payload. Ditulis hanya oleh App\Services\Dsr\BuktiWali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dsr_requests', function (Blueprint $t) {
            if (! Schema::hasColumn('dsr_requests', 'subject_identifier')) {
                $t->text('subject_identifier')->nullable()->after('subject_class');
            }
            if (! Schema::hasColumn('dsr_requests', 'subject_identifier_hash')) {
                $t->string('subject_identifier_hash', 64)->nullable()->after('subject_identifier');
            }
            if (! Schema::hasColumn('dsr_requests', 'guardian_consent_id')) {
                $t->uuid('guardian_consent_id')->nullable()->after('subject_identifier_hash');
            }
            if (! Schema::hasColumn('dsr_requests', 'guardian_proof_status')) {
                $t->string('guardian_proof_status', 16)->nullable()->after('guardian_consent_id');
            }
            if (! Schema::hasColumn('dsr_requests', 'guardian_proof_reason')) {
                $t->string('guardian_proof_reason', 1000)->nullable()->after('guardian_proof_status');
            }
            if (! Schema::hasColumn('dsr_requests', 'guardian_proof_verified_at')) {
                $t->timestamp('guardian_proof_verified_at')->nullable()->after('guardian_proof_reason');
            }
            if (! Schema::hasColumn('dsr_requests', 'guardian_proof_verified_by')) {
                $t->uuid('guardian_proof_verified_by')->nullable()->after('guardian_proof_verified_at');
            }
        });

        if (! $this->punyaIndeks('dsr_requests', 'dsr_subject_hash_idx')) {
            Schema::table('dsr_requests', function (Blueprint $t) {
                $t->index(['org_id', 'subject_identifier_hash'], 'dsr_subject_hash_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->punyaIndeks('dsr_requests', 'dsr_subject_hash_idx')) {
            Schema::table('dsr_requests', function (Blueprint $t) {
                $t->dropIndex('dsr_subject_hash_idx');
            });
        }

        Schema::table('dsr_requests', function (Blueprint $t) {
            foreach ([
                'guardian_proof_verified_by', 'guardian_proof_verified_at', 'guardian_proof_reason',
                'guardian_proof_status', 'guardian_consent_id', 'subject_identifier_hash', 'subject_identifier',
            ] as $kolom) {
                if (Schema::hasColumn('dsr_requests', $kolom)) {
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
