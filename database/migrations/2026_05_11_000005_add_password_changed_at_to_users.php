<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Track kapan password user terakhir diganti — buat enforce password
 * rotation policy. Grandfather user existing dengan password_changed_at = now
 * supaya gak langsung kena rotation timeout setelah feature di-enable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('two_factor_confirmed_at');
        });

        // Grandfather: user existing → set ke now() supaya gak langsung
        // dipaksa ganti password tepat setelah deploy.
        DB::table('users')->whereNull('password_changed_at')->update([
            'password_changed_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
