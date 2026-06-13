<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend lms_badges for the admin CRUD phase (Task 5.3-BE):
 *
 *  - add `org_id` (uuid, nullable, FK to organizations) — null means a global
 *    seeded badge; tenant admins may edit but not delete null-org badges.
 *  - add `softDeletes` (`deleted_at`) so destroy is reversible.
 *  - relax `criteria_type` from a hard ENUM constraint to a free-text string
 *    so the admin layer can introduce new types (lesson_complete, quiz_pass,
 *    quiz_perfect, course_complete, streak, xp_threshold) without requiring
 *    a fresh enum migration each time. Validation lives in
 *    StoreBadgeRequest / UpdateBadgeRequest + BadgeCriteriaJsonRule.
 *  - replace the global UNIQUE on `slug` with a partial UNIQUE on
 *    `(org_id, slug)` that ignores soft-deleted rows (mirrors the Course
 *    pattern from 2026_05_08_220500_make_lms_courses_slug_unique_ignore_soft_deletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Add org_id (nullable uuid FK), softDeletes, and an index on org_id.
        Schema::table('lms_badges', function (Blueprint $table) {
            $table->uuid('org_id')->nullable()->after('id');
            $table->softDeletes();
            $table->index('org_id', 'lms_badges_org_id_index');
        });

        if (in_array($driver, ['pgsql', 'mysql'], true)) {
            // Add the FK separately so we can keep this migration safe on
            // partial test schemas where the parent table might be absent.
            Schema::table('lms_badges', function (Blueprint $table) {
                $table->foreign('org_id', 'lms_badges_org_id_foreign')
                    ->references('id')->on('organizations')
                    ->cascadeOnDelete();
            });
        }

        // 2. Drop the global UNIQUE on slug. SQLite/MySQL/PG all auto-named
        //    the index `lms_badges_slug_unique`. Some test harnesses rebuild
        //    schema from scratch so wrap in a try/catch.
        try {
            Schema::table('lms_badges', function (Blueprint $table) {
                $table->dropUnique('lms_badges_slug_unique');
            });
        } catch (\Throwable $e) {
            // Index may not exist on rebuilt test schemas; ignore.
        }

        // 3. Replace the criteria_type ENUM with a plain VARCHAR. SQLite stores
        //    enums as TEXT with a CHECK constraint; rebuilding via doctrine is
        //    not available without doctrine/dbal, so we recreate the column.
        if ($driver === 'sqlite') {
            // SQLite: recreate the column as text. Preserve existing values.
            Schema::table('lms_badges', function (Blueprint $table) {
                $table->string('criteria_type_new')->nullable();
            });
            DB::statement('UPDATE lms_badges SET criteria_type_new = criteria_type');
            Schema::table('lms_badges', function (Blueprint $table) {
                $table->dropColumn('criteria_type');
            });
            Schema::table('lms_badges', function (Blueprint $table) {
                $table->renameColumn('criteria_type_new', 'criteria_type');
            });
        } elseif ($driver === 'pgsql') {
            // Postgres: drop the CHECK constraint that the enum() helper
            // creates as `<table>_<col>_check`, then alter type.
            DB::statement('ALTER TABLE lms_badges DROP CONSTRAINT IF EXISTS lms_badges_criteria_type_check');
            DB::statement('ALTER TABLE lms_badges ALTER COLUMN criteria_type TYPE VARCHAR(64)');
        } else {
            // MySQL: convert ENUM column to VARCHAR(64) directly.
            DB::statement("ALTER TABLE lms_badges MODIFY criteria_type VARCHAR(64) NOT NULL");
        }

        // 4. Composite partial unique on (org_id, slug), ignoring soft-deletes.
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX lms_badges_org_id_slug_unique ON lms_badges (org_id, slug) WHERE deleted_at IS NULL');
        } else {
            // MySQL: hard composite unique without partial-index support.
            Schema::table('lms_badges', function (Blueprint $table) {
                $table->unique(['org_id', 'slug'], 'lms_badges_org_id_slug_unique');
            });
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS lms_badges_org_id_slug_unique');
        } else {
            try {
                Schema::table('lms_badges', function (Blueprint $table) {
                    $table->dropUnique('lms_badges_org_id_slug_unique');
                });
            } catch (\Throwable $e) {
                // ignore
            }
        }

        // Restore global slug unique
        try {
            Schema::table('lms_badges', function (Blueprint $table) {
                $table->unique('slug', 'lms_badges_slug_unique');
            });
        } catch (\Throwable $e) {
            // ignore
        }

        if (in_array($driver, ['pgsql', 'mysql'], true)) {
            try {
                Schema::table('lms_badges', function (Blueprint $table) {
                    $table->dropForeign('lms_badges_org_id_foreign');
                });
            } catch (\Throwable $e) {
                // ignore
            }
        }

        Schema::table('lms_badges', function (Blueprint $table) {
            $table->dropIndex('lms_badges_org_id_index');
            $table->dropSoftDeletes();
            $table->dropColumn('org_id');
        });
    }
};
