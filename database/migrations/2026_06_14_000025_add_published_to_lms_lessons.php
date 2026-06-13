<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `published` column to lms_lessons so lesson status (draft|published)
 * can be persisted independently of the parent module/course.
 *
 * Mirrors the 2026_05_08_221500_add_published_to_lms_modules migration.
 *
 * Defaults to true so existing seeded lessons remain visible after migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_lessons', function (Blueprint $table) {
            $table->boolean('published')->default(true)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('lms_lessons', function (Blueprint $table) {
            $table->dropColumn('published');
        });
    }
};
