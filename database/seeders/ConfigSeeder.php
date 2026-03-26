<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AppSetting;
use App\Models\License;
use App\Models\Organization;

class ConfigSeeder extends Seeder
{
    public function run(): void
    {
        // 1. API Key
        AppSetting::set('deepseek_api_key', 'sk-2c93b296892c4ec2b6c8a143dc403cec');
        $this->command->info('✅ DeepSeek API Key inserted.');

        // 2. License Key
        $org = Organization::firstOrCreate(
            ['slug' => 'pt-tester-indonesia'],
            [
                'name' => 'PT Tester Indonesia',
                'industry' => 'Technology',
                'email' => 'admin@tester.co.id',
                'phone' => '+62211234567',
                'address' => 'Jakarta'
            ]
        );

        License::updateOrCreate(
            ['license_key' => 'PRIV-VS44-CAXS-QZ67-K5K3'],
            [
                'package_type' => 'ai',
                'license_type' => 'perpetual',
                'status' => 'active',
                'features' => ['Semua fitur Basic', 'AI Assistant (ChatBot)', 'AI Risk Scoring', 'Live Visual Drill', 'AI-powered Gap Analysis', 'Auto-generate DPIA dari ROPA'],
                'org_id' => $org->id,
                'org_name' => $org->name,
                'activation_count' => 1,
                'max_activations' => 1,
                'activated_at' => now(),
            ]
        );
        $this->command->info('✅ License PRIV-VS44-CAXS-QZ67-K5K3 inserted.');
    }
}
