<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-collection / per-app locale (id|en) — supaya klien yang punya market
 * internasional bisa serve widget dalam bahasa berbeda per app/collection
 * tanpa edit data-locale attribute di script tag.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('consent_collection_points') && ! Schema::hasColumn('consent_collection_points', 'locale')) {
            try {
                Schema::table('consent_collection_points', function (Blueprint $t) {
                    $t->string('locale', 8)->default('id')->after('audience');
                });
            } catch (Throwable $e) {
                if (! str_contains($e->getMessage(), 'already') && ! str_contains($e->getMessage(), 'duplicate')) {
                    throw $e;
                }
            }
        }
        if (Schema::hasTable('dsr_apps') && ! Schema::hasColumn('dsr_apps', 'locale')) {
            try {
                Schema::table('dsr_apps', function (Blueprint $t) {
                    $t->string('locale', 8)->default('id')->after('captcha_secret');
                });
            } catch (Throwable $e) {
                if (! str_contains($e->getMessage(), 'already') && ! str_contains($e->getMessage(), 'duplicate')) {
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('consent_collection_points', 'locale')) {
            try {
                Schema::table('consent_collection_points', fn ($t) => $t->dropColumn('locale'));
            } catch (Throwable $e) {
            }
        }
        if (Schema::hasColumn('dsr_apps', 'locale')) {
            try {
                Schema::table('dsr_apps', fn ($t) => $t->dropColumn('locale'));
            } catch (Throwable $e) {
            }
        }
    }
};
