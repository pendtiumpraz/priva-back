<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint C2: RACI matrix column on ROPA and DPIA.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addJsonColumn('ropas', 'raci_matrix');
        $this->addJsonColumn('dpias', 'raci_matrix');
    }

    public function down(): void
    {
        $this->dropColumn('ropas', 'raci_matrix');
        $this->dropColumn('dpias', 'raci_matrix');
    }

    private function addJsonColumn(string $tableName, string $colName): void
    {
        if (!Schema::hasTable($tableName) || Schema::hasColumn($tableName, $colName)) {
            return;
        }
        try {
            Schema::table($tableName, function (Blueprint $table) use ($colName) {
                $table->json($colName)->nullable();
            });
        } catch (\Throwable $e) {
            if (!$this->columnAlreadyExists($e)) throw $e;
        }
    }

    private function dropColumn(string $tableName, string $colName): void
    {
        if (!Schema::hasTable($tableName) || !Schema::hasColumn($tableName, $colName)) {
            return;
        }
        Schema::table($tableName, function (Blueprint $table) use ($colName) {
            $table->dropColumn($colName);
        });
    }

    private function columnAlreadyExists(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'Duplicate column')
            || str_contains($msg, '1060')    // MySQL duplicate column
            || str_contains($msg, '42701');  // PostgreSQL duplicate column
    }
};
