<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiProvider;
use Illuminate\Database\Seeder;

class AiProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            // =============================================
            // 1. OpenAI
            // =============================================
            [
                'slug' => 'openai',
                'name' => 'OpenAI',
                'api_base_url' => 'https://api.openai.com/v1',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 1,
                'description' => 'OpenAI — pembuat GPT-5.4, o3, o4-mini. Provider AI terbesar di dunia.',
                'website' => 'https://platform.openai.com',
                'icon' => '🟢',
                'models' => [
                    ['model_id' => 'gpt-5.4', 'name' => 'GPT-5.4', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 5.0, 'output_price_per_m' => 15.0, 'sort_order' => 1],
                    ['model_id' => 'gpt-5.4-pro', 'name' => 'GPT-5.4 Pro', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => 65536, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 10.0, 'output_price_per_m' => 30.0, 'sort_order' => 2],
                    ['model_id' => 'gpt-5.4-mini', 'name' => 'GPT-5.4 Mini', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.4, 'output_price_per_m' => 1.6, 'sort_order' => 3],
                    ['model_id' => 'gpt-5.4-nano', 'name' => 'GPT-5.4 Nano', 'category' => 'chat', 'context_window' => 512000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.1, 'output_price_per_m' => 0.4, 'sort_order' => 4],
                    ['model_id' => 'gpt-5.3-chat', 'name' => 'GPT-5.3 Chat', 'category' => 'chat', 'context_window' => 512000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 2.0, 'output_price_per_m' => 8.0, 'sort_order' => 5],
                    ['model_id' => 'gpt-5.3-codex', 'name' => 'GPT-5.3 Codex', 'category' => 'coding', 'context_window' => 512000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 3.0, 'output_price_per_m' => 12.0, 'sort_order' => 6],
                    ['model_id' => 'o3', 'name' => 'o3 (Reasoning)', 'category' => 'reasoning', 'context_window' => 200000, 'max_output_tokens' => 100000, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 10.0, 'output_price_per_m' => 40.0, 'sort_order' => 7],
                    ['model_id' => 'o4-mini', 'name' => 'o4-mini (Reasoning)', 'category' => 'reasoning', 'context_window' => 200000, 'max_output_tokens' => 100000, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.1, 'output_price_per_m' => 4.4, 'sort_order' => 8],
                    ['model_id' => 'gpt-4.1', 'name' => 'GPT-4.1', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 2.0, 'output_price_per_m' => 8.0, 'sort_order' => 9],
                    ['model_id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 Mini', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.4, 'output_price_per_m' => 1.6, 'sort_order' => 10],
                    ['model_id' => 'gpt-4.1-nano', 'name' => 'GPT-4.1 Nano', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.1, 'output_price_per_m' => 0.4, 'sort_order' => 11],
                    ['model_id' => 'gpt-4o', 'name' => 'GPT-4o', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 2.5, 'output_price_per_m' => 10.0, 'sort_order' => 12],
                    ['model_id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.6, 'sort_order' => 13],
                ],
            ],

            // =============================================
            // 2. Anthropic
            // =============================================
            [
                'slug' => 'anthropic',
                'name' => 'Anthropic',
                'api_base_url' => 'https://api.anthropic.com/v1',
                'auth_header' => 'x-api-key',
                'auth_prefix' => '',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 2,
                'description' => 'Anthropic — pembuat Claude. Model AI terdepan untuk coding dan reasoning.',
                'website' => 'https://console.anthropic.com',
                'icon' => '🟤',
                'models' => [
                    ['model_id' => 'claude-opus-4-6', 'name' => 'Claude Opus 4.6', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 15.0, 'output_price_per_m' => 75.0, 'sort_order' => 1],
                    ['model_id' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 3.0, 'output_price_per_m' => 15.0, 'sort_order' => 2],
                    ['model_id' => 'claude-sonnet-5-fennec', 'name' => 'Claude Sonnet 5 (Fennec)', 'category' => 'coding', 'context_window' => 1000000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 4.0, 'output_price_per_m' => 20.0, 'sort_order' => 3],
                    ['model_id' => 'claude-haiku-4-5', 'name' => 'Claude Haiku 4.5', 'category' => 'chat', 'context_window' => 200000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.8, 'output_price_per_m' => 4.0, 'sort_order' => 4],
                    ['model_id' => 'claude-3-5-sonnet-20241022', 'name' => 'Claude 3.5 Sonnet (Legacy)', 'category' => 'chat', 'context_window' => 200000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 3.0, 'output_price_per_m' => 15.0, 'sort_order' => 5],
                ],
            ],

            // =============================================
            // 3. Google AI (Gemini)
            // =============================================
            [
                'slug' => 'google',
                'name' => 'Google AI (Gemini)',
                'api_base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'auth_header' => '',
                'auth_prefix' => '',
                // Uses standard Bearer auth via OpenAI-compatible endpoint
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 3,
                'description' => 'Google AI — Gemini 3.1 Pro, Flash. Konteks hingga 2M tokens.',
                'website' => 'https://aistudio.google.com',
                'icon' => '🔵',
                'models' => [
                    ['model_id' => 'gemini-3.1-pro-preview', 'name' => 'Gemini 3.1 Pro Preview', 'category' => 'reasoning', 'context_window' => 2000000, 'max_output_tokens' => 65536, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.25, 'output_price_per_m' => 5.0, 'sort_order' => 1],
                    ['model_id' => 'gemini-3-flash-preview', 'name' => 'Gemini 3 Flash Preview', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.6, 'sort_order' => 2],
                    ['model_id' => 'gemini-3.1-flash-lite-preview', 'name' => 'Gemini 3.1 Flash Lite', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.04, 'output_price_per_m' => 0.15, 'sort_order' => 3],
                    ['model_id' => 'gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro', 'category' => 'reasoning', 'context_window' => 1048576, 'max_output_tokens' => 65536, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.25, 'output_price_per_m' => 5.0, 'sort_order' => 4],
                    ['model_id' => 'gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => 65536, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.6, 'sort_order' => 5],
                    ['model_id' => 'gemini-2.5-flash-lite', 'name' => 'Gemini 2.5 Flash Lite', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.04, 'output_price_per_m' => 0.15, 'sort_order' => 6],
                ],
            ],

            // =============================================
            // 3b. AWS Bedrock — managed foundation models (Claude, Llama, Nova, Mistral)
            // Auth = AWS SigV4 (Access Key ID + Secret + region), BUKAN bearer key.
            // api_base_url mengikuti region (mis. bedrock-runtime.eu-central-1.amazonaws.com).
            // =============================================
            [
                'slug' => 'bedrock',
                'name' => 'AWS Bedrock',
                'api_base_url' => 'https://bedrock-runtime.us-east-1.amazonaws.com',
                'auth_header' => '',
                'auth_prefix' => '',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 7,
                'description' => 'AWS Bedrock — model terkelola (Claude, Llama, Amazon Nova, Mistral) dengan kontrol region & data residency. Autentikasi via AWS SigV4 (Access Key + Secret + region), zero data retention default.',
                'website' => 'https://aws.amazon.com/bedrock/',
                'icon' => '🟧',
                'models' => [
                    ['model_id' => 'anthropic.claude-sonnet-4-20250514-v1:0', 'name' => 'Claude Sonnet 4 (Bedrock)', 'category' => 'reasoning', 'context_window' => 200000, 'max_output_tokens' => 65536, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 3.0, 'output_price_per_m' => 15.0, 'sort_order' => 1],
                    ['model_id' => 'anthropic.claude-3-5-haiku-20241022-v1:0', 'name' => 'Claude 3.5 Haiku (Bedrock)', 'category' => 'chat', 'context_window' => 200000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.8, 'output_price_per_m' => 4.0, 'sort_order' => 2],
                    ['model_id' => 'amazon.nova-pro-v1:0', 'name' => 'Amazon Nova Pro', 'category' => 'chat', 'context_window' => 300000, 'max_output_tokens' => 5120, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.8, 'output_price_per_m' => 3.2, 'sort_order' => 3],
                    ['model_id' => 'amazon.nova-lite-v1:0', 'name' => 'Amazon Nova Lite', 'category' => 'chat', 'context_window' => 300000, 'max_output_tokens' => 5120, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.06, 'output_price_per_m' => 0.24, 'sort_order' => 4],
                    ['model_id' => 'meta.llama3-3-70b-instruct-v1:0', 'name' => 'Llama 3.3 70B (Bedrock)', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.72, 'output_price_per_m' => 0.72, 'sort_order' => 5],
                ],
            ],

            // =============================================
            // 4. DeepSeek
            // =============================================
            [
                'slug' => 'deepseek',
                'name' => 'DeepSeek',
                'api_base_url' => 'https://api.deepseek.com/v1',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 4,
                'description' => 'DeepSeek — model China yang sangat cost-effective. OpenAI-compatible API.',
                'website' => 'https://platform.deepseek.com',
                'icon' => '🐋',
                'models' => [
                    // V4 series — released April 2026, 1M context, MoE architecture.
                    // Replaces deepseek-chat/reasoner (V3.x) which retire 24 Jul 2026.
                    ['model_id' => 'deepseek-v4-pro', 'name' => 'DeepSeek V4 Pro', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => 384000, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.74, 'output_price_per_m' => 3.48, 'sort_order' => 1],
                    ['model_id' => 'deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 384000, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.14, 'output_price_per_m' => 0.28, 'sort_order' => 2],
                    // Legacy V3.x — retire 24 Jul 2026, kept for migration window.
                    ['model_id' => 'deepseek-chat', 'name' => 'DeepSeek Chat (V3.2 — Legacy)', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.27, 'output_price_per_m' => 1.1, 'sort_order' => 10],
                    ['model_id' => 'deepseek-reasoner', 'name' => 'DeepSeek Reasoner (R1 — Legacy)', 'category' => 'reasoning', 'context_window' => 128000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.55, 'output_price_per_m' => 2.19, 'sort_order' => 11],
                ],
            ],

            // =============================================
            // 4b. DeepSeek Vision/OCR (separate provider record so the OCR
            // fallback can target a vision model without colliding with the
            // text-only DeepSeek selection above). Accessed via DeepInfra's
            // OpenAI-compatible endpoint — same Bearer-auth shape as OpenAI.
            // =============================================
            [
                'slug' => 'deepseek-vision',
                'name' => 'DeepSeek Vision/OCR',
                'api_base_url' => 'https://api.deepinfra.com/v1/openai',
                'auth_header' => 'Authorization',
                'auth_prefix' => 'Bearer',
                'supports_tools' => false,
                'supports_streaming' => false,
                'sort_order' => 5,
                'description' => 'DeepSeek-OCR & VL2 untuk extract text dari scanned PDF / image. Diakses via DeepInfra OpenAI-compatible API.',
                'website' => 'https://deepinfra.com/deepseek-ai/DeepSeek-OCR',
                'icon' => '👁️',
                'models' => [
                    ['model_id' => 'deepseek-ai/DeepSeek-OCR', 'name' => 'DeepSeek OCR', 'category' => 'vision', 'context_window' => 32000, 'max_output_tokens' => 8192, 'supports_tools' => false, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.03, 'output_price_per_m' => 0.10, 'sort_order' => 1],
                    ['model_id' => 'deepseek-ai/deepseek-vl2', 'name' => 'DeepSeek VL2', 'category' => 'vision', 'context_window' => 32000, 'max_output_tokens' => 8192, 'supports_tools' => false, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.10, 'output_price_per_m' => 0.30, 'sort_order' => 2],
                ],
            ],

            // =============================================
            // 5. Mistral AI
            // =============================================
            [
                'slug' => 'mistral',
                'name' => 'Mistral AI',
                'api_base_url' => 'https://api.mistral.ai/v1',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 5,
                'description' => 'Mistral AI — open-weight models dari Prancis. Mistral Small 4 & Large 3.',
                'website' => 'https://console.mistral.ai',
                'icon' => '🟠',
                'models' => [
                    ['model_id' => 'mistral-large-latest', 'name' => 'Mistral Large 3', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => true, 'input_price_per_m' => 2.0, 'output_price_per_m' => 6.0, 'sort_order' => 1],
                    ['model_id' => 'mistral-small-2603', 'name' => 'Mistral Small 4', 'category' => 'chat', 'context_window' => 256000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.1, 'output_price_per_m' => 0.3, 'sort_order' => 2],
                    ['model_id' => 'magistral-medium-latest', 'name' => 'Magistral Medium 1.2', 'category' => 'reasoning', 'context_window' => 131072, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 2.0, 'output_price_per_m' => 5.0, 'sort_order' => 3],
                    ['model_id' => 'magistral-small-latest', 'name' => 'Magistral Small 1.2', 'category' => 'reasoning', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.1, 'output_price_per_m' => 0.3, 'sort_order' => 4],
                    ['model_id' => 'devstral-2', 'name' => 'Devstral 2 (Coding)', 'category' => 'coding', 'context_window' => 131072, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.2, 'output_price_per_m' => 0.6, 'sort_order' => 5],
                ],
            ],

            // =============================================
            // 6. xAI (Grok)
            // =============================================
            [
                'slug' => 'xai',
                'name' => 'xAI (Grok)',
                'api_base_url' => 'https://api.x.ai/v1',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 6,
                'description' => 'xAI — pembuat Grok. Real-time web search built-in, agentic capabilities.',
                'website' => 'https://console.x.ai',
                'icon' => '⚡',
                'models' => [
                    ['model_id' => 'grok-4.20-reasoning', 'name' => 'Grok 4.20 Reasoning', 'category' => 'reasoning', 'context_window' => 256000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 3.0, 'output_price_per_m' => 15.0, 'sort_order' => 1],
                    ['model_id' => 'grok-4.20-non-reasoning', 'name' => 'Grok 4.20 Non-Reasoning', 'category' => 'chat', 'context_window' => 256000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 2.0, 'output_price_per_m' => 10.0, 'sort_order' => 2],
                    ['model_id' => 'grok-4.20-multi-agent-beta', 'name' => 'Grok 4.20 Multi-Agent Beta', 'category' => 'agent', 'context_window' => 256000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 5.0, 'output_price_per_m' => 25.0, 'sort_order' => 3],
                    ['model_id' => 'grok-4-1-fast-reasoning', 'name' => 'Grok 4.1 Fast Reasoning', 'category' => 'reasoning', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.6, 'output_price_per_m' => 2.4, 'sort_order' => 4],
                ],
            ],

            // =============================================
            // 7. Qwen (Alibaba Cloud)
            // =============================================
            [
                'slug' => 'qwen',
                'name' => 'Qwen (Alibaba)',
                'api_base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 7,
                'description' => 'Qwen — LLM dari Alibaba. Qwen 3.5 series, native multimodal agents.',
                'website' => 'https://www.alibabacloud.com/en/solutions/generative-ai',
                'icon' => '🟣',
                'models' => [
                    ['model_id' => 'qwen3.5-397b-a17b', 'name' => 'Qwen 3.5 397B-A17B (MoE)', 'category' => 'reasoning', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.8, 'output_price_per_m' => 2.4, 'sort_order' => 1],
                    ['model_id' => 'qwen3.5-122b-a10b', 'name' => 'Qwen 3.5 122B-A10B', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.2, 'sort_order' => 2],
                    ['model_id' => 'qwen3.5-27b', 'name' => 'Qwen 3.5 27B', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.6, 'sort_order' => 3],
                    ['model_id' => 'qwen3.5-9b', 'name' => 'Qwen 3.5 9B', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.05, 'output_price_per_m' => 0.2, 'sort_order' => 4],
                    ['model_id' => 'qwq-32b', 'name' => 'QwQ 32B (Reasoning)', 'category' => 'reasoning', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.6, 'sort_order' => 5],
                ],
            ],

            // =============================================
            // 8. Groq (Ultra-fast inference)
            // =============================================
            [
                'slug' => 'groq',
                'name' => 'Groq',
                'api_base_url' => 'https://api.groq.com/openai/v1',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 8,
                'description' => 'Groq — inferensi ultra-cepat dengan chip LPU. Hosting open-source models.',
                'website' => 'https://console.groq.com',
                'icon' => '⚡',
                'models' => [
                    ['model_id' => 'meta-llama/llama-4-scout-17b-16e-instruct', 'name' => 'Llama 4 Scout 17B', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.11, 'output_price_per_m' => 0.34, 'sort_order' => 1],
                    ['model_id' => 'meta-llama/llama-3.3-70b-versatile', 'name' => 'Llama 3.3 70B Versatile', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.59, 'output_price_per_m' => 0.79, 'sort_order' => 2],
                    ['model_id' => 'meta-llama/llama-3.1-8b-instant', 'name' => 'Llama 3.1 8B Instant', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.05, 'output_price_per_m' => 0.08, 'sort_order' => 3],
                    ['model_id' => 'qwen/qwen3-32b', 'name' => 'Qwen 3 32B (on Groq)', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.29, 'output_price_per_m' => 0.39, 'sort_order' => 4],
                ],
            ],

            // =============================================
            // 9. Cohere
            // =============================================
            [
                'slug' => 'cohere',
                'name' => 'Cohere',
                'api_base_url' => 'https://api.cohere.com/v2',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 9,
                'description' => 'Cohere — enterprise AI untuk RAG, tool use, dan multilingual.',
                'website' => 'https://dashboard.cohere.com',
                'icon' => '🔴',
                'models' => [
                    ['model_id' => 'command-a-03-2025', 'name' => 'Command A', 'category' => 'chat', 'context_window' => 256000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => true, 'input_price_per_m' => 2.5, 'output_price_per_m' => 10.0, 'sort_order' => 1],
                    ['model_id' => 'command-r-plus-08-2024', 'name' => 'Command R+', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => 4096, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 2.5, 'output_price_per_m' => 10.0, 'sort_order' => 2],
                    ['model_id' => 'command-r-08-2024', 'name' => 'Command R', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => 4096, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.6, 'sort_order' => 3],
                ],
            ],

            // =============================================
            // 10. OpenRouter (Aggregator — semua model)
            // =============================================
            [
                'slug' => 'openrouter',
                'name' => 'OpenRouter',
                'api_base_url' => 'https://openrouter.ai/api/v1',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 10,
                'description' => 'OpenRouter — aggregator 300+ model dari semua provider. 1 API key untuk semua model.',
                'website' => 'https://openrouter.ai',
                'icon' => '🌐',
                'models' => [
                    ['model_id' => 'openai/gpt-5.4', 'name' => 'GPT-5.4 (via OpenRouter)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 5.5, 'output_price_per_m' => 16.5, 'sort_order' => 1],
                    ['model_id' => 'openai/gpt-5.4-mini', 'name' => 'GPT-5.4 Mini (via OpenRouter)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.44, 'output_price_per_m' => 1.76, 'sort_order' => 2],
                    ['model_id' => 'anthropic/claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6 (via OpenRouter)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 3.3, 'output_price_per_m' => 16.5, 'sort_order' => 3],
                    ['model_id' => 'anthropic/claude-opus-4-6', 'name' => 'Claude Opus 4.6 (via OpenRouter)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 16.5, 'output_price_per_m' => 82.5, 'sort_order' => 4],
                    ['model_id' => 'google/gemini-2.5-pro', 'name' => 'Gemini 2.5 Pro (via OpenRouter)', 'category' => 'reasoning', 'context_window' => 1048576, 'max_output_tokens' => 65536, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.38, 'output_price_per_m' => 5.5, 'sort_order' => 5],
                    ['model_id' => 'google/gemini-2.5-flash', 'name' => 'Gemini 2.5 Flash (via OpenRouter)', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => 65536, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.17, 'output_price_per_m' => 0.66, 'sort_order' => 6],
                    ['model_id' => 'deepseek/deepseek-v4-pro', 'name' => 'DeepSeek V4 Pro (via OpenRouter)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => 384000, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.92, 'output_price_per_m' => 3.83, 'sort_order' => 7],
                    ['model_id' => 'deepseek/deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash (via OpenRouter)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 384000, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.16, 'output_price_per_m' => 0.31, 'sort_order' => 8],
                    ['model_id' => 'deepseek/deepseek-chat', 'name' => 'DeepSeek V3.2 — Legacy (via OpenRouter)', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.2, 'sort_order' => 18],
                    ['model_id' => 'deepseek/deepseek-r1', 'name' => 'DeepSeek R1 — Legacy (via OpenRouter)', 'category' => 'reasoning', 'context_window' => 128000, 'max_output_tokens' => 8192, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.6, 'output_price_per_m' => 2.4, 'sort_order' => 19],
                    ['model_id' => 'x-ai/grok-4.20-beta', 'name' => 'Grok 4.20 Beta (via OpenRouter)', 'category' => 'reasoning', 'context_window' => 256000, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 3.3, 'output_price_per_m' => 16.5, 'sort_order' => 9],
                    ['model_id' => 'mistralai/mistral-large-latest', 'name' => 'Mistral Large 3 (via OpenRouter)', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 32768, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => true, 'input_price_per_m' => 2.2, 'output_price_per_m' => 6.6, 'sort_order' => 10],
                    ['model_id' => 'nvidia/nemotron-3-super-120b-a12b', 'name' => 'NVIDIA Nemotron 3 Super (via OpenRouter)', 'category' => 'agent', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.2, 'sort_order' => 11],
                    ['model_id' => 'xiaomi/mimo-v2-pro', 'name' => 'Xiaomi MiMo-V2-Pro (via OpenRouter)', 'category' => 'reasoning', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.2, 'sort_order' => 12],
                    ['model_id' => 'minimax/minimax-m2.7', 'name' => 'MiniMax M2.7 (via OpenRouter)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 1.1, 'output_price_per_m' => 4.4, 'sort_order' => 13],
                    ['model_id' => 'qwen/qwen3.5-122b-a10b', 'name' => 'Qwen 3.5 122B (via OpenRouter)', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 0.33, 'output_price_per_m' => 1.32, 'sort_order' => 14],
                    ['model_id' => 'openrouter/auto', 'name' => 'Auto Router (Best Model)', 'category' => 'chat', 'context_window' => 200000, 'max_output_tokens' => 16384, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 15],
                ],
            ],

            // =============================================
            // 11. ModelsLab
            // =============================================
            [
                'slug' => 'modelslab',
                'name' => 'ModelsLab',
                'api_base_url' => 'https://modelslab.com/api/v7/llm',
                'supports_tools' => false,
                'supports_streaming' => true,
                'sort_order' => 11,
                'description' => 'ModelsLab — aggregator 50,000+ model. Unified API untuk open-source LLMs.',
                'website' => 'https://modelslab.com',
                'icon' => '🧪',
                'models' => [
                    ['model_id' => 'openai-gpt-5.4', 'name' => 'GPT-5.4 (via ModelsLab)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 32768, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 1],
                    ['model_id' => 'openai-gpt-5.4-mini', 'name' => 'GPT-5.4 Mini (via ModelsLab)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 2],
                    ['model_id' => 'anthropic-claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6 (via ModelsLab)', 'category' => 'chat', 'context_window' => 200000, 'max_output_tokens' => 8192, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 3],
                    ['model_id' => 'google-gemini-3.1-flash-lite-preview', 'name' => 'Gemini 3.1 Flash Lite (via ModelsLab)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 4],
                    ['model_id' => 'mistralai-mistral-small-2603', 'name' => 'Mistral Small 4 (via ModelsLab)', 'category' => 'chat', 'context_window' => 256000, 'max_output_tokens' => 16384, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 5],
                    ['model_id' => 'mistralai-mistral-large', 'name' => 'Mistral Large (via ModelsLab)', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 32768, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 6],
                    ['model_id' => 'nvidia-nemotron-3-super-120b-a12b', 'name' => 'NVIDIA Nemotron 3 Super (via ModelsLab)', 'category' => 'agent', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 7],
                    ['model_id' => 'xiaomi-mimo-v2-pro', 'name' => 'Xiaomi MiMo-V2-Pro (via ModelsLab)', 'category' => 'reasoning', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 8],
                    ['model_id' => 'x-ai-grok-4.20-beta', 'name' => 'Grok 4.20 Beta (via ModelsLab)', 'category' => 'reasoning', 'context_window' => 256000, 'max_output_tokens' => 32768, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 9],
                    ['model_id' => 'minimax-minimax-m2.7', 'name' => 'MiniMax M2.7 (via ModelsLab)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => 16384, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 10],
                    ['model_id' => 'qwen-qwen3.5-122b-a10b', 'name' => 'Qwen 3.5 122B (via ModelsLab)', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 16384, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 11],
                    ['model_id' => 'inception-mercury-2', 'name' => 'Inception Mercury 2 (via ModelsLab)', 'category' => 'chat', 'context_window' => 131072, 'max_output_tokens' => 8192, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => null, 'output_price_per_m' => null, 'sort_order' => 12],
                ],
            ],

            // =============================================
            // 12. Sumopod AI (Aggregator, OpenAI-compatible)
            // =============================================
            //
            // Satu kunci untuk Claude, GPT, Gemini, DeepSeek, GLM, Qwen, Kimi,
            // MiniMax, MiMo, dan Hunyuan. Endpoint `/v1/chat/completions` dengan
            // Bearer token — jadi tidak perlu cabang kode baru; `auth_header` dan
            // `auth_prefix` memakai bawaan.
            //
            // HARGA. Diambil dari daftar harga Sumopod per 18 September 2026 dan
            // WAJIB dicek ulang sebelum dipakai untuk menghitung anggaran —
            // katalog gateway berubah sering. Tiga aturan dipakai saat menurunkan
            // satu angka dari daftar yang punya lebih dari satu:
            //
            //   - promo  ("25% Off", coret) -> harga SETELAH diskon (yang dibayar);
            //   - peak / off-peak (DeepSeek) -> harga PEAK, supaya perkiraan biaya
            //     tidak pernah lebih rendah dari tagihan;
            //   - bertingkat menurut panjang konteks (qwen3.7-flash) -> tingkat
            //     TERTINGGI, dengan alasan yang sama.
            //
            // Harga cache (kolom ketiga di daftar Sumopod) TIDAK disimpan: tabel
            // `ai_models` hanya punya harga input & output, dan keduanya murni
            // untuk ditampilkan — belum ada perhitungan biaya yang membacanya.
            //
            // `max_output_tokens` dibiarkan null karena Sumopod tidak menyebutnya.
            // Kemampuan (tools / vision / reasoning) mengikuti keluarga model
            // hulunya, bukan klaim Sumopod; `supports_vision` sengaja dibiarkan
            // false kecuali model itu memang model penglihatan — salah menyalakan
            // berarti galat saat dipakai, salah mematikan hanya berarti tidak
            // ditawarkan.
            [
                'slug' => 'sumopod',
                'name' => 'Sumopod AI',
                'api_base_url' => 'https://ai.sumopod.com/v1',
                'supports_tools' => true,
                'supports_streaming' => true,
                'sort_order' => 12,
                'description' => 'Sumopod AI — gateway OpenAI-compatible, satu kunci untuk Claude, GPT, Gemini, DeepSeek, GLM, Qwen, Kimi, MiniMax, MiMo, dan Hunyuan.',
                'website' => 'https://ai.sumopod.com',
                'icon' => '🛰️',
                'models' => [
                    // Anthropic
                    ['model_id' => 'claude-opus-5', 'name' => 'Claude Opus 5 (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 5.0, 'output_price_per_m' => 25.0, 'sort_order' => 1],
                    ['model_id' => 'claude-opus-4-8', 'name' => 'Claude Opus 4.8 (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 5.0, 'output_price_per_m' => 25.0, 'sort_order' => 2],
                    ['model_id' => 'claude-sonnet-5', 'name' => 'Claude Sonnet 5 (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 2.0, 'output_price_per_m' => 10.0, 'sort_order' => 3],
                    ['model_id' => 'claude-haiku-4-5', 'name' => 'Claude Haiku 4.5 (via Sumopod)', 'category' => 'chat', 'context_window' => 200000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 1.0, 'output_price_per_m' => 5.0, 'sort_order' => 4],

                    // OpenAI
                    ['model_id' => 'gpt-5.6-sol', 'name' => 'GPT-5.6 Sol (via Sumopod)', 'category' => 'reasoning', 'context_window' => 922000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 4.0, 'output_price_per_m' => 20.0, 'sort_order' => 5],
                    ['model_id' => 'gpt-5.6-terra', 'name' => 'GPT-5.6 Terra (via Sumopod)', 'category' => 'reasoning', 'context_window' => 922000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 2.0, 'output_price_per_m' => 12.0, 'sort_order' => 6],
                    ['model_id' => 'gpt-5.6-luna', 'name' => 'GPT-5.6 Luna (via Sumopod)', 'category' => 'chat', 'context_window' => 922000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.2, 'output_price_per_m' => 1.2, 'sort_order' => 7],
                    ['model_id' => 'gpt-5.4', 'name' => 'GPT-5.4 (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1050000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 2.5, 'output_price_per_m' => 15.0, 'sort_order' => 8],
                    ['model_id' => 'gpt-5.4-mini', 'name' => 'GPT-5.4 Mini (via Sumopod)', 'category' => 'chat', 'context_window' => 272000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.75, 'output_price_per_m' => 4.5, 'sort_order' => 9],
                    ['model_id' => 'gpt-5.4-nano', 'name' => 'GPT-5.4 Nano (via Sumopod)', 'category' => 'chat', 'context_window' => 272000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.2, 'output_price_per_m' => 1.25, 'sort_order' => 10],
                    ['model_id' => 'gpt-5', 'name' => 'GPT-5 (via Sumopod)', 'category' => 'reasoning', 'context_window' => 272000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.25, 'output_price_per_m' => 10.0, 'sort_order' => 11],
                    ['model_id' => 'gpt-5-mini', 'name' => 'GPT-5 Mini (via Sumopod)', 'category' => 'chat', 'context_window' => 272000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.25, 'output_price_per_m' => 2.0, 'sort_order' => 12],
                    ['model_id' => 'gpt-5-nano', 'name' => 'GPT-5 Nano (via Sumopod)', 'category' => 'chat', 'context_window' => 272000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.05, 'output_price_per_m' => 0.4, 'sort_order' => 13],
                    ['model_id' => 'gpt-4.1', 'name' => 'GPT-4.1 (via Sumopod)', 'category' => 'chat', 'context_window' => 1047576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 2.0, 'output_price_per_m' => 8.0, 'sort_order' => 14],
                    ['model_id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 Mini (via Sumopod)', 'category' => 'chat', 'context_window' => 1047576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.4, 'output_price_per_m' => 1.6, 'sort_order' => 15],
                    ['model_id' => 'gpt-4.1-nano', 'name' => 'GPT-4.1 Nano (via Sumopod)', 'category' => 'chat', 'context_window' => 1047576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.1, 'output_price_per_m' => 0.4, 'sort_order' => 16],
                    ['model_id' => 'gpt-4o', 'name' => 'GPT-4o (via Sumopod)', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 2.5, 'output_price_per_m' => 10.0, 'sort_order' => 17],
                    ['model_id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini (via Sumopod)', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.6, 'sort_order' => 18],

                    // Google Gemini — id-nya memang berawalan "gemini/" di Sumopod.
                    ['model_id' => 'gemini/gemini-3.1-pro-preview', 'name' => 'Gemini 3.1 Pro Preview (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1048576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 2.0, 'output_price_per_m' => 12.0, 'sort_order' => 19],
                    ['model_id' => 'gemini/gemini-3.8-flash', 'name' => 'Gemini 3.8 Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.75, 'output_price_per_m' => 3.75, 'sort_order' => 20],
                    ['model_id' => 'gemini/gemini-3.7-flash', 'name' => 'Gemini 3.7 Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.75, 'output_price_per_m' => 3.75, 'sort_order' => 21],
                    ['model_id' => 'gemini/gemini-3.5-flash', 'name' => 'Gemini 3.5 Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 1.5, 'output_price_per_m' => 9.0, 'sort_order' => 22],
                    ['model_id' => 'gemini/gemini-3.5-flash-lite', 'name' => 'Gemini 3.5 Flash Lite (via Sumopod)', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.3, 'output_price_per_m' => 2.5, 'sort_order' => 23],
                    ['model_id' => 'gemini/gemini-3-flash-preview', 'name' => 'Gemini 3 Flash Preview (via Sumopod)', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.5, 'output_price_per_m' => 3.0, 'sort_order' => 24],
                    ['model_id' => 'gemini/gemini-3.1-flash-lite', 'name' => 'Gemini 3.1 Flash Lite (via Sumopod)', 'category' => 'chat', 'context_window' => 1048576, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.25, 'output_price_per_m' => 1.5, 'sort_order' => 25],
                    // Embedding: bukan model percakapan — tanpa tool, tanpa vision, keluaran gratis.
                    ['model_id' => 'gemini/gemini-embedding-001', 'name' => 'Gemini Embedding 001 (via Sumopod)', 'category' => 'embedding', 'context_window' => 2048, 'max_output_tokens' => null, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.0, 'sort_order' => 26],

                    // DeepSeek — harga PEAK (lihat catatan aturan harga di atas).
                    ['model_id' => 'deepseek-v4-pro', 'name' => 'DeepSeek V4 Pro (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.32, 'output_price_per_m' => 3.96, 'sort_order' => 27],
                    ['model_id' => 'deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.2, 'sort_order' => 28],
                    ['model_id' => 'deepseek-flash', 'name' => 'DeepSeek Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.2, 'sort_order' => 29],
                    ['model_id' => 'deepseek-v4-flash-vision-exp', 'name' => 'DeepSeek V4 Flash Vision (eksperimen, via Sumopod)', 'category' => 'vision', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.2, 'sort_order' => 30],
                    // Varian ":netra" berjalan di runtime Netra, harganya tetap (tanpa peak).
                    ['model_id' => 'deepseek-v4.1-flash:netra', 'name' => 'DeepSeek V4.1 Flash — Netra (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.0, 'sort_order' => 31],
                    ['model_id' => 'deepseek-v4-flash-0731:netra', 'name' => 'DeepSeek V4 Flash 0731 — Netra (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.2, 'output_price_per_m' => 0.5, 'sort_order' => 32],

                    // Z.ai (GLM)
                    ['model_id' => 'glm-5.2', 'name' => 'GLM-5.2 (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.05, 'output_price_per_m' => 3.3, 'sort_order' => 33],
                    ['model_id' => 'glm-5.1', 'name' => 'GLM-5.1 (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.4, 'output_price_per_m' => 4.4, 'sort_order' => 34],
                    ['model_id' => 'glm-5', 'name' => 'GLM-5 (via Sumopod)', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.6, 'output_price_per_m' => 2.0, 'sort_order' => 35],
                    ['model_id' => 'glm-5.3-flash', 'name' => 'GLM-5.3 Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.07, 'output_price_per_m' => 0.25, 'sort_order' => 36],
                    // Sumopod menulis konteks glm-5-turbo = 0 (tidak disebut); disamakan
                    // dengan saudaranya yang 128K daripada memasang angka karangan.
                    ['model_id' => 'glm-5-turbo', 'name' => 'GLM-5 Turbo (via Sumopod)', 'category' => 'chat', 'context_window' => 128000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 1.2, 'output_price_per_m' => 4.0, 'sort_order' => 37],
                    ['model_id' => 'glm-5v-turbo', 'name' => 'GLM-5V Turbo (via Sumopod)', 'category' => 'vision', 'context_window' => 128000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => true, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 1.2, 'output_price_per_m' => 4.0, 'sort_order' => 38],

                    // Alibaba Qwen
                    ['model_id' => 'qwen3.8-max', 'name' => 'Qwen 3.8 Max (via Sumopod)', 'category' => 'reasoning', 'context_window' => 991808, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.0, 'output_price_per_m' => 3.0, 'sort_order' => 39],
                    ['model_id' => 'qwen3.7-max', 'name' => 'Qwen 3.7 Max (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.25, 'output_price_per_m' => 3.75, 'sort_order' => 40],
                    ['model_id' => 'qwen3.8-flash', 'name' => 'Qwen 3.8 Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.15, 'output_price_per_m' => 0.47, 'sort_order' => 41],
                    ['model_id' => 'qwen3.7-plus', 'name' => 'Qwen 3.7 Plus (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.32, 'output_price_per_m' => 1.28, 'sort_order' => 42],
                    // Harga bertingkat menurut panjang konteks; dipasang tingkat >256K.
                    ['model_id' => 'qwen3.7-flash-2026-07-15', 'name' => 'Qwen 3.7 Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.2, 'output_price_per_m' => 0.8, 'sort_order' => 43],
                    ['model_id' => 'qwen3.6-plus', 'name' => 'Qwen 3.6 Plus (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.5, 'output_price_per_m' => 3.0, 'sort_order' => 44],
                    ['model_id' => 'qwen3.6-flash', 'name' => 'Qwen 3.6 Flash (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.25, 'output_price_per_m' => 1.5, 'sort_order' => 45],

                    // Moonshot (Kimi)
                    ['model_id' => 'kimi-k3', 'name' => 'Kimi K3 (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => true, 'input_price_per_m' => 1.5, 'output_price_per_m' => 7.5, 'sort_order' => 46],
                    ['model_id' => 'kimi-k2.7', 'name' => 'Kimi K2.7 (via Sumopod)', 'category' => 'chat', 'context_window' => 262100, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.95, 'output_price_per_m' => 4.0, 'sort_order' => 47],
                    ['model_id' => 'kimi-k2.6', 'name' => 'Kimi K2.6 (via Sumopod)', 'category' => 'chat', 'context_window' => 262100, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.67, 'output_price_per_m' => 3.39, 'sort_order' => 48],

                    // MiniMax
                    ['model_id' => 'MiniMax-M3', 'name' => 'MiniMax M3 (via Sumopod)', 'category' => 'chat', 'context_window' => 1000000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.3, 'output_price_per_m' => 1.2, 'sort_order' => 49],
                    ['model_id' => 'MiniMax-M2.7-highspeed', 'name' => 'MiniMax M2.7 Highspeed (via Sumopod)', 'category' => 'chat', 'context_window' => 204800, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.03, 'output_price_per_m' => 0.12, 'sort_order' => 50],

                    // Xiaomi MiMo
                    ['model_id' => 'mimo-v2.5-pro', 'name' => 'MiMo V2.5 Pro (via Sumopod)', 'category' => 'reasoning', 'context_window' => 1100000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => true, 'recommended_for_agent' => false, 'input_price_per_m' => 0.43, 'output_price_per_m' => 0.87, 'sort_order' => 51],
                    ['model_id' => 'mimo-v2.5', 'name' => 'MiMo V2.5 (via Sumopod)', 'category' => 'chat', 'context_window' => 1100000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.14, 'output_price_per_m' => 0.28, 'sort_order' => 52],

                    // Tencent Hunyuan
                    ['model_id' => 'hy3', 'name' => 'Hunyuan 3 (via Sumopod)', 'category' => 'chat', 'context_window' => 256000, 'max_output_tokens' => null, 'supports_tools' => true, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.13, 'output_price_per_m' => 0.53, 'sort_order' => 53],
                ],
            ],
        ];

        foreach ($providers as $providerData) {
            $models = $providerData['models'];
            unset($providerData['models']);

            $provider = AiProvider::updateOrCreate(
                ['slug' => $providerData['slug']],
                $providerData
            );

            foreach ($models as $modelData) {
                AiModel::updateOrCreate(
                    ['provider_id' => $provider->id, 'model_id' => $modelData['model_id']],
                    $modelData
                );
            }
        }

        $this->command->info('✅ Seeded '.AiProvider::count().' AI providers with '.AiModel::count().' LLM models');
    }
}
