<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase D — Document template customization.
 *
 * `document_templates` — preset library (system + tenant-custom).
 * Config JSON drives every PDF export's look. Applies to ROPA, DPIA,
 * GAP, Breach reports, Compliance Dashboard.
 *
 * `tenant_themes.active_document_template_id` points to the current
 * preset. If null, fall back to the default system template.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->nullable()->index();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->string('preview_image', 255)->nullable();
            $table->json('config');              // full styling payload
            $table->boolean('is_default')->default(false);
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('usage_count')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['org_id', 'is_system']);
        });

        Schema::table('tenant_themes', function (Blueprint $table) {
            $table->uuid('active_document_template_id')->nullable()->after('layout_preset');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_themes', function (Blueprint $table) {
            $table->dropColumn('active_document_template_id');
        });
        Schema::dropIfExists('document_templates');
    }
};
