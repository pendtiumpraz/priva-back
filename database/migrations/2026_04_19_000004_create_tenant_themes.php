<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_themes')) return;

        try {
            Schema::create('tenant_themes', function (Blueprint $t) {
                $t->uuid('id')->primary();
                // NULL = platform-level theme (root/superadmin scope). NOT NULL = tenant-scoped.
                $t->uuid('org_id')->nullable()->index();
                $t->string('name', 120);
                // Color palette: primary, accent, bg, card_bg, text, text_muted, border, danger, success
                $t->json('palette');
                $t->string('logo_url', 500)->nullable();
                $t->string('favicon_url', 500)->nullable();
                $t->string('layout_preset', 40)->default('classic'); // classic|compact|brand-heavy|minimal
                $t->string('font_family', 60)->default('Inter');
                $t->boolean('is_active')->default(false);
                $t->uuid('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['org_id', 'is_active']);
            });
        } catch (\Throwable $e) {
            // Cross-DB already-exists tolerance (MySQL 1050 / PG 42P07)
            $msg = $e->getMessage();
            if (!str_contains($msg, '42S01') && !str_contains($msg, '42P07')
                && !str_contains($msg, '1050') && !str_contains($msg, 'already exists')) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_themes');
    }
};
