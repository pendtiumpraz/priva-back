<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a human-readable title to lms_quizzes.
     * Used by feature_doc quizzes (Phase 6) so the drawer heading is meaningful.
     */
    public function up(): void
    {
        Schema::table('lms_quizzes', function (Blueprint $table) {
            $table->string('title')->nullable()->after('owner_key');
        });
    }

    public function down(): void
    {
        Schema::table('lms_quizzes', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
