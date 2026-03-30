<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_collection_points', function (Blueprint $table) {
            $table->string('webhook_url')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('consent_collection_points', function (Blueprint $table) {
            $table->dropColumn('webhook_url');
        });
    }
};
