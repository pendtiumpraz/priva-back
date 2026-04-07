<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_active_selections', function (Blueprint $table) {
            $table->foreignId('document_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('document_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ai_active_selections', function (Blueprint $table) {
            $table->dropForeign(['document_provider_id']);
            $table->dropForeign(['document_model_id']);
            $table->dropColumn(['document_provider_id', 'document_model_id']);
        });
    }
};
