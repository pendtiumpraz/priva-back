<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use Illuminate\Database\Seeder;

class LmsMenuSeeder extends Seeder
{
    public function run(): void
    {
        MenuItem::updateOrCreate(
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
    }
}
