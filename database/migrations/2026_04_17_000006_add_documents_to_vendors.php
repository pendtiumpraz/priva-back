<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint D3: Store uploaded document metadata per vendor (TPRM rename).
 * Format: [{ name, path, type, size, uploaded_at }]
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendors') && !Schema::hasColumn('vendors', 'documents')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->json('documents')->nullable();
            });
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
};
