<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('org_id');
            $table->string('user_name');
            $table->string('user_email');
            $table->string('status')->default('open'); // open, closed, admin_active
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'status']);
            $table->index(['user_id']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('conversation_id');
            $table->string('role'); // user, assistant, admin
            $table->text('content');
            $table->string('sender_name')->nullable(); // for admin replies
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
