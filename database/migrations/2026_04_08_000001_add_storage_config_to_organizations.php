<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('storage_driver')->nullable()->after('settings');       // s3, gcs, azure, local, null=default
            $table->text('storage_config')->nullable()->after('storage_driver');    // encrypted JSON: key, secret, bucket, region, endpoint
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['storage_driver', 'storage_config']);
        });
    }
};
