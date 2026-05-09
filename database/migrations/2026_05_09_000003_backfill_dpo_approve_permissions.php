<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill: tenant_roles dengan is_system=true dan name='DPO' yang sudah
 * eksis sebelum DPO preset di-update di Organization::booted() — pastikan
 * mereka punya '<module>:approve' permission untuk semua module yang
 * support approval workflow (ropa, dpia, dsr, breach, cross_border, vendor_risk).
 *
 * Tanpa ini, tenant lama yang dibuat sebelum 2026-05-09 punya DPO role tanpa
 * explicit :approve, sehingga dropdown step approver di Approval Workflow
 * Config kelihatan kosong meskipun controller sudah implicit-allow lewat
 * `is_system && name=DPO`.
 */
return new class extends Migration
{
    private const APPROVE_KEYS = [
        'ropa:approve',
        'dpia:approve',
        'dsr:approve',
        'breach:approve',
        'cross_border:approve',
        'vendor_risk:approve',
    ];

    public function up(): void
    {
        $rows = DB::table('tenant_roles')
            ->where('is_system', true)
            ->whereRaw('LOWER(name) = ?', ['dpo'])
            ->get(['id', 'permissions']);

        foreach ($rows as $r) {
            $perms = is_string($r->permissions) ? json_decode($r->permissions, true) : (array) $r->permissions;
            $perms = is_array($perms) ? $perms : [];

            $merged = array_values(array_unique(array_merge($perms, self::APPROVE_KEYS)));

            DB::table('tenant_roles')
                ->where('id', $r->id)
                ->update([
                    'permissions' => json_encode($merged),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Tidak rollback — meninggalkan permissions extended aman; menghapus
        // approve keys bisa membatalkan workflow yang sudah dipakai user.
    }
};
