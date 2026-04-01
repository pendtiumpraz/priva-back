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
            'dashboard', 'gap-assessment', 'ropa', 'dpia', 'data-discovery',
            'contract-review', 'dsr', 'consent', 'breach', 'simulation',
            'users', 'settings'
        ];

        // Explicit all write/read for easy mapping
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
            'gap-assessment:read', 'gap-assessment:write',
            'ropa:read', 'ropa:write',
            'dpia:read', 'dpia:write',
            'data-discovery:read', 'data-discovery:write',
            'contract-review:read', 'contract-review:write',
            'dsr:read', 'dsr:write',
            'consent:read', 'consent:write',
            'breach:read', 'breach:write'
        ];

        $organizations = Organization::all();
        
        foreach ($organizations as $org) {
            // Admin
            $adminRole = TenantRole::firstOrCreate(
                ['org_id' => $org->id, 'name' => 'Admin / C-Level'],
                ['is_system' => true, 'description' => 'Akses penuh ke semua modul tenant', 'permissions' => ['*']]
            );
            
            // DPO
            $dpoRole = TenantRole::firstOrCreate(
                ['org_id' => $org->id, 'name' => 'DPO (Data Protection Officer)'],
                ['is_system' => true, 'description' => 'Akses penuh & manajemen privasi, tanpa pengaturan teknis', 'permissions' => $dpoPerms]
            );

            // Maker
            $makerRole = TenantRole::firstOrCreate(
                ['org_id' => $org->id, 'name' => 'Data Operator / Maker'],
                ['is_system' => true, 'description' => 'Bisa input dan edit data operasional', 'permissions' => $makerPerms]
            );

            // Viewer
            $viewerRole = TenantRole::firstOrCreate(
                ['org_id' => $org->id, 'name' => 'Viewer / Auditor'],
                ['is_system' => true, 'description' => 'Hanya bisa melihat data (Read Only)', 'permissions' => $allRead]
            );
            
            // Migrate users
            $users = User::where('org_id', $org->id)->get();
            foreach ($users as $u) {
                if ($u->role === 'admin') $u->tenant_role_id = $adminRole->id;
                elseif ($u->role === 'dpo') $u->tenant_role_id = $dpoRole->id;
                elseif ($u->role === 'maker') $u->tenant_role_id = $makerRole->id;
                elseif ($u->role === 'viewer') $u->tenant_role_id = $viewerRole->id;
                
                if ($u->isDirty()) $u->save();
            }
        }
    }
}
