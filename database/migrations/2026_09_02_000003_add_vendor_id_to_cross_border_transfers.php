<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautkan transfer lintas-negara ke registri pihak ketiga (vendor).
 *
 * Sebelumnya `destination_entity` + kontak DPO penerima diketik bebas —
 * duplikasi dengan registri pihak ketiga (Vendor / TPRM) yang sudah ditautkan
 * di form RoPA. Dengan vendor_id, nama entitas & kontak DPO disalin otomatis
 * dari vendor (server-authoritative). Transfer ad-hoc tetap boleh isi manual
 * (vendor_id null) — persis pola PPDP internal vs pihak ketiga.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cross_border_transfers') || Schema::hasColumn('cross_border_transfers', 'vendor_id')) {
            return;
        }

        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->uuid('vendor_id')->nullable()->after('org_id');
            $table->index('vendor_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cross_border_transfers') || ! Schema::hasColumn('cross_border_transfers', 'vendor_id')) {
            return;
        }

        Schema::table('cross_border_transfers', function (Blueprint $table) {
            $table->dropIndex(['vendor_id']);
            $table->dropColumn('vendor_id');
        });
    }
};
