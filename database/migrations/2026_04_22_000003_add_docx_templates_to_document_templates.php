<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds tenant-uploaded DOCX templates per export kind.
 *
 * Stored shape (nullable, defaults to []):
 *   { "ropa":  {"path": "tenants/{org}/docx-tpl/ropa_xxx.docx", "name": "MyRopa.docx"},
 *     "dpia":  {...}, "gap": {...} }
 *
 * Export controllers check active DocumentTemplate.docx_templates[kind]. When
 * present, render via PhpWord TemplateProcessor (placeholder fill). Else fall
 * back to built-in programmatic generator.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('docx_templates')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('docx_templates');
        });
    }
};
