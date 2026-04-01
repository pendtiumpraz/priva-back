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
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->uuid('pic_id')->nullable()->after('dpo_id');
            $table->string('pic_name')->nullable()->after('pic_id');
            $table->foreign('pic_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->dropForeign(['pic_id']);
            $table->dropColumn(['pic_id', 'pic_name']);
        });
    }
};
