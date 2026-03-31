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
        Schema::create('organization_apps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            
            // Staging DB Config for fast scan
            $table->string('staging_db_driver')->nullable(); // mysql, pgsql
            $table->string('staging_db_host')->nullable();
            $table->string('staging_db_port')->nullable();
            $table->string('staging_db_database')->nullable();
            $table->string('staging_db_username')->nullable();
            $table->text('staging_db_password')->nullable();
            
            // Production DB Config for deep scan
            $table->string('prod_db_driver')->nullable();
            $table->string('prod_db_host')->nullable();
            $table->string('prod_db_port')->nullable();
            $table->string('prod_db_database')->nullable();
            $table->string('prod_db_username')->nullable();
            $table->text('prod_db_password')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_apps');
    }
};
