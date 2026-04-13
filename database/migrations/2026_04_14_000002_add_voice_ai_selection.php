<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_active_selections', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_active_selections', 'voice_provider_id')) {
                $table->foreignId('voice_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            }
            if (!Schema::hasColumn('ai_active_selections', 'voice_model_id')) {
                $table->foreignId('voice_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_active_selections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voice_provider_id');
            $table->dropConstrainedForeignId('voice_model_id');
        });
    }
};
