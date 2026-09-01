<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registry regulasi platform (landlord).
 *
 * Arahan produk: UU PDP (uu_pdp) + PP 33/2026 (pp_33) adalah baseline WAJIB
 * (is_core) yang selalu aktif dan tidak bisa dimatikan tenant. Regulasi lain
 * (POJK/OJK, UU ITE, GDPR, PDPA, ISO) adalah ADD-ON opsional — default nonaktif,
 * dapat di-enable per tenant lewat org_regulations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();      // uu_pdp, pp_33, pojk, uu_ite, gdpr, ...
            $table->string('name');
            $table->string('short', 120)->nullable();
            $table->string('category', 64)->nullable(); // id_core, id_sektoral, internasional, standar
            $table->boolean('is_core')->default(false); // wajib, tak bisa dimatikan (uu_pdp, pp_33)
            $table->boolean('default_enabled')->default(false);
            $table->text('description')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulations');
    }
};
