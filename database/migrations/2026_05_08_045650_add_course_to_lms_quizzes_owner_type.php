<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE lms_quizzes DROP CONSTRAINT IF EXISTS lms_quizzes_owner_type_check');
            DB::statement("ALTER TABLE lms_quizzes ADD CONSTRAINT lms_quizzes_owner_type_check CHECK (owner_type IN ('module','feature_doc','course'))");
        } elseif ($driver === 'sqlite') {
            // SQLite enforces enum via CHECK on CREATE TABLE; rebuild to extend it.
            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement('ALTER TABLE lms_quizzes RENAME TO lms_quizzes_old');
            DB::statement('CREATE TABLE "lms_quizzes" ("id" integer primary key autoincrement not null, "owner_type" varchar check ("owner_type" in (\'module\', \'feature_doc\', \'course\')) not null, "owner_key" varchar not null, "passing_score" integer not null default (\'70\'), "time_limit_seconds" integer, "max_attempts" integer, "created_at" datetime, "updated_at" datetime)');
            DB::statement('INSERT INTO lms_quizzes SELECT * FROM lms_quizzes_old');
            DB::statement('DROP TABLE lms_quizzes_old');
            DB::statement('CREATE INDEX "lms_quizzes_owner_type_owner_key_index" ON "lms_quizzes" ("owner_type", "owner_key")');
            DB::statement('PRAGMA foreign_keys=ON');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE lms_quizzes DROP CONSTRAINT IF EXISTS lms_quizzes_owner_type_check');
            DB::statement("ALTER TABLE lms_quizzes ADD CONSTRAINT lms_quizzes_owner_type_check CHECK (owner_type IN ('module','feature_doc'))");
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');
            DB::statement('ALTER TABLE lms_quizzes RENAME TO lms_quizzes_old');
            DB::statement('CREATE TABLE "lms_quizzes" ("id" integer primary key autoincrement not null, "owner_type" varchar check ("owner_type" in (\'module\', \'feature_doc\')) not null, "owner_key" varchar not null, "passing_score" integer not null default (\'70\'), "time_limit_seconds" integer, "max_attempts" integer, "created_at" datetime, "updated_at" datetime)');
            DB::statement('INSERT INTO lms_quizzes SELECT * FROM lms_quizzes_old');
            DB::statement('DROP TABLE lms_quizzes_old');
            DB::statement('CREATE INDEX "lms_quizzes_owner_type_owner_key_index" ON "lms_quizzes" ("owner_type", "owner_key")');
            DB::statement('PRAGMA foreign_keys=ON');
        }
    }
};
