<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The add_course_to_lms_quizzes_owner_type migration rebuilds lms_quizzes via
 * RENAME TO lms_quizzes_old (SQLite workaround). This leaves the FK in
 * lms_quiz_attempts.quiz_id referencing the ghost name lms_quizzes_old.
 * Rebuild with the correct FK target.
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver !== 'sqlite') {
            return; // pgsql FK references live name — no rebuild needed
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement('
            CREATE TABLE "lms_quiz_attempts_new" (
                "id"             integer primary key autoincrement not null,
                "user_id"        varchar not null,
                "org_id"         varchar not null,
                "quiz_id"        integer not null,
                "score"          integer,
                "passed"         tinyint(1) not null default \'0\',
                "attempt_number" integer not null default \'1\',
                "started_at"     datetime,
                "submitted_at"   datetime,
                "answers"        text,
                "created_at"     datetime,
                "updated_at"     datetime,
                foreign key("user_id") references "users"("id") on delete cascade,
                foreign key("org_id") references "organizations"("id") on delete cascade,
                foreign key("quiz_id") references "lms_quizzes"("id") on delete cascade
            )
        ');

        DB::statement('INSERT INTO "lms_quiz_attempts_new" SELECT * FROM "lms_quiz_attempts"');
        DB::statement('DROP TABLE "lms_quiz_attempts"');
        DB::statement('ALTER TABLE "lms_quiz_attempts_new" RENAME TO "lms_quiz_attempts"');
        DB::statement('CREATE INDEX "lms_quiz_attempts_org_id_user_id_quiz_id_index" ON "lms_quiz_attempts" ("org_id", "user_id", "quiz_id")');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        // Not reversible — would re-introduce broken FK.
    }
};
