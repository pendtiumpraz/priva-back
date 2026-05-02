<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * system_settings — singleton config global (INFRASTRUCTURE_PLAN.md §2.1).
 *
 * Stores platform-wide configuration that solves the chicken-egg problem of
 * needing Redis/queue/cache config without baking it into .env. Read by
 * SettingsServiceProvider on boot, cached to bootstrap/cache/system_settings.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();      // namespaced: "redis.host", "ai.api_key", ...
            $t->json('value')->nullable();         // value (string, int, bool, object) — nullable so empty defaults are explicit
            $t->boolean('is_encrypted')->default(false);
            $t->string('section', 32)->index();    // grouping: infrastructure, redis, ai, mail, aws, deployment
            $t->uuid('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
