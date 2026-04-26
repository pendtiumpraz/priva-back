<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — captcha config per DSR app.
 *
 * captcha_provider: null | "turnstile" | "hcaptcha" | "recaptcha_v3"
 * captcha_site_key: client-side key (rendered in widget)
 * captcha_secret:   server-side key (validated in submit endpoint)
 *
 * Secret stored as TEXT, klien isi via dashboard. Phase 6: encrypt at rest.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('dsr_apps')) return;

        $cols = [
            'captcha_provider' => fn(Blueprint $t) => $t->string('captcha_provider', 32)->nullable()->after('nda_signing_method'),
            'captcha_site_key' => fn(Blueprint $t) => $t->string('captcha_site_key', 200)->nullable()->after('captcha_provider'),
            'captcha_secret'   => fn(Blueprint $t) => $t->text('captcha_secret')->nullable()->after('captcha_site_key'),
        ];
        foreach ($cols as $name => $fn) {
            if (Schema::hasColumn('dsr_apps', $name)) continue;
            try {
                Schema::table('dsr_apps', function (Blueprint $t) use ($fn) { $fn($t); });
            } catch (\Illuminate\Database\QueryException $e) {
                $code = $e->errorInfo[1] ?? null;
                if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) continue;
                throw $e;
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('dsr_apps')) return;
        foreach (['captcha_provider', 'captcha_site_key', 'captcha_secret'] as $col) {
            if (Schema::hasColumn('dsr_apps', $col)) {
                try { Schema::table('dsr_apps', fn(Blueprint $t) => $t->dropColumn($col)); } catch (\Throwable $e) {}
            }
        }
    }
};
