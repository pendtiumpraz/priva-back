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
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
        });
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->uuid('org_id')->nullable()->change();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table('ai_active_selections', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
        });
        Schema::table('ai_active_selections', function (Blueprint $table) {
            $table->uuid('org_id')->nullable()->change();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
        });
        Schema::table('ai_provider_configs', function (Blueprint $table) {
            $table->uuid('org_id')->nullable(false)->change();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::table('ai_active_selections', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
        });
        Schema::table('ai_active_selections', function (Blueprint $table) {
            $table->uuid('org_id')->nullable(false)->change();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }
};
