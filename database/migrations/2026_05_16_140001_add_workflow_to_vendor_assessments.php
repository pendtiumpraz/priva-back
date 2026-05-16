<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 2 — 3-stage approval workflow (Maker → Reviewer → Approver).
 *
 * State machine status (kolom existing diperluas, tidak ganti tipe):
 *   draft               (Maker: vendor dibuat, belum sebar link)
 *   sent                (Maker: link generated, tunggu vendor isi)
 *   submitted           (Vendor sudah submit, antrian review)
 *   review_in_progress  (Reviewer: sedang adjust jawaban)
 *   pending_approval    (Reviewer kirim ke Approver)
 *   approved            (Approver setuju, final)
 *   rejected            (Approver tolak, alasan disimpan)
 *
 * Field baru:
 *   - assigned_reviewer_id  : user yang harus review (set Maker saat send-link)
 *   - assigned_approver_id  : user yang harus approve (set Reviewer saat submit-to-approver)
 *   - reviewer_id, reviewer_actioned_at, reviewer_note  (siapa aktual yang review + kapan + catatan)
 *   - approver_id, approver_actioned_at, approver_note  (sama untuk approver)
 *   - rejection_reason      (kalau status=rejected)
 *   - workflow_locked       (flag: status final, tidak bisa diubah lagi)
 *
 * Sengaja TIDAK pakai foreign key constraint (users) untuk kompat
 * cross-tenant cleanup. Integritas dijaga di application layer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->uuid('assigned_reviewer_id')->nullable()->after('assessed_by')->index();
            $table->uuid('assigned_approver_id')->nullable()->after('assigned_reviewer_id')->index();

            $table->uuid('reviewer_id')->nullable()->after('assigned_approver_id');
            $table->timestamp('reviewer_actioned_at')->nullable();
            $table->text('reviewer_note')->nullable();

            $table->uuid('approver_id')->nullable();
            $table->timestamp('approver_actioned_at')->nullable();
            $table->text('approver_note')->nullable();

            $table->text('rejection_reason')->nullable();
            $table->boolean('workflow_locked')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->dropColumn([
                'assigned_reviewer_id', 'assigned_approver_id',
                'reviewer_id', 'reviewer_actioned_at', 'reviewer_note',
                'approver_id', 'approver_actioned_at', 'approver_note',
                'rejection_reason', 'workflow_locked',
            ]);
        });
    }
};
