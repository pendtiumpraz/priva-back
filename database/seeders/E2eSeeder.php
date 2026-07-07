<?php

namespace Database\Seeders;

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

        $this->command->info('✅ E2E user: e2e.maker@privasimu.test / E2ePass123! (org e2e-org)');
    }
}
