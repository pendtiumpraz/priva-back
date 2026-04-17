<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint D4: Custom RACI + AI-generated dynamic containment steps on breach.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('breach_incidents')) {
            return;
        }
        $this->addJsonCol('custom_raci');
        $this->addJsonCol('containment_steps');
    }

    public function down(): void
    {
        if (!Schema::hasTable('breach_incidents')) return;
        foreach (['custom_raci', 'containment_steps'] as $col) {
            if (Schema::hasColumn('breach_incidents', $col)) {
                Schema::table('breach_incidents', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }

    private function addJsonCol(string $col): void
    {
        if (Schema::hasColumn('breach_incidents', $col)) return;
        try {
            Schema::table('breach_incidents', function (Blueprint $table) use ($col) {
                $table->json($col)->nullable();
            });
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $dup = str_contains($msg, 'Duplicate column') || str_contains($msg, '1060') || str_contains($msg, '42701');
            if (!$dup) throw $e;
        }
    }
};
