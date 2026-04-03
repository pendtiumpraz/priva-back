<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->string('regulation_code')->default('uupdp')->after('status')->index();
        });
        Schema::table('dpias', function (Blueprint $table) {
            $table->string('regulation_code')->default('uupdp')->after('status')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->dropColumn('regulation_code');
        });
        Schema::table('dpias', function (Blueprint $table) {
            $table->dropColumn('regulation_code');
        });
    }
};
