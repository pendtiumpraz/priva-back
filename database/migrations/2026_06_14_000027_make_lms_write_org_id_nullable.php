<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform roles (root / superadmin) have users.org_id = NULL by design — they
 * operate cross-tenant. The LMS learner-write tables originally declared org_id
 * NOT NULL, so every write performed by a platform user (quiz/exam attempt,
 * lesson-complete, watch progress, bookmark, note, plus the XP/badge/leaderboard
 * side-effects) hit `NOT NULL constraint failed: <table>.org_id` and returned 500.
 *
 * Reads already bypass org scoping for platform roles (MeController::isPlatformRole,
 * the controllers' whereNull('org_id') OR org_id=user clauses), so making org_id
 * nullable lets platform-role rows be written with a NULL org and read back the
 * same way. Tenant users are unaffected — they always carry a concrete org_id.
 */
return new class extends Migration
{
    private array $tables = [
        'lms_quiz_attempts',
        'lms_user_lesson_progress',
        'lms_user_module_progress',
        'lms_xp_log',
        'lms_user_badges',
        'lms_user_bookmarks',
        'lms_user_notes',
        'lms_org_leaderboard',
    ];

    public function up(): void
    {
        foreach ($this->tables as $t) {
            if (! Schema::hasColumn($t, 'org_id')) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->uuid('org_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Reverting requires no NULL org_id rows to exist; platform-role rows
        // written while nullable must be cleared first or this will fail.
        foreach ($this->tables as $t) {
            if (! Schema::hasColumn($t, 'org_id')) {
                continue;
            }
            Schema::table($t, function (Blueprint $table) {
                $table->uuid('org_id')->nullable(false)->change();
            });
        }
    }
};
