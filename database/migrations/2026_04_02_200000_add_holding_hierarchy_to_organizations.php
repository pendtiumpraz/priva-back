<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->after('id');
            $table->string('org_level', 20)->default('subsidiary')->after('parent_id'); // holding, sub_holding, subsidiary
            $table->foreign('parent_id')->references('id')->on('organizations')->nullOnDelete();
            $table->index('parent_id');
            $table->index('org_level');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn(['parent_id', 'org_level']);
        });
    }
};
