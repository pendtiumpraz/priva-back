<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    public function up(): void
    {
        Schema::create('feature_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->string('title');
            $table->text('description');
            $table->string('category'); // module, ui, integration, security, other
            $table->string('priority')->default('medium'); // low, medium, high, critical
            $table->string('status')->default('submitted'); // submitted, reviewing, planned, in_progress, completed, rejected
            $table->text('admin_notes')->nullable();
            $table->integer('votes')->default(1);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_requests');
    }
};
