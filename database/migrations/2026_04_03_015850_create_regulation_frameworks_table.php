<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_frameworks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique(); // 'pdpa', 'gdpr', 'uupdp'
            $table->string('name');
            $table->string('country');
            $table->json('articles')->nullable(); // detailed questions bank
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        
        // Add foreign key or reference to gap_assessments table
        Schema::table('gap_assessments', function (Blueprint $table) {
            $table->string('regulation_code')->nullable()->after('org_id');
        });
    }

    public function down(): void
    {
        Schema::table('gap_assessments', function (Blueprint $table) {
            $table->dropColumn('regulation_code');
        });
        Schema::dropIfExists('regulation_frameworks');
    }
};
