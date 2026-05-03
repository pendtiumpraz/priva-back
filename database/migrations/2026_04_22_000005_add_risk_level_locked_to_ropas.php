<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RoPA risk level is computed automatically from 7-step wizard triggers
 * (Sprint E1). This flag lets a tenant admin opt out of the auto-calc
 * and pin a manual risk level — useful for rare cases where the business
 * context overrides the wizard signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ropas', 'risk_level_locked')) {
            return;
        }
        try {
            Schema::table('ropas', function (Blueprint $table) {
                $table->boolean('risk_level_locked')->default(false)->after('risk_level');
            });
        } catch (QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) {
                return;
            }
            throw $e;
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('ropas', 'risk_level_locked')) {
            return;
        }
        try {
            Schema::table('ropas', function (Blueprint $table) {
                $table->dropColumn('risk_level_locked');
            });
        } catch (QueryException $e) { /* already gone */
        }
    }
};
