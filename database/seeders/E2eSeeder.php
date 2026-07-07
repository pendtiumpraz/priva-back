<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder minimal untuk e2e test (Playwright). Membuat 1 organisasi + 1 user
 * role `maker` (non-privileged → TIDAK kena enforce 2FA, jadi login langsung
 * mengeluarkan token) supaya alur login end-to-end bisa diuji tanpa 2FA.
 *
 * WAJIB dijalankan pada database TERISOLASI (mis. database/e2e.sqlite via env
 * DB_DATABASE) — bukan database dev. Idempotent.
 *
 *   Kredensial e2e: e2e.maker@privasimu.test / E2ePass123!
 */
class E2eSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::firstOrCreate(
            ['slug' => 'e2e-org'],
            [
                'name' => 'E2E Test Org',
                'industry' => 'Technology',
                'email' => 'e2e@privasimu.test',
                'onboarding_completed' => true,
            ],
        );

        User::updateOrCreate(
            ['email' => 'e2e.maker@privasimu.test'],
            [
                'name' => 'E2E Maker',
                'password' => 'E2ePass123!', // di-hash otomatis (cast 'hashed')
                'org_id' => $org->id,
                'role' => 'maker',
                'position' => 'QA Automation',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // Lisensi ai_agent (paket penuh) supaya org tidak ke-gate ke /license.
        // JWT ditandatangani oleh global-setup e2e; LICENSE_PUBLIC_KEY di-set env
        // agar verifikasi lolos. Tanpa env ini (mis. seed manual), dilewati.
        $jwt = env('E2E_LICENSE_JWT');
        if ($jwt) {
            License::updateOrCreate(
                ['license_key' => 'E2E-LICENSE', 'org_id' => $org->id],
                [
                    'package_type' => 'ai_agent',
                    'license_type' => 'perpetual',
                    'status' => 'active',
                    'org_name' => $org->name,
                    'signed_payload' => $jwt,
                    'activated_at' => now(),
                    'activation_count' => 1,
                    'max_activations' => 1,
                ],
            );
            $this->command->info('✅ E2E license: ai_agent (perpetual) untuk org e2e-org');
        }

        // Divisi (dibutuhkan wizard ROPA: pilih divisi + penanggung jawab).
        foreach (['HR', 'IT'] as $i => $name) {
            Department::firstOrCreate(
                ['org_id' => $org->id, 'name' => $name],
                ['code' => strtoupper($name), 'is_active' => true],
            );
        }

        $this->command->info('✅ E2E user: e2e.maker@privasimu.test / E2ePass123! (org e2e-org, divisi HR/IT)');
    }
}
