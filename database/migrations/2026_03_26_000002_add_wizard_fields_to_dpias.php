<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dpias', function (Blueprint $table) {
            $table->jsonb('wizard_data')->nullable()->after('mitigation_measures');
            $table->decimal('progress', 5, 2)->default(0)->after('wizard_data');
        });
    }

    public function down(): void
    {
        Schema::table('dpias', function (Blueprint $table) {
            $table->dropColumn(['wizard_data', 'progress']);
        });
    }
};
