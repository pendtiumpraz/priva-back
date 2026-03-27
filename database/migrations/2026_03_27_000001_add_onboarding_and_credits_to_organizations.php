<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Onboarding fields
            $table->string('business_model')->nullable()->after('industry'); // B2B, B2C, B2B2C, Internal
            $table->string('company_size')->nullable()->after('business_model'); // 1-10, 11-50, 51-200, 201-500, 500+
            $table->json('data_subjects_type')->nullable()->after('company_size'); // ["karyawan","pelanggan","pasien"]
            $table->json('core_systems')->nullable()->after('data_subjects_type'); // ["ERP","CRM","HRIS"]
            $table->boolean('has_dpo')->default(false)->after('core_systems');
            $table->boolean('onboarding_completed')->default(false)->after('has_dpo');

            // AI Credit fields
            $table->integer('ai_credits_monthly')->default(0)->after('onboarding_completed');
            $table->integer('ai_credits_remaining')->default(0)->after('ai_credits_monthly');
            $table->integer('ai_credits_purchased')->default(0)->after('ai_credits_remaining');
            $table->timestamp('ai_credits_reset_at')->nullable()->after('ai_credits_purchased');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn([
                'business_model', 'company_size', 'data_subjects_type', 'core_systems',
                'has_dpo', 'onboarding_completed',
                'ai_credits_monthly', 'ai_credits_remaining', 'ai_credits_purchased', 'ai_credits_reset_at',
            ]);
        });
    }
};
