<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Organization;
use App\Models\TenantRole;
use App\Models\User;

class TenantRoleSeeder extends Seeder
{
    public function run(): void
    {
        $allModules = [
            'dashboard', 'gap_assessment', 'ropa', 'dpia', 'data_discovery',
            'contract_review', 'dsr', 'consent', 'breach', 'simulation',
            'users', 'settings'
        ];

        $allWrite = [];
        foreach ($allModules as $mod) {
            $allWrite[] = "$mod:read";
            $allWrite[] = "$mod:write";
        }
        $allRead = [];
        foreach ($allModules as $mod) {
            $allRead[] = "$mod:read";
        }

        $dpoPerms = array_merge([], $allWrite);
        
        $makerPerms = [
            'dashboard:read',
            'gap_assessment:read', 'gap_assessment:write',
            'ropa:read', 'ropa:write',
            'dpia:read', 'dpia:write',
            'data_discovery:read', 'data_discovery:write',
            'contract_review:read', 'contract_review:write',
            'dsr:read', 'dsr:write',
            'consent:read', 'consent:write',
            'breach:read', 'breach:write',
            'simulation:read', 'simulation:write',
        ];

        $organizations = Organization::all();
        
        foreach ($organizations as $org) {
            // -------------------------------------------------------
            // Clean up duplicate roles from old migration/seeder mismatch
            // Old migration created: Admin, DPO, Maker, Viewer
            // Old seeder created: Admin / C-Level, DPO (Data Protection Officer), Data Operator / Maker, Viewer / Auditor
            // We keep the migration ones (Admin, DPO, Maker, Viewer) and delete the seeder duplicates
            // -------------------------------------------------------
            $duplicateNames = ['Admin / C-Level', 'DPO (Data Protection Officer)', 'Data Operator / Maker', 'Viewer / Auditor'];
            TenantRole::where('org_id', $org->id)
                ->whereIn('name', $duplicateNames)
                ->each(function ($dupeRole) {
                    // Reassign users from this duplicate to the canonical role before deleting
                    User::where('tenant_role_id', $dupeRole->id)->update(['tenant_role_id' => null]);
                    $dupeRole->delete();
                });

            // Now upsert the canonical roles (matching migration names)
            $adminRole = TenantRole::updateOrCreate(
                ['org_id' => $org->id, 'name' => 'Admin'],
                ['is_system' => true, 'description' => 'Administrator dengan full akses konfigurasi', 'permissions' => ['*']]
            );
            
            $dpoRole = TenantRole::updateOrCreate(
                ['org_id' => $org->id, 'name' => 'DPO'],
                ['is_system' => true, 'description' => 'Data Protection Officer untuk review dan approval', 'permissions' => $dpoPerms]
            );

            $makerRole = TenantRole::updateOrCreate(
                ['org_id' => $org->id, 'name' => 'Maker'],
                ['is_system' => true, 'description' => 'User operasional yang input data', 'permissions' => $makerPerms]
            );

            $viewerRole = TenantRole::updateOrCreate(
                ['org_id' => $org->id, 'name' => 'Viewer'],
                ['is_system' => true, 'description' => 'Akses hanya baca (read-only)', 'permissions' => $allRead]
            );
            
            // Re-link users that got orphaned
            User::where('org_id', $org->id)->where('role', 'admin')->whereNull('tenant_role_id')->update(['tenant_role_id' => $adminRole->id]);
            User::where('org_id', $org->id)->where('role', 'dpo')->whereNull('tenant_role_id')->update(['tenant_role_id' => $dpoRole->id]);
            User::where('org_id', $org->id)->where('role', 'maker')->whereNull('tenant_role_id')->update(['tenant_role_id' => $makerRole->id]);
            User::where('org_id', $org->id)->where('role', 'viewer')->whereNull('tenant_role_id')->update(['tenant_role_id' => $viewerRole->id]);
        }
    }
}

