<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint G — Public link sharing untuk pihak ketiga (no login).
 *
 * Flow:
 *   1. Admin tenant generate token (UUID v7) → status='sent'
 *   2. Share URL https://nexus.privasimu.com/asesmen-pihak-ketiga/{token}
 *   3. Pihak ketiga buka link tanpa login, isi kuisoner + upload bukti
 *   4. Submit → status='submitted', token_consumed_at di-set, ip + ua dicatat
 *   5. URL setelah consumed: read-only result page
 *
 * Security:
 *   - Token expiry default 30 hari (configurable via system_settings)
 *   - Single-use: consumed_at set sekali, submit kedua di-block
 *   - Rate limit: max 3 percobaan submit per token per 10 menit (di middleware)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->uuid('assessment_token')->nullable()->unique()->after('id');
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('token_consumed_at')->nullable();
            $table->string('status', 32)->default('draft')->index();
            // draft | sent | submitted | reviewed | closed
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->text('submitted_user_agent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vendor_assessments', function (Blueprint $table) {
            $table->dropColumn([
                'assessment_token', 'token_expires_at', 'token_consumed_at',
                'status', 'submitted_at', 'submitted_ip', 'submitted_user_agent',
            ]);
        });
    }
};
