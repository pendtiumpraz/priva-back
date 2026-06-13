<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite stores FK references by the table name at the time the FK was created.
 * A prior migration renamed `lms_quizzes` via CREATE+DROP (SQLite column-alter workaround),
 * leaving `lms_quiz_questions.quiz_id` referencing the ghost name `lms_quizzes_old`.
 * This migration rebuilds the table with the correct FK target.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite-only ghost-FK rebuild. On PostgreSQL/MySQL the FK target was
        // never corrupted (no CREATE+DROP table-rename workaround was used), so
        // this is a no-op there.
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('PRAGMA foreign_keys = OFF');

        // 1. Create the corrected replacement table.
        DB::statement('
            CREATE TABLE "lms_quiz_questions_new" (
                "id"             integer primary key autoincrement not null,
                "quiz_id"        integer not null,
                "type"           varchar check ("type" in (\'mcq\', \'true_false\')) not null,
                "prompt"         text not null,
                "options"        text,
                "correct_answer" text not null,
                "points"         integer not null default \'1\',
                "order"          integer not null default \'0\',
                "created_at"     datetime,
                "updated_at"     datetime,
                foreign key("quiz_id") references "lms_quizzes"("id") on delete cascade
            )
        ');

        // 2. Copy existing rows (if any).
        DB::statement('INSERT INTO "lms_quiz_questions_new" SELECT * FROM "lms_quiz_questions"');

        // 3. Swap names.
        DB::statement('DROP TABLE "lms_quiz_questions"');
        DB::statement('ALTER TABLE "lms_quiz_questions_new" RENAME TO "lms_quiz_questions"');

        // 4. Restore the original index.
        DB::statement('CREATE INDEX "lms_quiz_questions_quiz_id_order_index" ON "lms_quiz_questions" ("quiz_id", "order")');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        // Reversing would re-introduce the broken FK — not worth implementing.
    }
};
