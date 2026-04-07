<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('uploaded_by')->nullable();

            // File info
            $table->string('original_filename');
            $table->string('storage_path');
            $table->string('file_type');              // docx, xlsx, csv, pdf
            $table->unsignedBigInteger('file_size');  // bytes

            // Processing
            $table->string('target_module');           // ropa, dpia
            $table->string('status')->default('queued'); // queued, parsing, analyzing, mapping, review, creating, completed, failed
            $table->unsignedTinyInteger('progress')->default(0); // 0-100
            $table->text('status_message')->nullable();

            // AI Results
            $table->jsonb('extracted_data')->nullable();
            $table->jsonb('mapped_fields')->nullable();
            $table->jsonb('confidence_scores')->nullable();
            $table->uuid('created_record_id')->nullable();

            // Batch reference
            $table->uuid('batch_id')->nullable();

            // Error handling
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('uploaded_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['org_id', 'status']);
            $table->index(['batch_id']);
        });

        Schema::create('document_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('created_by')->nullable();
            $table->string('name');
            $table->string('target_module');
            $table->unsignedInteger('total_files')->default(0);
            $table->unsignedInteger('completed_files')->default(0);
            $table->unsignedInteger('failed_files')->default(0);
            $table->string('status')->default('processing'); // processing, completed, partial_failure
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_imports');
        Schema::dropIfExists('document_import_batches');
    }
};
