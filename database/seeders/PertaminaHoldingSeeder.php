<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Models\GapAssessment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class PertaminaHoldingSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding Pertamina Holding Structure + Users + Demo Data...');

        // ========== HOLDING ==========
        $holding = Organization::updateOrCreate(
            ['slug' => 'pt-pertamina-persero'],
            [
                'name'      => 'PT Pertamina (Persero)',
                'industry'  => 'Oil & Gas',
                'org_level' => 'holding',
                'parent_id' => null,
                'email'     => 'privacy@pertamina.com',
                'website'   => 'https://www.pertamina.com',
                'address'   => 'Jl. Medan Merdeka Timur 1A, Jakarta Pusat 10110',
                'onboarding_completed' => true,
                'ai_credits_monthly'   => 500,
                'ai_credits_remaining' => 500,
            ]
        );
        $this->createAdminUser($holding, 'DPO Holding Pertamina', 'dpo@pertamina.com');
        $this->command->info("  ✅ Holding: {$holding->name}");

        // ========== SUB HOLDING ==========
        $subHoldings = [
            [
                'name'     => 'PT Pertamina Patra Niaga (Sub Holding Commercial & Trading)',
                'slug'     => 'pertamina-patra-niaga',
                'industry' => 'Commercial & Trading',
                'email'    => 'dpo@patraniaga.pertamina.com',
                'user'     => 'DPO Patra Niaga',
            ],
            [
                'name'     => 'PT Pertamina Hulu Energi (Sub Holding Upstream)',
                'slug'     => 'pertamina-hulu-energi',
                'industry' => 'Oil & Gas Upstream',
                'email'    => 'dpo@phe.pertamina.com',
                'user'     => 'DPO Hulu Energi',
            ],
            [
                'name'     => 'PT Kilang Pertamina Internasional (Sub Holding Refining & Petrochemical)',
                'slug'     => 'kilang-pertamina-intl',
                'industry' => 'Refining & Petrochemical',
                'email'    => 'dpo@kpi.pertamina.com',
                'user'     => 'DPO Kilang Pertamina',
            ],
        ];

        $subHoldingModels = [];
        foreach ($subHoldings as $sh) {
            $model = Organization::updateOrCreate(
                ['slug' => $sh['slug']],
                [
                    'name'      => $sh['name'],
                    'industry'  => $sh['industry'],
                    'org_level' => 'sub_holding',
                    'parent_id' => $holding->id,
                    'email'     => $sh['email'],
                    'onboarding_completed' => true,
                    'ai_credits_monthly'   => 200,
                    'ai_credits_remaining' => 200,
                ]
            );
            $this->createAdminUser($model, $sh['user'], $sh['email']);
            $subHoldingModels[$sh['slug']] = $model;
            $this->command->info("  ✅ Sub Holding: {$model->name}");
        }

        // ========== ANAK PERUSAHAAN ==========
        $subsidiaries = [
            ['name' => 'PT Pertamina Lubricants', 'slug' => 'pertamina-lubricants', 'industry' => 'Lubricants Manufacturing', 'parent' => 'pertamina-patra-niaga'],
            ['name' => 'PT Pertamina Retail', 'slug' => 'pertamina-retail', 'industry' => 'Retail & Distribution', 'parent' => 'pertamina-patra-niaga'],
            ['name' => 'PT Elnusa Petrofin', 'slug' => 'elnusa-petrofin', 'industry' => 'Fuel Distribution', 'parent' => 'pertamina-patra-niaga'],
            ['name' => 'PT Pertamina EP', 'slug' => 'pertamina-ep', 'industry' => 'Exploration & Production', 'parent' => 'pertamina-hulu-energi'],
            ['name' => 'PT Pertamina Drilling Services Indonesia', 'slug' => 'pertamina-drilling', 'industry' => 'Drilling Services', 'parent' => 'pertamina-hulu-energi'],
            ['name' => 'PT Pertamina Trans Kontinental', 'slug' => 'pertamina-trans-kontinental', 'industry' => 'Shipping & Maritime', 'parent' => 'kilang-pertamina-intl'],
            ['name' => 'PT Pertamina Power Indonesia', 'slug' => 'pertamina-power', 'industry' => 'Power Generation', 'parent' => 'kilang-pertamina-intl'],
            ['name' => 'PT Pertamina International EP', 'slug' => 'pertamina-intl-ep', 'industry' => 'International E&P', 'parent' => 'kilang-pertamina-intl'],
        ];

        $allOrgs = [$holding];
        foreach ($subsidiaries as $sub) {
            $parent = $subHoldingModels[$sub['parent']] ?? null;
            $model = Organization::updateOrCreate(
                ['slug' => $sub['slug']],
                [
                    'name'      => $sub['name'],
                    'industry'  => $sub['industry'],
                    'org_level' => 'subsidiary',
                    'parent_id' => $parent?->id,
                    'email'     => 'privacy@' . $sub['slug'] . '.co.id',
                    'onboarding_completed' => true,
                    'ai_credits_monthly'   => 100,
                    'ai_credits_remaining' => 100,
                ]
            );
            $emailSlug = str_replace('-', '', Str::limit($sub['slug'], 15, ''));
            $this->createAdminUser($model, 'Admin ' . $sub['name'], "admin@{$sub['slug']}.co.id");
            $allOrgs[] = $model;
            $this->command->info("    ✅ Subsidiary: {$model->name}");
        }

        // ========== SEED DEMO DATA PER ORG ==========
        $this->command->info('');
        $this->command->info('📊 Seeding demo assessment data...');
        
        foreach ($allOrgs as $org) {
            $this->seedGapAssessment($org);
            $this->seedVendors($org);
            $this->seedRopa($org);
            $this->seedDpia($org);
        }

        $this->command->info('');
        $this->command->info('🏢 Pertamina Holding Structure seeded successfully!');
        $this->command->info("   1 Holding → 3 Sub Holdings → 8 Anak Perusahaan = 12 total orgs");
        $this->command->info("   + Admin users per org + GAP assessments + Vendors + ROPA + DPIA");
    }

    private function createAdminUser(Organization $org, string $name, string $email): void
    {
        User::updateOrCreate(
            ['email' => $email],
            [
                'name'      => $name,
                'password'  => bcrypt('Pertamina2026!'),
                'org_id'    => $org->id,
                'role'      => 'admin',
                'is_active' => true,
                'position'  => 'Data Protection Officer',
            ]
        );
    }

    private function seedGapAssessment(Organization $org): void
    {
        // Skip if already has assessments
        if (GapAssessment::where('org_id', $org->id)->exists()) return;

        $questions = GapAssessment::getQuestionBank('uupdp');
        $answers = [];
        $answerOptions = ['yes', 'yes', 'yes', 'partial', 'partial', 'no', 'yes', 'yes', 'partial', 'yes'];
        
        foreach ($questions as $idx => $q) {
            $answers[$q['id']] = $answerOptions[$idx % count($answerOptions)];
        }

        $calc = GapAssessment::calculateScore($answers, 'uupdp');

        GapAssessment::create([
            'org_id'           => $org->id,
            'regulation_code'  => 'uupdp',
            'version'          => 'GAP_v3.0_UUPDP_#1',
            'overall_score'    => $calc['overall_score'],
            'compliance_level' => $calc['compliance_level'],
            'progress'         => 100,
            'answers'          => $answers,
            'recommendations'  => $calc['recommendations'],
        ]);
    }

    private function seedVendors(Organization $org): void
    {
        if (\App\Models\Vendor::where('org_id', $org->id)->exists()) return;

        $vendors = [
            ['name' => 'Amazon Web Services (AWS)', 'services' => ['Cloud Hosting', 'Data Storage', 'Compute'], 'risk' => 'medium', 'score' => 72, 'dpa' => 'signed'],
            ['name' => 'Google Cloud Platform', 'services' => ['Cloud Infrastructure', 'AI/ML', 'BigQuery'], 'risk' => 'low', 'score' => 85, 'dpa' => 'signed'],
            ['name' => 'PT Telkom Indonesia', 'services' => ['Network', 'Colocation', 'ISP'], 'risk' => 'low', 'score' => 78, 'dpa' => 'signed'],
            ['name' => 'Salesforce', 'services' => ['CRM', 'Marketing Cloud'], 'risk' => 'medium', 'score' => 68, 'dpa' => 'draft'],
            ['name' => 'SAP Indonesia', 'services' => ['ERP', 'HRIS', 'Finance'], 'risk' => 'high', 'score' => 55, 'dpa' => 'expired'],
        ];

        foreach ($vendors as $v) {
            \App\Models\Vendor::create([
                'org_id'             => $org->id,
                'name'               => $v['name'],
                'services_provided'  => $v['services'],
                'risk_level'         => $v['risk'],
                'risk_score'         => $v['score'],
                'dpa_status'         => $v['dpa'],
                'last_assessed_at'   => now()->subDays(rand(5, 90)),
            ]);
        }
    }

    private function seedRopa(Organization $org): void
    {
        $table = 'ropas';
        if (\Illuminate\Support\Facades\DB::table($table)->where('org_id', $org->id)->exists()) return;

        $ropas = [
            ['activity' => 'Rekrutmen Kandidat Karyawan', 'purpose' => 'Pengumpulan dan evaluasi data pribadi calon karyawan', 'risk_level' => 'medium', 'division' => 'HRD'],
            ['activity' => 'Pengelolaan Data Pelanggan SPBU', 'purpose' => 'Program loyalitas dan transaksi pelanggan', 'risk_level' => 'high', 'division' => 'Commercial'],
            ['activity' => 'Pengelolaan Data Vendor', 'purpose' => 'Due diligence dan monitoring vendor pihak ketiga', 'risk_level' => 'low', 'division' => 'Procurement'],
        ];

        foreach ($ropas as $idx => $r) {
            try {
            \Illuminate\Support\Facades\DB::table($table)->insert([
                'id'                    => Str::uuid()->toString(),
                'org_id'                => $org->id,
                'registration_number'   => 'ROPA-' . strtoupper(substr(Str::slug($org->name), 0, 12)) . '-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'processing_activity'   => $r['activity'],
                'division'              => $r['division'],
                'purpose'               => $r['purpose'],
                'risk_level'            => $r['risk_level'],
                'status'                => 'active',
                'description'           => $r['purpose'],
                'kategori_pemrosesan'   => 'Pengendali Data Pribadi',
                'regulation_code'       => 'uupdp',
                'progress'              => rand(60, 100),
                'created_at'            => now()->subDays(rand(10, 60)),
                'updated_at'            => now(),
            ]);
            } catch (\Exception $e) { /* skip duplicate */ }
        }
    }

    private function seedDpia(Organization $org): void
    {
        $table = 'dpias';
        if (\Illuminate\Support\Facades\DB::table($table)->where('org_id', $org->id)->exists()) return;

        $dpias = [
            ['desc' => 'Assessment risiko pemrosesan data pelanggan melalui aplikasi mobile MyPertamina', 'risk_level' => 'high'],
            ['desc' => 'Assessment risiko sistem HRIS yang memproses data karyawan termasuk biometrik', 'risk_level' => 'medium'],
        ];

        foreach ($dpias as $idx => $d) {
            try {
            \Illuminate\Support\Facades\DB::table($table)->insert([
                'id'                  => Str::uuid()->toString(),
                'org_id'              => $org->id,
                'registration_number' => 'DPIA-' . strtoupper(substr(Str::slug($org->name), 0, 12)) . '-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'description'         => $d['desc'],
                'risk_level'          => $d['risk_level'],
                'status'              => 'active',
                'regulation_code'     => 'uupdp',
                'progress'            => rand(40, 100),
                'created_at'          => now()->subDays(rand(5, 30)),
                'updated_at'          => now(),
            ]);
            } catch (\Exception $e) { /* skip duplicate */ }
        }
    }
}
