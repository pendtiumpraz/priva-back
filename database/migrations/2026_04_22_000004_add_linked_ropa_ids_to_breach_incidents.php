<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-RoPA linkage for breach incidents.
 *
 * Keeps legacy linked_ropa_id (single FK → the primary/first RoPA) for
 * backward compatibility with existing queries/views. New column stores
 * the full list, so one breach can reference N RoPAs (e.g. when a single
 * incident touches multiple processing activities).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('breach_incidents', 'linked_ropa_ids')) {
            return;
        }
        try {
            Schema::table('breach_incidents', function (Blueprint $table) {
                $table->json('linked_ropa_ids')->nullable()->after('linked_ropa_id');
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
        if (! Schema::hasColumn('breach_incidents', 'linked_ropa_ids')) {
            return;
        }
        try {
            Schema::table('breach_incidents', function (Blueprint $table) {
                $table->dropColumn('linked_ropa_ids');
            });
        } catch (QueryException $e) { /* already gone */
        }
    }
};
