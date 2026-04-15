<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint B2: Custom Gap Assessment Questions
 * Allows organizations to add custom questions on top of the default template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_gap_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('regulation_code', 20)->default('uupdp');
            $table->string('category');
            $table->string('subcategory')->nullable();
            $table->text('question');
            $table->text('explanation')->nullable();
            $table->text('recommendation');
            $table->decimal('weight', 5, 2)->default(1.0);
            $table->string('article')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->index(['org_id', 'regulation_code', 'is_active'], 'custom_gap_q_org_reg_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_gap_questions');
    }
};
