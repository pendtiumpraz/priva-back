<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cross-DB fix for lms_quizzes.owner_type.
 *
 * The create migration made owner_type an ENUM('module','feature_doc') and a
 * later migration (000019) added 'course' — but ONLY for pgsql + sqlite, never
 * MySQL. On MySQL prod the ENUM stayed without 'course', so seeding a course
 * quiz failed: "Data truncated for column 'owner_type'".
 *
 * Fix: relax owner_type to a free VARCHAR on the real DBs (pgsql + mysql), so it
 * accepts 'course' (and any future owner type) without more enum-surgery. The
 * value set is enforced at the application layer. SQLite (tests) already allows
 * 'course' via 000019, so it's left as-is.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE lms_quizzes MODIFY owner_type VARCHAR(32) NOT NULL');
        } elseif ($driver === 'pgsql') {
            // Drop the CHECK constraint → plain varchar, matching MySQL.
            DB::statement('ALTER TABLE lms_quizzes DROP CONSTRAINT IF EXISTS lms_quizzes_owner_type_check');
        }
        // sqlite: 000019 already rebuilt the CHECK to include 'course'; leave it.
    }

    public function down(): void
    {
        // Intentionally irreversible — re-narrowing could reject existing rows.
    }
};
