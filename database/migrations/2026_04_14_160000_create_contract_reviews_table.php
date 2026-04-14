<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->string('title');
            $table->string('contract_type')->default('other');
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->longText('contract_text')->nullable();
            $table->json('review_result')->nullable();
            $table->integer('risk_score')->default(0);
            $table->string('overall_rating')->nullable(); // baik/perlu_perbaikan/buruk
            $table->string('status')->default('completed');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_reviews');
    }
};
