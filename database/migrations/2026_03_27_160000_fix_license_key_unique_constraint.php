<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            // Drop the old unique constraint on license_key alone
            $table->dropUnique(['license_key']);

            // Add composite unique: same key can exist for different org_id
            // This supports "beli putus" where 1 key = SA (org_id=null) + Tenant (org_id=uuid)
            $table->unique(['license_key', 'org_id'], 'licenses_key_org_unique');
        });
    }

    public function down(): void
    {
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropUnique('licenses_key_org_unique');
            $table->unique('license_key');
        });
    }
};
