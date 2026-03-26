<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // =============================================
        // Organization
        // =============================================
        $org = Organization::create([
            'name' => 'PT Tester Indonesia',
            'slug' => 'pt-tester-indonesia',
            'industry' => 'Technology',
            'email' => 'admin@tester.co.id',
            'phone' => '+62211234567',
            'website' => 'https://tester.co.id',
            'address' => 'Jl. Sudirman No. 1, Jakarta Pusat',
        ]);

        // =============================================
        // Users — PT Tester Indonesia (all 5 roles)
        // =============================================
        $superadmin = User::create([
            'name' => 'System SuperAdmin',
            'email' => 'superadmin@privasimu.com',
            'password' => 'SuperAdmin@2026',
            'org_id' => $org->id,
            'role' => 'superadmin',
            'position' => 'System Administrator',
            'is_active' => true,
        ]);

        $admin = User::create([
            'name' => 'Galih Admin',
            'email' => 'pendtiumpraz@gmail.com',
            'password' => '#F1r3n1c3142337',
            'org_id' => $org->id,
            'role' => 'admin',
            'position' => 'IT Manager',
            'is_active' => true,
        ]);

        $dpo = User::create([
            'name' => 'Budi DPO',
            'email' => 'budi.dpo@tester.co.id',
            'password' => 'password123',
            'org_id' => $org->id,
            'role' => 'dpo',
            'position' => 'Data Protection Officer',
            'is_active' => true,
        ]);

        $maker = User::create([
            'name' => 'Andi Maker',
            'email' => 'andi.maker@tester.co.id',
            'password' => 'password123',
            'org_id' => $org->id,
            'role' => 'maker',
            'position' => 'CISO',
            'is_active' => true,
        ]);

        $viewer = User::create([
            'name' => 'Sari Viewer',
            'email' => 'sari.viewer@tester.co.id',
            'password' => 'password123',
            'org_id' => $org->id,
            'role' => 'viewer',
            'position' => 'Compliance Auditor',
            'is_active' => true,
        ]);

        // =============================================
        // Organization 2 — PT Sainskerta Solusi Nusantara
        // =============================================
        $org2 = Organization::create([
            'name' => 'PT Sainskerta Solusi Nusantara',
            'slug' => 'pt-sainskerta-solusi-nusantara',
            'industry' => 'Financial Services',
            'email' => 'admin@sainskerta.co.id',
            'phone' => '+62215551234',
            'website' => 'https://sainskerta.co.id',
            'address' => 'Jl. Gatot Subroto Kav. 42, Jakarta Selatan',
        ]);

        // Users — PT Sainskerta (all 5 roles)
        $ssn_superadmin = User::create([
            'name' => 'Raka SuperAdmin',
            'email' => 'raka.superadmin@sainskerta.co.id',
            'password' => 'Sainskerta@2026',
            'org_id' => $org2->id,
            'role' => 'superadmin',
            'position' => 'CTO',
            'is_active' => true,
        ]);

        $ssn_admin = User::create([
            'name' => 'Dewi Admin',
            'email' => 'dewi.admin@sainskerta.co.id',
            'password' => 'password123',
            'org_id' => $org2->id,
            'role' => 'admin',
            'position' => 'IT Manager',
            'is_active' => true,
        ]);

        $ssn_dpo = User::create([
            'name' => 'Faris DPO',
            'email' => 'faris.dpo@sainskerta.co.id',
            'password' => 'password123',
            'org_id' => $org2->id,
            'role' => 'dpo',
            'position' => 'Data Protection Officer',
            'is_active' => true,
        ]);

        $ssn_maker = User::create([
            'name' => 'Nadia Maker',
            'email' => 'nadia.maker@sainskerta.co.id',
            'password' => 'password123',
            'org_id' => $org2->id,
            'role' => 'maker',
            'position' => 'Risk & Compliance Manager',
            'is_active' => true,
        ]);

        $ssn_viewer = User::create([
            'name' => 'Hendra Viewer',
            'email' => 'hendra.viewer@sainskerta.co.id',
            'password' => 'password123',
            'org_id' => $org2->id,
            'role' => 'viewer',
            'position' => 'Internal Auditor',
            'is_active' => true,
        ]);

        // =============================================
        // Gap Assessments
        // =============================================
        DB::table('gap_assessments')->insert([
            [
                'id' => Str::uuid(),
                'org_id' => $org->id,
                'version' => 'GAP_v3.0_#1',
                'overall_score' => 36.00,
                'progress' => 100.00,
                'compliance_level' => 'low',
                'created_by' => $admin->id,
                'created_at' => now()->subMonths(9),
                'updated_at' => now()->subMonths(9),
            ],
            [
                'id' => Str::uuid(),
                'org_id' => $org->id,
                'version' => 'GAP_v3.0_#2',
                'overall_score' => 27.00,
                'progress' => 41.00,
                'compliance_level' => 'low',
                'created_by' => $admin->id,
                'created_at' => now()->subMonth(),
                'updated_at' => now()->subMonth(),
            ],
        ]);

        // =============================================
        // ROPAs
        // =============================================
        $ropa1Id = Str::uuid();
        DB::table('ropas')->insert([
            [
                'id' => $ropa1Id,
                'org_id' => $org->id,
                'registration_number' => 'ROPA-001',
                'processing_activity' => 'Rekrutmen Kandidat Karyawan',
                'division' => 'Human Capital',
                'assign_group' => 'DPO Agent',
                'risk_level' => 'high',
                'status' => 'in_progress',
                'purpose' => 'Pemrosesan data pribadi calon karyawan untuk proses rekrutmen',
                'legal_basis' => 'Persetujuan subjek data',
                'retention_period' => '5 tahun',
                'created_by' => $admin->id,
                'created_at' => now()->subMonth(),
                'updated_at' => now()->subMonth(),
            ],
            [
                'id' => Str::uuid(),
                'org_id' => $org->id,
                'registration_number' => 'ROPA-002',
                'processing_activity' => 'Proses Penggajian dan Benefit',
                'division' => 'Human Capital',
                'assign_group' => 'DPO Agent',
                'risk_level' => 'medium',
                'status' => 'approved',
                'purpose' => 'Pemrosesan data karyawan untuk penggajian',
                'legal_basis' => 'Pemenuhan kontrak kerja',
                'retention_period' => '10 tahun',
                'created_by' => $admin->id,
                'created_at' => now()->subMonths(2),
                'updated_at' => now()->subMonth(),
            ],
        ]);

        // =============================================
        // DPIAs
        // =============================================
        DB::table('dpias')->insert([
            'id' => Str::uuid(),
            'org_id' => $org->id,
            'registration_number' => 'DPIA-001',
            'ropa_id' => $ropa1Id,
            'risk_level' => 'low',
            'status' => 'in_progress',
            'description' => 'DPIA untuk aktivitas rekrutmen karyawan',
            'created_by' => $admin->id,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        // =============================================
        // DSR Settings
        // =============================================
        DB::table('dsr_settings')->insert([
            'id' => Str::uuid(),
            'org_id' => $org->id,
            'mailer' => 'smtp',
            'sender_name' => $org->name,
            'otp_method' => 'email',
            'embed_token' => Str::random(32),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // =============================================
        // Consent Collection Point
        // =============================================
        $cpId = Str::uuid();
        DB::table('consent_collection_points')->insert([
            'id' => $cpId,
            'org_id' => $org->id,
            'collection_id' => '79189817',
            'name' => 'Aplikasi Toko Online',
            'domain' => 'tokonline.com',
            'redirect_url' => 'tokonline.com/finish',
            'created_by' => $admin->id,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        DB::table('consent_items')->insert([
            'id' => Str::uuid(),
            'collection_point_id' => $cpId,
            'title' => 'Persetujuan Pembayaran Pihak Ketiga',
            'description' => 'Persetujuan untuk memproses data pembayaran melalui pihak ketiga',
            'version' => '1.0',
            'is_required' => true,
            'is_active' => true,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        // =============================================
        // PT Sainskerta — ROPAs
        // =============================================
        $ssnRopa1Id = Str::uuid();
        DB::table('ropas')->insert([
            [
                'id' => $ssnRopa1Id,
                'org_id' => $org2->id,
                'registration_number' => 'ROPA-SSN-001',
                'processing_activity' => 'Verifikasi Identitas Nasabah (e-KYC)',
                'division' => 'Onboarding',
                'assign_group' => 'DPO Agent',
                'risk_level' => 'high',
                'status' => 'in_progress',
                'purpose' => 'Pemrosesan data identitas nasabah untuk verifikasi KYC',
                'legal_basis' => 'Kewajiban hukum (UU PDP & OJK)',
                'retention_period' => '10 tahun',
                'created_by' => $ssn_admin->id,
                'created_at' => now()->subMonths(2),
                'updated_at' => now()->subMonth(),
            ],
            [
                'id' => Str::uuid(),
                'org_id' => $org2->id,
                'registration_number' => 'ROPA-SSN-002',
                'processing_activity' => 'Pemrosesan Transaksi Pembayaran',
                'division' => 'Payment Processing',
                'assign_group' => 'DPO Agent',
                'risk_level' => 'medium',
                'status' => 'approved',
                'purpose' => 'Pemrosesan data transaksi nasabah',
                'legal_basis' => 'Pemenuhan kontrak kerja & persetujuan subjek data',
                'retention_period' => '7 tahun',
                'created_by' => $ssn_admin->id,
                'created_at' => now()->subMonths(3),
                'updated_at' => now()->subMonths(2),
            ],
        ]);

        // PT Sainskerta — DPIA
        DB::table('dpias')->insert([
            'id' => Str::uuid(),
            'org_id' => $org2->id,
            'registration_number' => 'DPIA-SSN-001',
            'ropa_id' => $ssnRopa1Id,
            'risk_level' => 'high',
            'status' => 'in_progress',
            'description' => 'DPIA untuk aktivitas verifikasi e-KYC nasabah',
            'created_by' => $ssn_admin->id,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        // PT Sainskerta — DSR Settings
        DB::table('dsr_settings')->insert([
            'id' => Str::uuid(),
            'org_id' => $org2->id,
            'mailer' => 'smtp',
            'sender_name' => $org2->name,
            'otp_method' => 'email',
            'embed_token' => Str::random(32),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // PT Sainskerta — Consent
        $ssn_cpId = Str::uuid();
        DB::table('consent_collection_points')->insert([
            'id' => $ssn_cpId,
            'org_id' => $org2->id,
            'collection_id' => 'SSN-20260101',
            'name' => 'Aplikasi Mobile Banking',
            'domain' => 'mbanking.sainskerta.co.id',
            'redirect_url' => 'mbanking.sainskerta.co.id/finish',
            'created_by' => $ssn_admin->id,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        DB::table('consent_items')->insert([
            'id' => Str::uuid(),
            'collection_point_id' => $ssn_cpId,
            'title' => 'Persetujuan Pemrosesan Data Finansial',
            'description' => 'Persetujuan untuk memproses data transaksi dan rekening nasabah',
            'version' => '1.0',
            'is_required' => true,
            'is_active' => true,
            'created_at' => now()->subMonth(),
            'updated_at' => now()->subMonth(),
        ]);

        $this->command->info('🎉 PRIVASIMU seed data created successfully!');
        $this->command->info('📋 Tenant 1: PT Tester Indonesia — 5 users (superadmin, admin, dpo, maker, viewer)');
        $this->command->info('📋 Tenant 2: PT Sainskerta Solusi Nusantara — 5 users (superadmin, admin, dpo, maker, viewer)');
    }
}
