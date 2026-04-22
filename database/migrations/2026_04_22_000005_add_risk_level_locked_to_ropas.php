<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROPA risk level is computed automatically from 7-step wizard triggers
 * (Sprint E1). This flag lets a tenant admin opt out of the auto-calc
 * and pin a manual risk level — useful for rare cases where the business
 * context overrides the wizard signal.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->boolean('risk_level_locked')->default(false)->after('risk_level');
        });
    }

    public function down(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->dropColumn('risk_level_locked');
        });
    }
};
