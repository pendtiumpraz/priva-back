<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PertaminaHoldingSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding Pertamina Holding Structure...');

        // ========== HOLDING ==========
        $holding = Organization::create([
            'name'      => 'PT Pertamina (Persero)',
            'slug'      => 'pt-pertamina-persero',
            'industry'  => 'Oil & Gas',
            'org_level' => 'holding',
            'parent_id' => null,
            'email'     => 'privacy@pertamina.com',
            'website'   => 'https://www.pertamina.com',
            'address'   => 'Jl. Medan Merdeka Timur 1A, Jakarta Pusat 10110',
            'onboarding_completed' => true,
            'ai_credits_monthly'   => 500,
            'ai_credits_remaining' => 500,
        ]);
        $this->command->info("  ✅ Holding: {$holding->name}");

        // ========== SUB HOLDING ==========
        $subHoldings = [
            [
                'name'     => 'PT Pertamina Patra Niaga (Sub Holding Commercial & Trading)',
                'slug'     => 'pertamina-patra-niaga',
                'industry' => 'Commercial & Trading',
            ],
            [
                'name'     => 'PT Pertamina Hulu Energi (Sub Holding Upstream)',
                'slug'     => 'pertamina-hulu-energi',
                'industry' => 'Oil & Gas Upstream',
            ],
            [
                'name'     => 'PT Kilang Pertamina Internasional (Sub Holding Refining & Petrochemical)',
                'slug'     => 'kilang-pertamina-intl',
                'industry' => 'Refining & Petrochemical',
            ],
        ];

        $subHoldingModels = [];
        foreach ($subHoldings as $sh) {
            $model = Organization::create([
                'name'      => $sh['name'],
                'slug'      => $sh['slug'],
                'industry'  => $sh['industry'],
                'org_level' => 'sub_holding',
                'parent_id' => $holding->id,
                'email'     => 'privacy@' . Str::slug($sh['slug']) . '.co.id',
                'onboarding_completed' => true,
                'ai_credits_monthly'   => 200,
                'ai_credits_remaining' => 200,
            ]);
            $subHoldingModels[$sh['slug']] = $model;
            $this->command->info("  ✅ Sub Holding: {$model->name}");
        }

        // ========== ANAK PERUSAHAAN ==========
        $subsidiaries = [
            // Under Patra Niaga
            ['name' => 'PT Pertamina Lubricants', 'slug' => 'pertamina-lubricants', 'industry' => 'Lubricants Manufacturing', 'parent' => 'pertamina-patra-niaga'],
            ['name' => 'PT Pertamina Retail', 'slug' => 'pertamina-retail', 'industry' => 'Retail & Distribution', 'parent' => 'pertamina-patra-niaga'],
            ['name' => 'PT Elnusa Petrofin', 'slug' => 'elnusa-petrofin', 'industry' => 'Fuel Distribution', 'parent' => 'pertamina-patra-niaga'],

            // Under Hulu Energi
            ['name' => 'PT Pertamina EP', 'slug' => 'pertamina-ep', 'industry' => 'Exploration & Production', 'parent' => 'pertamina-hulu-energi'],
            ['name' => 'PT Pertamina Drilling Services Indonesia', 'slug' => 'pertamina-drilling', 'industry' => 'Drilling Services', 'parent' => 'pertamina-hulu-energi'],

            // Under Kilang
            ['name' => 'PT Pertamina Trans Kontinental', 'slug' => 'pertamina-trans-kontinental', 'industry' => 'Shipping & Maritime', 'parent' => 'kilang-pertamina-intl'],
            ['name' => 'PT Pertamina Power Indonesia', 'slug' => 'pertamina-power', 'industry' => 'Power Generation', 'parent' => 'kilang-pertamina-intl'],
            ['name' => 'PT Pertamina International EP', 'slug' => 'pertamina-intl-ep', 'industry' => 'International E&P', 'parent' => 'kilang-pertamina-intl'],
        ];

        foreach ($subsidiaries as $sub) {
            $parent = $subHoldingModels[$sub['parent']] ?? null;
            $model = Organization::create([
                'name'      => $sub['name'],
                'slug'      => $sub['slug'],
                'industry'  => $sub['industry'],
                'org_level' => 'subsidiary',
                'parent_id' => $parent?->id,
                'email'     => 'privacy@' . $sub['slug'] . '.co.id',
                'onboarding_completed' => true,
                'ai_credits_monthly'   => 100,
                'ai_credits_remaining' => 100,
            ]);
            $this->command->info("    ✅ Subsidiary: {$model->name}");
        }

        $this->command->info('');
        $this->command->info('🏢 Pertamina Holding Structure seeded successfully!');
        $this->command->info("   1 Holding → 3 Sub Holdings → 8 Anak Perusahaan = 12 total orgs");
    }
}
