<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RoPA milik pihak ketiga — diisi sendiri oleh pihak ketiga lewat tautan publik.
 *
 * Sengaja TABEL SENDIRI, bukan menumpang `ropas`: register Pasal 31 adalah
 * catatan milik pengendali (tenant). Kalau kiriman pihak ketiga masuk ke sana,
 * angka register, skor GAP, paparan sanksi, dan dashboard ikut terdistorsi.
 * Tautan ke RoPA tenant dibuat eksplisit lewat pivot `ropa_vendor_ropa` —
 * itulah yang nanti dipakai menelusuri "insiden di pihak ketiga menyentuh
 * kegiatan pemrosesan kami yang mana".
 *
 * Token mengikuti pola VendorAssessment: UUID v7 di baris datanya sendiri,
 * sekali kirim (token_consumed_at), dan menjadi tidak berlaku begitu di-rotate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_ropas')) {
            Schema::create('vendor_ropas', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id');
                $t->uuid('vendor_id');

                // Isi RoPA versi pihak ketiga (ringkas — hanya yang relevan bagi
                // pengendali untuk menilai dan menelusuri dampak insiden).
                $t->string('processing_activity')->nullable();
                $t->text('purpose')->nullable();
                $t->string('legal_basis')->nullable();
                // Peran pihak ketiga pada kegiatan ini; kosakata sama dengan `ropa_vendor`.
                $t->string('role', 32)->default('processor');
                $t->json('data_categories')->nullable();
                $t->json('data_subjects')->nullable();
                $t->string('retention_period')->nullable();
                $t->json('storage_locations')->nullable();   // sistem / lokasi penyimpanan
                $t->boolean('cross_border')->default(false);
                $t->json('cross_border_countries')->nullable();
                $t->json('sub_processors')->nullable();      // [{name, country, purpose}]
                $t->json('security_measures')->nullable();
                $t->text('notes')->nullable();

                // Kontak pengisi di sisi pihak ketiga (PII → dienkripsi di model).
                $t->text('pic_name')->nullable();
                $t->text('pic_email')->nullable();
                $t->text('pic_phone')->nullable();

                $t->string('status', 24)->default('draft');  // draft|submitted|accepted|returned

                // Tautan publik sekali-kirim.
                $t->uuid('access_token')->nullable()->unique();
                $t->timestamp('token_expires_at')->nullable();
                $t->timestamp('token_consumed_at')->nullable();

                $t->timestamp('submitted_at')->nullable();
                $t->string('submitted_ip', 45)->nullable();
                $t->text('submitted_user_agent')->nullable();

                $t->uuid('reviewed_by')->nullable();
                $t->timestamp('reviewed_at')->nullable();
                $t->text('review_notes')->nullable();

                $t->uuid('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['org_id', 'vendor_id'], 'vendor_ropas_org_vendor_idx');
                $t->index(['org_id', 'status'], 'vendor_ropas_org_status_idx');
                $t->foreign('org_id', 'vendor_ropas_org_fk')
                    ->references('id')->on('organizations')->cascadeOnDelete();
                $t->foreign('vendor_id', 'vendor_ropas_vendor_fk')
                    ->references('id')->on('vendors')->cascadeOnDelete();
            });
        }

        // RoPA tenant ↔ RoPA pihak ketiga. Bentuknya meniru pivot RoPA lain
        // (information_system_ropa dst.) supaya seluruh tautan RoPA seragam.
        if (! Schema::hasTable('ropa_vendor_ropa')) {
            Schema::create('ropa_vendor_ropa', function (Blueprint $t) {
                $t->uuid('ropa_id');
                $t->uuid('vendor_ropa_id');
                $t->uuid('org_id');
                $t->text('notes')->nullable();
                $t->timestamps();

                $t->primary(['ropa_id', 'vendor_ropa_id'], 'ropa_vendor_ropa_pk');
                $t->index('vendor_ropa_id', 'ropa_vendor_ropa_vr_idx');
                $t->index('org_id', 'ropa_vendor_ropa_org_idx');
                $t->foreign('ropa_id', 'ropa_vendor_ropa_ropa_fk')
                    ->references('id')->on('ropas')->cascadeOnDelete();
                $t->foreign('vendor_ropa_id', 'ropa_vendor_ropa_vr_fk')
                    ->references('id')->on('vendor_ropas')->cascadeOnDelete();
                $t->foreign('org_id', 'ropa_vendor_ropa_org_fk')
                    ->references('id')->on('organizations')->cascadeOnDelete();
            });
        }

        // Permintaan akses ubah dari pihak ketiga setelah kiriman terkunci.
        // Disetujui pengendali → token dirotasi dan dikirim ke email kontak
        // TERDAFTAR (bukan alamat yang diketik di formulir permintaan).
        if (! Schema::hasTable('vendor_ropa_edit_requests')) {
            Schema::create('vendor_ropa_edit_requests', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('org_id');
                $t->uuid('vendor_ropa_id');
                $t->text('reason');
                $t->string('requested_ip', 45)->nullable();
                $t->text('requested_user_agent')->nullable();
                $t->string('status', 16)->default('pending'); // pending|approved|rejected
                $t->uuid('decided_by')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->text('decision_notes')->nullable();
                $t->timestamps();

                $t->index(['org_id', 'status'], 'vendor_ropa_edit_org_status_idx');
                $t->foreign('vendor_ropa_id', 'vendor_ropa_edit_vr_fk')
                    ->references('id')->on('vendor_ropas')->cascadeOnDelete();
                $t->foreign('org_id', 'vendor_ropa_edit_org_fk')
                    ->references('id')->on('organizations')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_ropa_edit_requests');
        Schema::dropIfExists('ropa_vendor_ropa');
        Schema::dropIfExists('vendor_ropas');
    }
};
