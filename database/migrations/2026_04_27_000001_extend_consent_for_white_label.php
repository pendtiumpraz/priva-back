<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend Consent Management for white-label deployment + dual auth + flexible display.
 *
 * consent_collection_points (collection point = "DSR App equivalent" untuk consent):
 *   - embed_token            : public widget identifier (separate dari collection_id)
 *   - client_key, server_key : B2B API auth (server-to-server capture)
 *   - auth_methods           : JSON {widget, api_key} toggleable
 *   - allowed_domains        : JSON, mirror DSR
 *   - display_mode           : banner_bottom | banner_top | modal_center | fullscreen | inline
 *   - display_frequency      : once | session | every_load
 *   - audience               : anonymous_only | logged_in_only | both
 *   - captcha_provider/site_key/secret
 *   - branding (already in settings JSON, but extract for clarity)
 *
 * consent_items:
 *   - category : essential | analytics | marketing | personalization | functional | third_party | other
 *                Used to filter what anonymous visitors see (cookie banner = essential + analytics + marketing only).
 *   - cookie_keys : JSON array of cookie names this item authorizes (e.g. ["_ga", "_fbp"])
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('consent_collection_points')) {
            $cols = [
                'embed_token'        => fn(Blueprint $t) => $t->string('embed_token', 64)->nullable()->after('collection_id'),
                'client_key'         => fn(Blueprint $t) => $t->string('client_key', 80)->nullable()->after('embed_token'),
                'server_key'         => fn(Blueprint $t) => $t->text('server_key')->nullable()->after('client_key'),
                'auth_methods'       => fn(Blueprint $t) => $t->json('auth_methods')->nullable()->after('server_key'),
                'allowed_domains'    => fn(Blueprint $t) => $t->json('allowed_domains')->nullable()->after('auth_methods'),
                'display_mode'       => fn(Blueprint $t) => $t->string('display_mode', 32)->default('banner_bottom')->after('allowed_domains'),
                'display_frequency'  => fn(Blueprint $t) => $t->string('display_frequency', 32)->default('once')->after('display_mode'),
                'audience'           => fn(Blueprint $t) => $t->string('audience', 32)->default('anonymous_only')->after('display_frequency'),
                'captcha_provider'   => fn(Blueprint $t) => $t->string('captcha_provider', 32)->nullable()->after('audience'),
                'captcha_site_key'   => fn(Blueprint $t) => $t->string('captcha_site_key', 200)->nullable()->after('captcha_provider'),
                'captcha_secret'     => fn(Blueprint $t) => $t->text('captcha_secret')->nullable()->after('captcha_site_key'),
                'api_keys_last_rotated_at' => fn(Blueprint $t) => $t->timestamp('api_keys_last_rotated_at')->nullable()->after('captcha_secret'),
            ];
            foreach ($cols as $name => $fn) {
                if (Schema::hasColumn('consent_collection_points', $name)) continue;
                try {
                    Schema::table('consent_collection_points', function (Blueprint $t) use ($fn) { $fn($t); });
                } catch (\Illuminate\Database\QueryException $e) {
                    $code = $e->errorInfo[1] ?? null;
                    if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) continue;
                    throw $e;
                }
            }

            try {
                Schema::table('consent_collection_points', function (Blueprint $t) {
                    $t->unique('client_key', 'consent_cp_client_key_unique');
                });
            } catch (\Throwable $e) {}
            try {
                Schema::table('consent_collection_points', function (Blueprint $t) {
                    $t->unique('embed_token', 'consent_cp_embed_token_unique');
                });
            } catch (\Throwable $e) {}
        }

        if (Schema::hasTable('consent_items')) {
            $cols = [
                'category'    => fn(Blueprint $t) => $t->string('category', 32)->default('essential')->after('specific_purpose'),
                'cookie_keys' => fn(Blueprint $t) => $t->json('cookie_keys')->nullable()->after('category'),
            ];
            foreach ($cols as $name => $fn) {
                if (Schema::hasColumn('consent_items', $name)) continue;
                try {
                    Schema::table('consent_items', function (Blueprint $t) use ($fn) { $fn($t); });
                } catch (\Illuminate\Database\QueryException $e) {
                    $code = $e->errorInfo[1] ?? null;
                    if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) continue;
                    throw $e;
                }
            }
            try {
                Schema::table('consent_items', function (Blueprint $t) {
                    $t->index(['collection_point_id', 'category'], 'consent_items_cp_category_idx');
                });
            } catch (\Throwable $e) {}
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('consent_collection_points')) {
            try { Schema::table('consent_collection_points', fn($t) => $t->dropUnique('consent_cp_client_key_unique')); } catch (\Throwable $e) {}
            try { Schema::table('consent_collection_points', fn($t) => $t->dropUnique('consent_cp_embed_token_unique')); } catch (\Throwable $e) {}
            foreach (['embed_token','client_key','server_key','auth_methods','allowed_domains','display_mode','display_frequency','audience','captcha_provider','captcha_site_key','captcha_secret','api_keys_last_rotated_at'] as $col) {
                if (Schema::hasColumn('consent_collection_points', $col)) {
                    try { Schema::table('consent_collection_points', fn($t) => $t->dropColumn($col)); } catch (\Throwable $e) {}
                }
            }
        }
        if (Schema::hasTable('consent_items')) {
            try { Schema::table('consent_items', fn($t) => $t->dropIndex('consent_items_cp_category_idx')); } catch (\Throwable $e) {}
            foreach (['category', 'cookie_keys'] as $col) {
                if (Schema::hasColumn('consent_items', $col)) {
                    try { Schema::table('consent_items', fn($t) => $t->dropColumn($col)); } catch (\Throwable $e) {}
                }
            }
        }
    }
};
