<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI Providers (OpenAI, Anthropic, Google, DeepSeek, etc.)
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // openai, anthropic, google, deepseek, etc.
            $table->string('name');           // OpenAI, Anthropic, Google AI, etc.
            $table->string('api_base_url');   // https://api.openai.com/v1
            $table->string('auth_header')->default('Authorization'); // Bearer token header
            $table->string('auth_prefix')->default('Bearer');
            $table->boolean('supports_tools')->default(false);  // function calling / tool use
            $table->boolean('supports_streaming')->default(true);
            $table->boolean('is_active')->default(true); // globally enabled
            $table->integer('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('icon')->nullable(); // emoji or URL
            $table->timestamps();
        });

        // AI Models (GPT-5.4, Claude Sonnet 4.6, Gemini 3.1 Pro, etc.)
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('model_id');       // gpt-5.4, claude-sonnet-4-6, gemini-2.5-pro
            $table->string('name');           // GPT-5.4, Claude Sonnet 4.6
            $table->string('category')->default('chat'); // chat, reasoning, coding, agent
            $table->integer('context_window')->default(128000); // max tokens
            $table->integer('max_output_tokens')->nullable();
            $table->boolean('supports_tools')->default(false);
            $table->boolean('supports_vision')->default(false);
            $table->boolean('is_reasoning')->default(false); // thinking/CoT model
            $table->boolean('recommended_for_agent')->default(false);
            $table->decimal('input_price_per_m', 10, 4)->nullable(); // $ per 1M input tokens
            $table->decimal('output_price_per_m', 10, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['provider_id', 'model_id']);
        });

        // Per-Tenant AI Provider Configuration
        Schema::create('ai_provider_configs', function (Blueprint $table) {
            $table->id();
            $table->uuid('org_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreignId('provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->text('api_key_encrypted'); // encrypted API key
            $table->json('extra_config')->nullable(); // org_id for some providers, etc.
            $table->boolean('is_verified')->default(false); // connection tested successfully
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'provider_id']);
        });

        // Per-Tenant Active AI Selection
        Schema::create('ai_active_selections', function (Blueprint $table) {
            $table->id();
            $table->uuid('org_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();

            // For regular AI (chatbot, autofill, analysis)
            $table->foreignId('chat_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('chat_model_id')->nullable()->constrained('ai_models')->nullOnDelete();

            // For AI Agent (function calling, agentic)
            $table->foreignId('agent_provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            $table->foreignId('agent_model_id')->nullable()->constrained('ai_models')->nullOnDelete();

            $table->timestamps();

            $table->unique('org_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_active_selections');
        Schema::dropIfExists('ai_provider_configs');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
