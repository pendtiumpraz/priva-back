<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA Center — root-level fitur untuk track test coverage seluruh platform.
 *
 * 5 tabel:
 *   qa_test_cases        catalog test case (seeded dari kode)
 *   qa_test_runs         cycle QA per release
 *   qa_test_results      status per (run, case, role)
 *   qa_bug_reports       bug terkait test_result yang fail
 *   qa_bug_screenshots   image attachment bug
 *
 * Bukan tenant-scoped — root-only feature, gak punya org_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_test_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('module', 64)->index();
            $table->string('feature', 128);
            $table->string('interaction', 128);
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->text('expected_behavior')->nullable();
            $table->json('applicable_roles')->nullable();
            $table->json('license_packages')->nullable();
            $table->boolean('is_built_in')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['module', 'feature']);
            $table->index('is_active');
        });

        Schema::create('qa_test_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('version', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('qa_test_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('test_run_id');
            $table->uuid('test_case_id');
            $table->string('role', 64)->default('any');
            $table->string('status', 32)->default('not_tested');
            $table->string('tester_name', 255)->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['test_run_id', 'test_case_id', 'role'], 'qa_results_unique_combo');
            $table->index('status');
            $table->foreign('test_run_id')->references('id')->on('qa_test_runs')->cascadeOnDelete();
            $table->foreign('test_case_id')->references('id')->on('qa_test_cases')->cascadeOnDelete();
        });

        Schema::create('qa_bug_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('test_result_id');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('severity', 32)->default('medium');
            $table->string('status', 32)->default('open');
            $table->string('reporter_name', 255)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('assigned_to_name', 255)->nullable();
            $table->string('resolver_name', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('verified_by_name', 255)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('severity');
            $table->foreign('test_result_id')->references('id')->on('qa_test_results')->cascadeOnDelete();
        });

        Schema::create('qa_bug_screenshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('bug_report_id');
            $table->string('file_path', 512);
            $table->string('file_name', 255)->nullable();
            $table->integer('file_size')->nullable();
            $table->string('mime_type', 64)->nullable();
            $table->string('uploaded_by_name', 255)->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();

            $table->foreign('bug_report_id')->references('id')->on('qa_bug_reports')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_bug_screenshots');
        Schema::dropIfExists('qa_bug_reports');
        Schema::dropIfExists('qa_test_results');
        Schema::dropIfExists('qa_test_runs');
        Schema::dropIfExists('qa_test_cases');
    }
};
