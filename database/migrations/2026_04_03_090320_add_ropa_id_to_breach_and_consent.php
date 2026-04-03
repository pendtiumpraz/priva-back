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
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->uuid('linked_ropa_id')->nullable()->after('incident_code');
            $table->foreign('linked_ropa_id')->references('id')->on('ropas')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('breach_incidents', function (Blueprint $table) {
            $table->dropForeign(['linked_ropa_id']);
            $table->dropColumn('linked_ropa_id');
        });
    }
};
