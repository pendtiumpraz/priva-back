<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Departments (with hierarchy support)
        Schema::create('departments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name');
            $table->string('code')->nullable(); // e.g. HR, IT, FIN
            $table->uuid('parent_id')->nullable(); // for tree hierarchy
            $table->uuid('head_user_id')->nullable(); // department head
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('parent_id')->references('id')->on('departments')->onDelete('set null');
            $table->foreign('head_user_id')->references('id')->on('users')->onDelete('set null');
            $table->index(['org_id', 'is_active']);
        });

        // Positions (jabatan per tenant)
        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('department_id')->nullable();
            $table->string('name'); // e.g. "DPO", "CISO", "IT Manager"
            $table->string('level')->nullable(); // e.g. "C-Level", "Manager", "Staff"
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('department_id')->references('id')->on('departments')->onDelete('set null');
            $table->index(['org_id', 'is_active']);
        });

        // Add department_id and position_id to users table
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('department_id')->nullable()->after('position');
            $table->uuid('position_id')->nullable()->after('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['department_id', 'position_id']);
        });
        Schema::dropIfExists('positions');
        Schema::dropIfExists('departments');
    }
};
