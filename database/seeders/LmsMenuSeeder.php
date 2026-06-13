<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\RoleMenuWhitelist;
use Illuminate\Database\Seeder;

class LmsMenuSeeder extends Seeder
{
    public function run(): void
    {
        $menu = MenuItem::updateOrCreate(
            ['menu_key' => 'lms'],
            [
                'parent_menu_id' => null,
                'label' => 'Learn',
                'href' => '/learn',
                'icon' => 'GraduationCap',
                'section' => 'Menu Utama',
                'sort_order' => 100,
                'hideable' => true,
                'required_packages' => [],
            ]
        );

        // Whitelist 'lms' for ALL roles → the Learn menu is visible by default
        // for every tenant (MenuRegistryService hides un-whitelisted menus).
        // Root/superadmin can still hide it per-tenant via an entitlement revoke
        // (is_entitled=false), which Layer 0a honors before this whitelist.
        foreach (['root', 'superadmin', 'admin', 'dpo', 'maker', 'viewer'] as $role) {
            RoleMenuWhitelist::updateOrCreate(
                ['menu_id' => $menu->id, 'role' => $role],
                ['is_allowed' => true],
            );
        }
    }
}
