<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `published` column to lms_modules so module status (draft|published)
 * can be persisted independently of the parent course's status.
 *
 * Defaults to true so existing seeded modules remain visible after migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lms_modules', function (Blueprint $table) {
            $table->boolean('published')->default(true)->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('lms_modules', function (Blueprint $table) {
            $table->dropColumn('published');
        });
    }
};
