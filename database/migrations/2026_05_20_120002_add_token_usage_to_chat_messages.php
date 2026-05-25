<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom token usage per chat message untuk cost tracking + analytics.
 *
 * LLM provider (DeepSeek/OpenAI/Anthropic) return `usage` object di response:
 *   { prompt_tokens, completion_tokens, total_tokens }
 *
 * Disimpan di chat_messages untuk:
 *   - Per-conversation cost analysis (SUM total_tokens × provider rate)
 *   - Debug context overflow (lihat prompt_tokens trajectory naik per turn)
 *   - User-facing "this message cost X token" indicator
 *   - Admin dashboard token consumption per tenant
 *
 * NB: tidak dual-write dengan ai_credit_logs (yang sudah ada). ai_credit_logs
 * track credit unit per feature (chat=0.25 unit), chat_messages.tokens
 * track raw provider token granularity per message.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_messages')) return;
        if (Schema::hasColumn('chat_messages', 'total_tokens')) return;

        Schema::table('chat_messages', function (Blueprint $table) {
            // Nullable: legacy message + non-AI message (user message tidak punya usage)
            $table->unsignedInteger('prompt_tokens')->nullable()->after('summary');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            $table->unsignedInteger('total_tokens')->nullable()->after('completion_tokens');
            // Provider info untuk multi-provider audit
            $table->string('provider', 32)->nullable()->after('total_tokens');
            $table->string('model', 64)->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_messages')) return;

        Schema::table('chat_messages', function (Blueprint $table) {
            if (Schema::hasColumn('chat_messages', 'prompt_tokens')) $table->dropColumn('prompt_tokens');
            if (Schema::hasColumn('chat_messages', 'completion_tokens')) $table->dropColumn('completion_tokens');
            if (Schema::hasColumn('chat_messages', 'total_tokens')) $table->dropColumn('total_tokens');
            if (Schema::hasColumn('chat_messages', 'provider')) $table->dropColumn('provider');
            if (Schema::hasColumn('chat_messages', 'model')) $table->dropColumn('model');
        });
    }
};
