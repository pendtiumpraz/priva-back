<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->string('guest_name', 100)->nullable()->after('user_id');
            $table->string('guest_email', 150)->nullable()->after('guest_name');
        });
    }

    public function down(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'guest_email']);
        });
    }
};
