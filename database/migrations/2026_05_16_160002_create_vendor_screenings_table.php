<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TPRM Phase 3 — Vendor screening result history.
 *
 * Setiap kali user klik "AI Screening" di vendor, row baru ditulis di sini.
 * Bukan replace — append-only history supaya bisa lihat perubahan risiko
 * vendor seiring waktu (mis. jadi negatif setelah berita bocor data).
 *
 * Sources di-track per row supaya tahu data apa yang dipakai screening ini
 * (kalau di-rerun nanti dengan source berbeda, history lengkap).
 *
 * AI assessment payload disimpan di field JSON `findings` + `red_flags`
 * supaya FE bisa render tanpa parsing lagi.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendor_screenings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->index();
            $table->uuid('vendor_id')->index();
            $table->uuid('triggered_by_user_id')->nullable();

            // Sources yang dipakai (array of: 'web_search', 'privacy_policy',
            // 'documents', 'sanctions'). Bisa berbeda per run.
            $table->jsonb('sources_used')->nullable();

            // Hasil AI assessment final
            $table->string('overall_risk', 16)->default('unknown'); // low|medium|high|critical|unknown
            $table->unsignedTinyInteger('risk_score')->nullable();  // 0-100 confidence-weighted
            $table->jsonb('findings')->nullable();      // structured: [{type, description, source, severity, confidence}]
            $table->jsonb('red_flags')->nullable();     // critical findings disorot
            $table->text('summary')->nullable();        // narrative summary AI
            $table->text('recommendation')->nullable(); // action recommendation

            // Raw inputs untuk audit (struktur yang dikirim ke AI)
            $table->jsonb('search_results_raw')->nullable();
            $table->jsonb('privacy_policy_excerpt')->nullable();
            $table->jsonb('documents_summary')->nullable();
            $table->jsonb('sanctions_hits')->nullable();

            // Provider info untuk traceability
            $table->string('search_provider', 32)->nullable();   // 'duckduckgo' | 'brave' | 'tavily'
            $table->string('ai_model', 64)->nullable();           // model id yang dipakai
            $table->unsignedInteger('tokens_used')->default(0);

            // Status execution
            $table->string('status', 16)->default('pending'); // pending|running|completed|failed
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'created_at']);
            $table->index(['org_id', 'overall_risk']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_screenings');
    }
};
