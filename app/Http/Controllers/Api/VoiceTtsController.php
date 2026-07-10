<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Models\AiProvider;
use App\Models\AiModel;

/**
 * Voice TTS Controller
 * 
 * Synthesizes text to speech using the active voice provider.
 * Falls back to signaling "use browser TTS" if no provider is configured.
 * 
 * Supported providers:
 * - OpenAI TTS (tts-1, tts-1-hd, gpt-4o-mini-tts)
 * - ElevenLabs (eleven_v3, eleven_multilingual_v2, eleven_flash_v2_5)
 * - Google Cloud TTS (WaveNet, Neural2, Standard)
 * - Azure Cognitive Services (GadisNeural, ArdiNeural)
 * - MiniMax TTS (speech-02-turbo, speech-02-hd)
 * - Gemini TTS (gemini-2.5-flash/pro-preview-tts) — returns PCM, wrapped to WAV
 */
class VoiceTtsController extends Controller
{
    /**
     * POST /api/voice/synthesize
     * 
     * Body: { text: string, gender: 'male'|'female' }
     * Returns: { audio_base64: string, content_type: string } or { fallback: true }
     */
    public function synthesize(Request $request)
    {
        $request->validate([
            'text' => 'required|string|max:5000',
            'gender' => 'required|in:male,female',
        ]);

        $text = $request->text;
        $gender = $request->gender;

        // Resolve org
        $orgId = $request->user()->org_id;

        // Get active voice config
        $config = $this->getVoiceConfig($orgId);

        if (!$config) {
            // No voice provider set — frontend should use browser TTS
            return response()->json(['fallback' => true]);
        }

        $providerSlug = $config['provider']->slug;
        $modelId = $config['model']->model_id;
        $apiKey = $config['api_key'];

        try {
            $result = match ($providerSlug) {
                'openai-tts' => $this->synthesizeOpenAI($text, $modelId, $gender, $apiKey),
                'elevenlabs' => $this->synthesizeElevenLabs($text, $modelId, $gender, $apiKey),
                'google-tts' => $this->synthesizeGoogle($text, $modelId, $gender, $apiKey),
                'azure-tts' => $this->synthesizeAzure($text, $modelId, $gender, $apiKey),
                'minimax-tts' => $this->synthesizeMiniMax($text, $modelId, $gender, $apiKey),
                'gemini-tts' => $this->synthesizeGemini($text, $modelId, $gender, $apiKey),
                default => null,
            };

            if (!$result) {
                return response()->json(['fallback' => true]);
            }

            return response()->json([
                'audio_base64' => $result['audio_base64'],
                'content_type' => $result['content_type'],
            ]);

        } catch (\Exception $e) {
            \Log::error('Voice TTS error: ' . $e->getMessage());
            return response()->json(['fallback' => true, 'error' => $e->getMessage()], 200);
        }
    }

