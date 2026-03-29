<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('information_systems', function (Blueprint $table) {
            $table->string('owner')->nullable()->after('name');
        });

        // Add settings to consent_collection_points if not already jsonb
        // (Already exists in main migration as 'settings' jsonb)
    }

    public function down(): void
    {
        Schema::table('information_systems', function (Blueprint $table) {
            $table->dropColumn('owner');
        });
    }
};
