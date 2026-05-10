<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed default CORS allowlist ke section "security" di system_settings.
 *
 * Multi-deployment friendly: setiap install (SaaS Privasimu, on-prem Client A,
 * Client B, dst) baca origin production-nya dari env `CORS_ALLOWED_ORIGINS`
 * (comma-separated). Kalau env gak di-set, fallback ke localhost-only — itu
 * dev default, dan admin diharapkan tambah domain production via UI sebelum
 * traffic datang.
 *
 * Filosofi: TIDAK ada customer-specific domain di repo. On-prem clients
 * masing-masing punya .env sendiri dengan domain mereka — repo platform
 * tetap bersih dan portable.
 *
 *   .env contoh untuk SaaS Privasimu:
 *     CORS_ALLOWED_ORIGINS=https://nexus.privasimu.com,https://privasimu.sainskerta.net
 *
 *   .env contoh untuk on-prem Client A:
 *     CORS_ALLOWED_ORIGINS=https://privacy.client-a.example.com
 *
 * Setelah first boot, UI /platform-admin/system-settings → Security → CORS
 * jadi source of truth — admin bisa tambah/hapus tanpa redeploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        $envOrigins = $this->parseEnvOrigins();
        $defaults = [
            'security.cors_allowed_origins' => $envOrigins,
            'security.cors_allow_credentials' => false,
            'security.cors_max_age_seconds' => 3600,
        ];

        $now = now();
        foreach ($defaults as $key => $value) {
            $exists = DB::table('system_settings')->where('key', $key)->exists();
            if ($exists) continue;

            DB::table('system_settings')->insert([
                'key' => $key,
                'value' => json_encode($value),
                'is_encrypted' => false,
                'section' => 'security',
                'updated_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('key', [
            'security.cors_allowed_origins',
            'security.cors_allow_credentials',
            'security.cors_max_age_seconds',
        ])->delete();
    }

    /**
     * Parse env CORS_ALLOWED_ORIGINS (comma-separated). Trim, drop empty,
     * dedupe. Fallback ke localhost dev set kalau env kosong.
     *
     * @return list<string>
     */
    private function parseEnvOrigins(): array
    {
        $raw = (string) env('CORS_ALLOWED_ORIGINS', '');
        if ($raw === '') {
            return ['http://localhost:3000', 'http://127.0.0.1:3000'];
        }

        $parts = array_map('trim', explode(',', $raw));
        $parts = array_values(array_unique(array_filter($parts, fn ($s) => $s !== '')));

        // Tetap include localhost untuk dev convenience kecuali env eksplisit
        // matikan via "CORS_ALLOWED_ORIGINS_NO_LOCALHOST=true". Pentest /
        // production-strict bisa set itu untuk lock down total.
        if (! filter_var(env('CORS_ALLOWED_ORIGINS_NO_LOCALHOST', false), FILTER_VALIDATE_BOOLEAN)) {
            $parts = array_values(array_unique(array_merge($parts, [
                'http://localhost:3000',
                'http://127.0.0.1:3000',
            ])));
        }

        return $parts;
    }
};
