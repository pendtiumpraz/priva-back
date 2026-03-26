<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->jsonb('wizard_data')->nullable()->after('security_measures');
            $table->decimal('progress', 5, 2)->default(0)->after('wizard_data');
            $table->string('entity')->nullable()->after('processing_activity');
            $table->string('work_unit')->nullable()->after('division');
            $table->text('description')->nullable()->after('work_unit');
            $table->string('kategori_pemrosesan')->nullable()->after('description');
        });

        // Audit logs table for all modules
        if (!Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('module');
                $table->uuid('record_id');
                $table->string('action');
                $table->uuid('user_id')->nullable();
                $table->string('user_name')->nullable();
                $table->string('user_role')->nullable();
                $table->string('section')->nullable();
                $table->string('field')->nullable();
                $table->jsonb('changes')->nullable();
                $table->string('ip_address')->nullable();
                $table->timestamps();

                $table->index(['module', 'record_id']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('ropas', function (Blueprint $table) {
            $table->dropColumn(['wizard_data', 'progress', 'entity', 'work_unit', 'description', 'kategori_pemrosesan']);
        });
        Schema::dropIfExists('audit_logs');
    }
};
