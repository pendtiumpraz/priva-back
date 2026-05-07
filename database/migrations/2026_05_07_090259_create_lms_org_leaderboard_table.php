<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lms_org_leaderboard', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('org_id');
            $table->uuid('user_id');
            $table->unsignedInteger('xp_total')->default(0);
            $table->unsignedInteger('badges_count')->default(0);
            $table->unsignedInteger('courses_completed')->default(0);
            $table->timestamp('computed_at')->nullable();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['org_id', 'user_id']);
            $table->index(['org_id', 'xp_total']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_org_leaderboard');
    }
};
