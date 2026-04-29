<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master list of Maturity Assessment questions, seeded by
 * MaturityQuestionsSeeder per the UU PDP framework in
 * docs/new_feat/Privacy Compliance Maturity Assessment.pdf.
 *
 * Questions are versioned by `version` so when UU PDP regulations are
 * amended, a new set can be seeded without breaking existing
 * assessments — past responses stay tied to the version they were
 * scored against.
 *
 * Domain values:
 *   - governance              (Pasal 53 — Tata Kelola & DPO)
 *   - processing_basis        (Pasal 20 & 5-13 — Dasar pemrosesan & hak subjek)
 *   - controller_obligations  (Pasal 35-39 — Kewajiban pengendali & prosesor)
 *   - security                (Pasal 46-48 — Keamanan & penanganan kegagalan)
 *
 * Question codes follow PDF: A1, A2, B3, B4, C5..C16, D17, D18.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('maturity_questions')) {
            Schema::create('maturity_questions', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('question_code', 16);                // 'A1', 'C7', etc.
                $table->string('domain', 40);                       // see comment block above
                $table->string('regulation_ref', 100)->nullable();  // 'UU PDP Pasal 53'
                $table->text('question_text');
                $table->text('description')->nullable();
                $table->json('scoring_guide')->nullable();
                // Optional explanatory ranges, e.g. { "1-3": "...", "4-6": "...", ... }
                $table->boolean('is_active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->string('version', 16)->default('v1');
                $table->timestamps();

                $table->unique(['question_code', 'version']);
                $table->index(['domain', 'is_active']);
                $table->index('sort_order');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('maturity_questions');
    }
};