    /**
     * OpenAI TTS — POST /v1/audio/speech
     */
    private function synthesizeOpenAI(string $text, string $model, string $gender, string $apiKey): array
    {
        // Voice mapping by gender
        $voice = $gender === 'female' ? 'nova' : 'onyx';

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://api.openai.com/v1/audio/speech', [
            'model' => $model,
            'input' => $text,
            'voice' => $voice,
            'response_format' => 'mp3',
        ]);

        if (!$response->successful()) {
            throw new \Exception('OpenAI TTS error: ' . $response->body());
        }

        return [
            'audio_base64' => base64_encode($response->body()),
            'content_type' => 'audio/mpeg',
        ];
    }

    /**
     * ElevenLabs TTS — POST /v1/text-to-speech/{voice_id}
     */
    private function synthesizeElevenLabs(string $text, string $model, string $gender, string $apiKey): array
    {
        // Use premade ElevenLabs voices
        // Rachel = female, Adam = male (premade voice IDs)
        $voiceId = $gender === 'female'
            ? '21m00Tcm4TlvDq8ikWAM' // Rachel
            : 'pNInz6obpgDQGcFmaJgB'; // Adam

        $response = Http::withHeaders([
            'xi-api-key' => $apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(30)->post("https://api.elevenlabs.io/v1/text-to-speech/{$voiceId}", [
            'text' => $text,
            'model_id' => $model,
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('ElevenLabs TTS error: ' . $response->body());
        }

        return [
            'audio_base64' => base64_encode($response->body()),
            'content_type' => 'audio/mpeg',
        ];
    }

    /**
     * Google Cloud TTS — POST /v1/text:synthesize
     */
    private function synthesizeGoogle(string $text, string $model, string $gender, string $apiKey): array
    {
        // model = voice name (e.g. id-ID-Wavenet-A for female, id-ID-Wavenet-B for male)
        $voiceName = $model;

        // Determine ssml gender from voice name
        $ssmlGender = $gender === 'female' ? 'FEMALE' : 'MALE';

        $response = Http::timeout(30)->post("https://texttospeech.googleapis.com/v1/text:synthesize?key={$apiKey}", [
            'input' => ['text' => $text],
            'voice' => [
                'languageCode' => 'id-ID',
                'name' => $voiceName,
                'ssmlGender' => $ssmlGender,
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate' => 1.0,
                'pitch' => 0,
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('Google TTS error: ' . $response->body());
        }

        $data = $response->json();
        
        return [
            'audio_base64' => $data['audioContent'],
            'content_type' => 'audio/mpeg',
        ];
    }

    /**
     * Azure Cognitive Services TTS — POST /cognitiveservices/v1
     * Uses SSML format
     */
    private function synthesizeAzure(string $text, string $model, string $gender, string $apiKey): array
    {
        // model = voice name (e.g. id-ID-GadisNeural or id-ID-ArdiNeural)
        $voiceName = $model;

        $ssml = '<speak xmlns="http://www.w3.org/2001/10/synthesis" version="1.0" xml:lang="id-ID">'
            . '<voice name="' . $voiceName . '">' . htmlspecialchars($text) . '</voice>'
            . '</speak>';

        $response = Http::withHeaders([
            'Ocp-Apim-Subscription-Key' => $apiKey,
            'Content-Type' => 'application/ssml+xml',
            'X-Microsoft-OutputFormat' => 'audio-16khz-128kbitrate-mono-mp3',
        ])->timeout(30)->withBody($ssml, 'application/ssml+xml')
          ->post('https://southeastasia.tts.speech.microsoft.com/cognitiveservices/v1');

        if (!$response->successful()) {
            throw new \Exception('Azure TTS error: ' . $response->body());
        }

        return [
            'audio_base64' => base64_encode($response->body()),
            'content_type' => 'audio/mpeg',
        ];
    }

    /**
     * MiniMax TTS — POST /v1/t2a_v2
     */
    private function synthesizeMiniMax(string $text, string $model, string $gender, string $apiKey): array
    {
        // MiniMax preset voice IDs
        // female: Wise_Woman, male: young_sichuan_man  
        $voiceId = $gender === 'female' ? 'Wise_Woman' : 'young_sichuan_man';

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(30)->post('https://api.minimax.io/v1/t2a_v2', [
            'model' => $model,
            'text' => $text,
            'stream' => false,
            'voice_setting' => [
                'voice_id' => $voiceId,
                'speed' => 1.0,
                'vol' => 1.0,
                'pitch' => 0,
            ],
            'audio_setting' => [
                'sample_rate' => 32000,
                'bitrate' => 128000,
                'format' => 'mp3',
                'channel' => 1,
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('MiniMax TTS error: ' . $response->body());
        }

        $data = $response->json();

        // MiniMax returns audio as hex or url
        if (isset($data['data']['audio'])) {
            // hex encoded audio
            $audioBytes = hex2bin($data['data']['audio']);
            return [
                'audio_base64' => base64_encode($audioBytes),
                'content_type' => 'audio/mpeg',
            ];
        } elseif (isset($data['audio_file'])) {
            // URL — download it
            $audioResponse = Http::timeout(15)->get($data['audio_file']);
            return [
                'audio_base64' => base64_encode($audioResponse->body()),
                'content_type' => 'audio/mpeg',
            ];
        }

        throw new \Exception('MiniMax TTS: no audio in response');
    }

    /**
     * Gemini TTS — POST /v1beta/models/{model}:generateContent
     *
     * Gemini returns RAW PCM audio (signed 16-bit little-endian, mono, 24kHz)
     * as base64 inlineData — NOT MP3. The browser's <Audio> cannot play raw PCM,
     * so we wrap it in a WAV container before returning. Sample rate is parsed
     * from the response mimeType (e.g. "audio/L16;rate=24000"), default 24000.
     *
     * Voice: prebuilt names — female → "Kore", male → "Puck". model = model_id.
     */
    private function synthesizeGemini(string $text, string $model, string $gender, string $apiKey): array
    {
        $voice = $gender === 'female' ? 'Kore' : 'Puck';

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $response = Http::timeout(30)->post($url, [
            'contents' => [
                ['parts' => [['text' => $text]]],
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'prebuiltVoiceConfig' => ['voiceName' => $voice],
                    ],
                ],
            ],
        ]);

        if (!$response->successful()) {
            throw new \Exception('Gemini TTS error: ' . $response->body());
        }

        $data = $response->json();
        $inline = $data['candidates'][0]['content']['parts'][0]['inlineData'] ?? null;

        if (!$inline || empty($inline['data'])) {
            throw new \Exception('Gemini TTS: no audio in response');
        }

        $pcm = base64_decode($inline['data']);

        // Parse sample rate from mimeType like "audio/L16;rate=24000".
        $rate = 24000;
        if (!empty($inline['mimeType']) && preg_match('/rate=(\d+)/', $inline['mimeType'], $m)) {
            $rate = (int) $m[1];
        }

        $wav = $this->pcmToWav($pcm, $rate);

        return [
            'audio_base64' => base64_encode($wav),
            'content_type' => 'audio/wav',
        ];
    }

    /**
     * Wrap raw PCM (signed 16-bit LE, mono) into a WAV container by prepending
     * the 44-byte RIFF/WAVE header. Needed because Gemini TTS returns bare PCM
     * that the browser cannot play directly.
     */
    private function pcmToWav(string $pcm, int $sampleRate = 24000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $dataLen    = strlen($pcm);
        $byteRate   = (int) ($sampleRate * $channels * $bitsPerSample / 8);
        $blockAlign = (int) ($channels * $bitsPerSample / 8);

        $header = pack(
            'a4Va4a4VvvVVvva4V',
            'RIFF',            // ChunkID
            36 + $dataLen,     // ChunkSize
            'WAVE',            // Format
            'fmt ',            // Subchunk1ID
            16,                // Subchunk1Size (PCM)
            1,                 // AudioFormat (1 = PCM)
            $channels,         // NumChannels
            $sampleRate,       // SampleRate
            $byteRate,         // ByteRate
            $blockAlign,       // BlockAlign
            $bitsPerSample,    // BitsPerSample
            'data',            // Subchunk2ID
            $dataLen           // Subchunk2Size
        );

        return $header . $pcm;
    }

    /**
     * Get voice config from ai_active_selections
     */
    private function getVoiceConfig(?string $orgId): ?array
    {
        // Try org-specific first, then global
        $config = $this->loadVoiceConfigForOrg($orgId);
        if ($config) return $config;

        if ($orgId) {
            $config = $this->loadVoiceConfigForOrg(null);
            if ($config) return $config;
        }

        return null;
    }

    private function loadVoiceConfigForOrg(?string $orgId): ?array
    {
        $selQuery = DB::table('ai_active_selections');
        if ($orgId) {
            $selQuery->where('org_id', $orgId);
        } else {
            $selQuery->whereNull('org_id');
        }
        $selection = $selQuery->first();

        if (!$selection) return null;
        if (!isset($selection->voice_provider_id) || !$selection->voice_provider_id) return null;
        if (!isset($selection->voice_model_id) || !$selection->voice_model_id) return null;

        $provider = AiProvider::find($selection->voice_provider_id);
        $model = AiModel::find($selection->voice_model_id);

        if (!$provider || !$model) return null;

        // Resolve API key for this voice provider (org-specific, then global).
        // Gemini TTS uses the SAME Google AI (Gemini) key as the chat 'google'
        // provider, so if the gemini-tts provider has no key of its own, fall
        // back to the 'google' provider's key — no need to paste it twice.
        $apiKey = $this->resolveProviderKey($provider->id, $orgId);
        if (!$apiKey && $provider->slug === 'gemini-tts') {
            $google = AiProvider::where('slug', 'google')->first();
            if ($google) {
                $apiKey = $this->resolveProviderKey($google->id, $orgId);
            }
        }

        if (!$apiKey) return null;

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key' => $apiKey,
        ];
    }

    /**
     * Resolve+decrypt a provider's stored API key: org-specific first, then the
     * global (null org) key. Returns null if none is configured.
     */
    private function resolveProviderKey(string $providerId, ?string $orgId): ?string
    {
        $query = DB::table('ai_provider_configs')->where('provider_id', $providerId);
        if ($orgId) {
            $query->where('org_id', $orgId);
        } else {
            $query->whereNull('org_id');
        }
        $config = $query->first();

        if (!$config && $orgId) {
            $config = DB::table('ai_provider_configs')
                ->where('provider_id', $providerId)
                ->whereNull('org_id')
                ->first();
        }

        if (!$config || empty($config->api_key)) return null;

        try {
            return decrypt($config->api_key);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
