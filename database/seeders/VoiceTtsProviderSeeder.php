<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\AiProvider;
use App\Models\AiModel;

/**
 * Voice TTS Provider Seeder
 * 
 * Seeds AI TTS (Text-to-Speech) providers for voice synthesis.
 * These providers are used by the 3D Avatar (Priva) for speaking.
 * 
 * Models use category = 'voice' to differentiate from LLM models.
 * 
 * Research Date: April 2026
 */
class VoiceTtsProviderSeeder extends Seeder
{
    public function run(): void
    {
        $voiceProviders = [
            // =============================================
            // 1. ElevenLabs — Premium quality, emotional voices
            // Free: 10,000 chars/month
            // =============================================
            [
                'slug' => 'elevenlabs',
                'name' => 'ElevenLabs',
                'api_base_url' => 'https://api.elevenlabs.io/v1',
                'auth_header' => 'xi-api-key',
                'auth_prefix' => '',
                'supports_tools' => false,
                'supports_streaming' => true,
                'sort_order' => 20,
                'description' => 'ElevenLabs — TTS premium dengan suara paling natural & ekspresif. Free: 10K chars/bulan.',
                'website' => 'https://elevenlabs.io',
                'icon' => '🎙️',
                'models' => [
                    // eleven_v3 — latest, best quality
                    ['model_id' => 'eleven_v3', 'name' => 'ElevenLabs v3 (Best Quality)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 300.0, 'output_price_per_m' => 0, 'sort_order' => 1],
                    // eleven_multilingual_v2 — stable, high quality
                    ['model_id' => 'eleven_multilingual_v2', 'name' => 'ElevenLabs Multilingual v2', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 300.0, 'output_price_per_m' => 0, 'sort_order' => 2],
                    // eleven_flash_v2_5 — ultra low latency
                    ['model_id' => 'eleven_flash_v2_5', 'name' => 'ElevenLabs Flash v2.5 (Low Latency)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 150.0, 'output_price_per_m' => 0, 'sort_order' => 3],
                ],
            ],

            // =============================================
            // 2. OpenAI TTS — Reuse existing OpenAI API key
            // Free: $5 trial credits for new accounts
            // =============================================
            [
                'slug' => 'openai-tts',
                'name' => 'OpenAI TTS',
                'api_base_url' => 'https://api.openai.com/v1',
                'supports_tools' => false,
                'supports_streaming' => true,
                'sort_order' => 21,
                'description' => 'OpenAI TTS — suara AI berkualitas tinggi. Bisa reuse API key OpenAI yang sudah ada. Free: $5 trial.',
                'website' => 'https://platform.openai.com',
                'icon' => '🔊',
                'models' => [
                    // gpt-4o-mini-tts — newest, steerable with instructions
                    ['model_id' => 'gpt-4o-mini-tts', 'name' => 'GPT-4o Mini TTS (Steerable)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 0.6, 'output_price_per_m' => 12.0, 'sort_order' => 1],
                    // tts-1 — standard, fast
                    ['model_id' => 'tts-1', 'name' => 'TTS-1 (Standard)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 15.0, 'output_price_per_m' => 0, 'sort_order' => 2],
                    // tts-1-hd — high definition
                    ['model_id' => 'tts-1-hd', 'name' => 'TTS-1 HD (Premium)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 30.0, 'output_price_per_m' => 0, 'sort_order' => 3],
                ],
            ],

            // =============================================
            // 3. Google Cloud TTS — Murah, Indonesian native
            // Free: 1M chars/month (Standard), 500K (WaveNet)
            // =============================================
            [
                'slug' => 'google-tts',
                'name' => 'Google Cloud TTS',
                'api_base_url' => 'https://texttospeech.googleapis.com',
                'supports_tools' => false,
                'supports_streaming' => false,
                'sort_order' => 22,
                'description' => 'Google Cloud TTS — suara neural berkualitas, harga murah. Free: 1M chars/bulan (Standard).',
                'website' => 'https://cloud.google.com/text-to-speech',
                'icon' => '🔵',
                'models' => [
                    // WaveNet — high quality neural
                    ['model_id' => 'id-ID-Wavenet-A', 'name' => 'Google WaveNet Female (id-ID)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 16.0, 'output_price_per_m' => 0, 'sort_order' => 1],
                    ['model_id' => 'id-ID-Wavenet-B', 'name' => 'Google WaveNet Male (id-ID)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 16.0, 'output_price_per_m' => 0, 'sort_order' => 2],
                    // Neural2 — premium quality
                    ['model_id' => 'id-ID-Neural2-A', 'name' => 'Google Neural2 Female (id-ID)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 16.0, 'output_price_per_m' => 0, 'sort_order' => 3],
                    ['model_id' => 'id-ID-Neural2-B', 'name' => 'Google Neural2 Male (id-ID)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 16.0, 'output_price_per_m' => 0, 'sort_order' => 4],
                    // Standard — cheapest / free tier
                    ['model_id' => 'id-ID-Standard-A', 'name' => 'Google Standard Female (Free Tier)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 4.0, 'output_price_per_m' => 0, 'sort_order' => 5],
                    ['model_id' => 'id-ID-Standard-B', 'name' => 'Google Standard Male (Free Tier)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 4.0, 'output_price_per_m' => 0, 'sort_order' => 6],
                ],
            ],

            // =============================================
            // 4. Azure Cognitive Services TTS
            // Free: 500K chars/month neural voices
            // =============================================
            [
                'slug' => 'azure-tts',
                'name' => 'Azure Speech (TTS)',
                'api_base_url' => 'https://southeastasia.tts.speech.microsoft.com',
                'auth_header' => 'Ocp-Apim-Subscription-Key',
                'auth_prefix' => '',
                'supports_tools' => false,
                'supports_streaming' => true,
                'sort_order' => 23,
                'description' => 'Azure Cognitive Services TTS — enterprise-grade, nama Indonesia (Gadis & Ardi). Free: 500K chars/bulan.',
                'website' => 'https://azure.microsoft.com/en-us/products/ai-services/text-to-speech',
                'icon' => '🟣',
                'models' => [
                    // Gadis — Female Indonesian
                    ['model_id' => 'id-ID-GadisNeural', 'name' => 'Azure Gadis (Female id-ID)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 16.0, 'output_price_per_m' => 0, 'sort_order' => 1],
                    // Ardi — Male Indonesian
                    ['model_id' => 'id-ID-ArdiNeural', 'name' => 'Azure Ardi (Male id-ID)', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 16.0, 'output_price_per_m' => 0, 'sort_order' => 2],
                ],
            ],

            // =============================================
            // 5. MiniMax TTS — High quality, voice cloning
            // Free: 10,000 credits/month
            // =============================================
            [
                'slug' => 'minimax-tts',
                'name' => 'MiniMax TTS',
                'api_base_url' => 'https://api.minimax.io/v1',
                'supports_tools' => false,
                'supports_streaming' => true,
                'sort_order' => 24,
                'description' => 'MiniMax TTS — suara AI natural dengan voice cloning. Free: 10K credits/bulan. Support 30+ bahasa.',
                'website' => 'https://www.minimax.io',
                'icon' => '🎵',
                'models' => [
                    // speech-02-turbo — fast, lower latency
                    ['model_id' => 'speech-02-turbo', 'name' => 'MiniMax Speech Turbo', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 60.0, 'output_price_per_m' => 0, 'sort_order' => 1],
                    // speech-02-hd — highest quality
                    ['model_id' => 'speech-02-hd', 'name' => 'MiniMax Speech HD', 'category' => 'voice', 'context_window' => 0, 'max_output_tokens' => 0, 'supports_tools' => false, 'supports_vision' => false, 'is_reasoning' => false, 'recommended_for_agent' => false, 'input_price_per_m' => 100.0, 'output_price_per_m' => 0, 'sort_order' => 2],
                ],
            ],
        ];

        foreach ($voiceProviders as $providerData) {
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

        $voiceCount = AiModel::where('category', 'voice')->count();
        $this->command->info("✅ Seeded 5 Voice TTS providers with {$voiceCount} voice models");
    }
}
