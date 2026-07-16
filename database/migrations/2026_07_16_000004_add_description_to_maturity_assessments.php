<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maturity Assessment: free-text description per assessment.
 * Plain nullable text stored alongside `title` — lets the operator
 * record scope/context notes for the whole assessment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maturity_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('maturity_assessments', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('maturity_assessments', function (Blueprint $table) {
            if (Schema::hasColumn('maturity_assessments', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
