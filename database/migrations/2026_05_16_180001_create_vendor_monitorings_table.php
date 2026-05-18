<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 4 — Schedule monitoring berkala per vendor.
 *
 * Konsep:
 *   - 1 vendor → 0 atau 1 monitoring schedule aktif (unique soft via is_active)
 *   - frequency_months: 3 / 6 / 12 (boleh custom integer lain)
 *   - next_due_at: dihitung dari last_completed_at + frequency_months
 *   - status: 'upcoming' (belum jatuh tempo) | 'due' (jatuh tempo) | 'overdue' (lewat tempo)
 *   - assigned_user_id: reviewer yang ditugaskan (default: creator)
 *
 * Workflow:
 *   1. Setelah Approver setuju assessment, admin set schedule via UI
 *   2. Sistem hitung next_due_at = now() + frequency_months
 *   3. Status di-derive runtime (next_due_at vs now())
 *   4. User klik "Mulai Review" → bikin row di vendor_monitoring_reviews
 *   5. Selesai review → update monitoring.last_completed_at + next_due_at maju
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('vendor_monitorings')) return;
        Schema::create('vendor_monitorings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->uuid('vendor_id')->index();

            $table->unsignedTinyInteger('frequency_months')->default(6); // 3 / 6 / 12 atau custom
            $table->timestamp('next_due_at')->nullable()->index();
            $table->timestamp('last_completed_at')->nullable();

            $table->uuid('assigned_user_id')->nullable()->index();
            $table->uuid('created_by')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();

            // Counter cache: jumlah review yang sudah complete
            $table->unsignedInteger('reviews_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'is_active', 'next_due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_monitorings');
    }
};
