<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('consent_collection_points', function (Blueprint $table) {
            if (!Schema::hasColumn('consent_collection_points', 'records_count')) {
                $table->unsignedBigInteger('records_count')->default(0)->after('is_active');
            }
        });
    }

    public function down()
    {
        Schema::table('consent_collection_points', function (Blueprint $table) {
            if (Schema::hasColumn('consent_collection_points', 'records_count')) {
                $table->dropColumn('records_count');
            }
        });
    }
};
