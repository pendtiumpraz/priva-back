<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant customizable DPIA assessment framework.
 *
 *   dpia_categories         — 21 UU PDP principle categories (or tenant custom)
 *   dpia_category_risks     — 5 default risk events per category (or tenant custom)
 *
 * On first access to DPIA by a tenant, backend auto-seeds from system defaults
 * (see DpiaCategoryService::ensureSeeded). After that, DPO CRUDs freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dpia_categories')) {
            Schema::create('dpia_categories', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->string('name', 200);
                $table->text('description')->nullable();
                $table->integer('sequence')->default(0);
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->index(['org_id', 'is_active', 'sequence']);
                $table->unique(['org_id', 'name']);
            });
        }

        if (!Schema::hasTable('dpia_category_risks')) {
            Schema::create('dpia_category_risks', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('org_id');
                $table->uuid('category_id');
                $table->string('risk_event', 400);
                $table->text('description')->nullable();
                $table->integer('sequence')->default(0);
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->index(['org_id', 'category_id', 'is_active', 'sequence']);
                $table->foreign('category_id')->references('id')->on('dpia_categories')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dpia_category_risks');
        Schema::dropIfExists('dpia_categories');
    }
};
