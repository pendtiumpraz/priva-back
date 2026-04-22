<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-ROPA linkage for breach incidents.
 *
 * Keeps legacy linked_ropa_id (single FK → the primary/first ROPA) for
 * backward compatibility with existing queries/views. New column stores
 * the full list, so one breach can reference N ROPAs (e.g. when a single
 * incident touches multiple processing activities).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->json('linked_ropa_ids')->nullable()->after('linked_ropa_id');
        });
    }

    public function down(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->dropColumn('linked_ropa_ids');
        });
    }
};
