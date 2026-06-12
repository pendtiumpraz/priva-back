<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Discriminate chat conversations by surface so the AI Agent history and the
 * 3D Avatar (Priva) history don't mix. Existing rows are AI Agent conversations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->string('channel', 20)->default('agent')->index();
        });

        // Backfill existing rows explicitly (defensive; the default already covers it).
        DB::table('chat_conversations')->whereNull('channel')->update(['channel' => 'agent']);
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
