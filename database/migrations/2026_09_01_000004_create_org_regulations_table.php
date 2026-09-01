<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Status enable/disable regulasi ADD-ON per tenant.
 *
 * Baris hanya ada untuk add-on yang di-toggle tenant. Regulasi core
 * (is_core) dianggap SELALU aktif tanpa perlu baris di sini. Tanpa baris →
 * add-on dianggap nonaktif (default OFF).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_regulations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('regulation_code', 64);
            $table->boolean('enabled')->default(true);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'regulation_code']);
            $table->index(['org_id', 'enabled']);
            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_regulations');
    }
};
