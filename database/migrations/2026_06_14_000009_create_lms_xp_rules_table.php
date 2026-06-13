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
        Schema::create('lms_xp_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('action_key')->unique();
            $table->unsignedInteger('xp_amount');
            $table->jsonb('conditions')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lms_xp_rules');
    }
};
