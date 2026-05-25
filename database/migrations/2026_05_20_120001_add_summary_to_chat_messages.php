<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah kolom `summary` di chat_messages untuk inline summary memory.
 *
 * AI generate response dalam format JSON {reply, summary} — `summary`
 * disimpan di sini, tidak ditampilkan ke FE. Saat history load:
 *   - Last 10 message: full content
 *   - Older messages: summary saja, di-build sebagai 1 system message
 *     "[Ringkasan percakapan sebelumnya]: ..."
 *
 * Pattern: LangChain ConversationSummaryBufferMemory equivalent.
 *
 * Idempotent: pakai Schema::hasColumn guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('chat_messages')) return;
        if (Schema::hasColumn('chat_messages', 'summary')) return;

        Schema::table('chat_messages', function (Blueprint $table) {
            // Short summary ringkas (20-30 kata) untuk context compression.
            // Nullable: legacy message tanpa summary tetap valid (akan diabaikan
            // saat build history).
            $table->text('summary')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('chat_messages')) return;
        if (!Schema::hasColumn('chat_messages', 'summary')) return;

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
