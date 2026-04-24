<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom `summary` ke knowledge_base_sections untuk support
 * 2-tier grounding:
 *   - `summary` (50-200 token) — ultra-short, selalu inject untuk tight-budget feature
 *   - `content` (500-2000 token) — full detail, inject kalau budget allows
 *
 * Juga tambah kolom opsional `feature_tags` untuk filter per-fitur AI
 * (e.g. "ropa_autofill,contract_review") — supaya findRelevant bisa scope
 * query ke section yang relevant untuk fitur tertentu saja.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('knowledge_base_sections', 'summary')) return;

        try {
            Schema::table('knowledge_base_sections', function (Blueprint $table) {
                $table->text('summary')->nullable()->after('content');
                $table->string('feature_tags', 500)->nullable()->after('keywords');
                $table->string('category', 64)->nullable()->after('feature_tags');
            });
        } catch (\Illuminate\Database\QueryException $e) {
            $code = $e->errorInfo[1] ?? null;
            if ($code === 1060 || in_array($e->getCode(), ['42701', '42S21'], true)) return;
            throw $e;
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('knowledge_base_sections', 'summary')) return;

        try {
            Schema::table('knowledge_base_sections', function (Blueprint $table) {
                $table->dropColumn(['summary', 'feature_tags', 'category']);
            });
        } catch (\Illuminate\Database\QueryException $e) { /* ignore */ }
    }
};
