<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSR App API key auth — second method beside embed widget.
 *
 *   client_key  : public identifier, prefix "pk_live_" — sent as X-Privasimu-Client-Key header
 *   server_key  : secret signing key, prefix "sk_live_" — used by klien backend to HMAC sign body
 *                 stored encrypted at rest; revealed plaintext ONCE on regenerate
 *   auth_methods: JSON {widget: bool, api_key: bool} — both can coexist; toggle independently
 *
 * Backwards compat: existing apps default to {widget: true, api_key: false}.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('dsr_apps')) return;

        $cols = [
            'client_key'      => fn(Blueprint $t) => $t->string('client_key', 80)->nullable()->after('embed_token'),
            'server_key'      => fn(Blueprint $t) => $t->text('server_key')->nullable()->after('client_key'),
            'auth_methods'    => fn(Blueprint $t) => $t->json('auth_methods')->nullable()->after('server_key'),
            'api_keys_last_rotated_at' => fn(Blueprint $t) => $t->timestamp('api_keys_last_rotated_at')->nullable()->after('auth_methods'),
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

        // Unique index on client_key (lookup hot path)
        try {
            Schema::table('dsr_apps', function (Blueprint $t) {
                $t->unique('client_key', 'dsr_apps_client_key_unique');
            });
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        if (!Schema::hasTable('dsr_apps')) return;
        try { Schema::table('dsr_apps', fn(Blueprint $t) => $t->dropUnique('dsr_apps_client_key_unique')); } catch (\Throwable $e) {}
        foreach (['client_key', 'server_key', 'auth_methods', 'api_keys_last_rotated_at'] as $col) {
            if (Schema::hasColumn('dsr_apps', $col)) {
                try { Schema::table('dsr_apps', fn(Blueprint $t) => $t->dropColumn($col)); } catch (\Throwable $e) {}
            }
        }
    }
};
