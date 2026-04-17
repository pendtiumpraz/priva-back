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
        Schema::table('vendors', function (Blueprint $table) {
            $table->json('documents')->nullable()->after('services_provided');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('documents');
        });
    }
};
