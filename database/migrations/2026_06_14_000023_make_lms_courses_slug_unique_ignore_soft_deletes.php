<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the (org_id, slug) uniqueness on lms_courses ignore soft-deleted rows.
 *
 * The original migration created a hard unique index that prevents recreating
 * a course with the same slug after it has been soft-deleted. The fix is a
 * partial unique index keyed on `WHERE deleted_at IS NULL`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            // Drop the existing hard unique constraint and replace with a
            // partial unique index that excludes soft-deleted rows.
            Schema::table('lms_courses', function (Blueprint $table) {
                $table->dropUnique(['org_id', 'slug']);
            });
            DB::statement('CREATE UNIQUE INDEX lms_courses_org_id_slug_unique ON lms_courses (org_id, slug) WHERE deleted_at IS NULL');
            return;
        }

        if ($driver === 'sqlite') {
            // SQLite supports partial indexes since 3.8.
            Schema::table('lms_courses', function (Blueprint $table) {
                $table->dropUnique(['org_id', 'slug']);
            });
            DB::statement('CREATE UNIQUE INDEX lms_courses_org_id_slug_unique ON lms_courses (org_id, slug) WHERE deleted_at IS NULL');
            return;
        }

        // MySQL and others without partial-index support: leave the existing
        // hard unique constraint. Application-layer validation already excludes
        // soft-deleted rows; recreate-after-delete will require a force-delete
        // or admin slug change on those drivers.
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS lms_courses_org_id_slug_unique');
            Schema::table('lms_courses', function (Blueprint $table) {
                $table->unique(['org_id', 'slug']);
            });
        }
    }
};
