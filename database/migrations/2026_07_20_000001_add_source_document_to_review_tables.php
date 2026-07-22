<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan balik Contract/Policy Review → dokumen Document Maker asalnya.
 *
 * Alur prefill `/contract-review/new?source=docmaker&id=<uuid>` (dan padanan
 * policy) sebelumnya membuang id sumber begitu teks di-paste ke form, sehingga
 * halaman hasil tidak bisa menautkan balik ke `generated_documents`.
 *
 * Kedua kolom nullable tanpa default supaya AMAN untuk baris yang sudah ada —
 * record lama tetap NULL dan tidak butuh backfill. Sengaja TIDAK memakai
 * foreign key: dokumen sumber boleh dihapus (soft/hard) tanpa menjatuhkan
 * hasil review yang sudah terlanjur dibuat; validasi kepemilikan org
 * dilakukan di layer aplikasi (AiFeatureController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_reviews', function (Blueprint $table) {
            $table->uuid('source_document_id')->nullable()->after('created_by');
            $table->string('source_module', 32)->nullable()->after('source_document_id');
            $table->index(['org_id', 'source_document_id'], 'contract_reviews_org_source_idx');
        });

        Schema::table('policy_reviews', function (Blueprint $table) {
            $table->uuid('source_document_id')->nullable()->after('created_by');
            $table->string('source_module', 32)->nullable()->after('source_document_id');
            $table->index(['org_id', 'source_document_id'], 'policy_reviews_org_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contract_reviews', function (Blueprint $table) {
            $table->dropIndex('contract_reviews_org_source_idx');
            $table->dropColumn(['source_document_id', 'source_module']);
        });

        Schema::table('policy_reviews', function (Blueprint $table) {
            $table->dropIndex('policy_reviews_org_source_idx');
            $table->dropColumn(['source_document_id', 'source_module']);
        });
    }
};
