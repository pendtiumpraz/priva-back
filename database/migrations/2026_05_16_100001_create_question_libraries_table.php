<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 1 — Question Library wrapper.
 *
 * Sebelumnya pertanyaan asesmen pihak ketiga flat di `vendor_questionnaires`
 * dengan field `category` + `version` sebagai pseudo-grouping. Tabel ini
 * naik satu tingkat: 1 library = 1 set pertanyaan utuh untuk use case
 * tertentu (mis. "PDP Compliance UU 27/2022 v2_2026", "ISO 27001 Lite",
 * "Custom — Vendor Cloud").
 *
 * Akses:
 *   - org_id NULL + is_locked=true → template platform global (read-only
 *     untuk tenant; cuma bisa di-clone)
 *   - org_id = tenant            → library private milik tenant tsb
 *
 * `category` tetap dipertahankan untuk backward compat — saat bikin
 * assessment via API lama, slug category masih bisa dipakai untuk
 * lookup library. Saat builder UI siap, FE switch ke library_id.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('question_libraries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->nullable()->index();    // NULL = global template
            $table->string('name');                         // "PDP Compliance UU 27/2022"
            $table->string('slug')->nullable();             // 'pdp_compliance' (untuk URL)
            $table->text('description')->nullable();
            $table->string('category')->nullable()->index(); // mapping ke vendor_questionnaires.category lama
            $table->string('version')->default('v1');       // 'v2_2026'

            // Sumber library:
            //  - 'seeded'  = library platform default (dari seeder)
            //  - 'custom'  = di-build tenant from scratch
            //  - 'cloned'  = duplicate dari template
            $table->string('source')->default('custom');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_locked')->default(false);   // tenant TIDAK bisa edit (template global)

            // Counter cache supaya list page tidak perlu COUNT subquery
            $table->unsignedInteger('segments_count')->default(0);
            $table->unsignedInteger('questions_count')->default(0);

            // Suggested use case tags (JSON array of strings: ["cloud", "saas", "finance"])
            $table->jsonb('tags')->nullable();

            $table->uuid('created_by')->nullable();
            $table->uuid('cloned_from_library_id')->nullable()->index();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'is_active']);
            $table->index(['category', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_libraries');
    }
};
