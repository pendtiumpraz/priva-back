<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint D3: Store uploaded document metadata per vendor (TPRM rename).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vendors') || Schema::hasColumn('vendors', 'documents')) {
            return;
        }
        try {
            Schema::table('vendors', function (Blueprint $table) {
                $table->json('documents')->nullable();
            });
        } catch (\Throwable $e) {
            if (!$this->columnDup($e)) throw $e;
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'documents')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('documents');
            });
        }
    }

    private function columnDup(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'Duplicate column') || str_contains($msg, '1060') || str_contains($msg, '42701');
    }
};
