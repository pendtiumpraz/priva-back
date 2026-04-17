<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C8: per-DPIA role assignments (reviewer / approver / dpo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dpias') || Schema::hasColumn('dpias', 'assigned_roles')) {
            return;
        }
        try {
            Schema::table('dpias', function (Blueprint $table) {
                $table->json('assigned_roles')->nullable();
            });
        } catch (\Throwable $e) {
            if (!$this->columnDup($e)) throw $e;
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('dpias') && Schema::hasColumn('dpias', 'assigned_roles')) {
            Schema::table('dpias', function (Blueprint $table) {
                $table->dropColumn('assigned_roles');
            });
        }
    }

    private function columnDup(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'Duplicate column') || str_contains($msg, '1060') || str_contains($msg, '42701');
    }
};
