<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AiModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AiProviderController extends Controller
{
    /**
     * List all providers with their models
     */
    public function index()
    {
        $providers = AiProvider::where('is_active', true)
            ->with(['models' => function ($q) {
                $q->where('is_active', true)->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $providers]);
    }

    /**
     * Get per-tenant AI configuration (saved API keys + active selections)
     */
    public function getConfig(Request $request)
    {
        $orgId = $request->user()->org_id;

        // Get saved provider configs (API keys — masked)
        $configs = DB::table('ai_provider_configs')
            ->where('org_id', $orgId)
            ->get()
            ->map(function ($c) {
                $key = decrypt($c->api_key_encrypted);
                $c->api_key_masked = substr($key, 0, 8) . str_repeat('•', 20) . substr($key, -4);
                unset($c->api_key_encrypted);
                return $c;
            })
            ->keyBy('provider_id');

        // Get active selections
        $selection = DB::table('ai_active_selections')->where('org_id', $orgId)->first();

        return response()->json([
            'configs' => $configs,
            'selection' => $selection,
        ]);
    }

    /**
     * Save/update API key for a specific provider
     */
    public function saveApiKey(Request $request)
    {
        $request->validate([
            'provider_id' => 'required|exists:ai_providers,id',
            'api_key' => 'required|string|min:8',
            'extra_config' => 'nullable|array',
        ]);

        $orgId = $request->user()->org_id;

        DB::table('ai_provider_configs')->updateOrInsert(
            ['org_id' => $orgId, 'provider_id' => $request->provider_id],
            [
                'api_key_encrypted' => encrypt($request->api_key),
                'extra_config' => $request->extra_config ? json_encode($request->extra_config) : null,
                'updated_at' => now(),
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
            ]
        );

        \App\Models\AuditLog::log('ai_provider', $request->provider_id, 'api_key_saved', [
            'provider_id' => $request->provider_id,
        ], 'manual');

        return response()->json(['message' => 'API key saved successfully']);
    }

    /**
     * Test API key for a provider (try a minimal request)
     */
    public function testConnection(Request $request)
    {
        $request->validate([
            'provider_id' => 'required|exists:ai_providers,id',
            'api_key' => 'required|string',
        ]);

        $provider = AiProvider::findOrFail($request->provider_id);
        $apiKey = $request->api_key;

        try {
            // Try listing models to verify the key works
            $url = rtrim($provider->api_base_url, '/') . '/models';

            $headers = [];
            if ($provider->auth_prefix) {
                $headers[] = "{$provider->auth_header}: {$provider->auth_prefix} {$apiKey}";
            } else {
                $headers[] = "{$provider->auth_header}: {$apiKey}";
            }
            $headers[] = 'Content-Type: application/json';

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                // Mark as verified
                DB::table('ai_provider_configs')
                    ->where('org_id', $request->user()->org_id)
                    ->where('provider_id', $request->provider_id)
                    ->update(['is_verified' => true, 'verified_at' => now()]);

                return response()->json([
                    'success' => true,
                    'message' => "Berhasil terhubung ke {$provider->name}",
                    'http_code' => $httpCode,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => "Gagal koneksi ke {$provider->name} (HTTP {$httpCode})",
                    'http_code' => $httpCode,
                ]);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Error: {$e->getMessage()}",
            ]);
        }
    }

    /**
     * Set active LLM selection (separate for chat vs agent)
     */
    public function setActiveModel(Request $request)
    {
        $request->validate([
            'mode' => 'required|in:chat,agent',
            'provider_id' => 'required|exists:ai_providers,id',
            'model_id' => 'required|exists:ai_models,id',
        ]);

        $orgId = $request->user()->org_id;
        $mode = $request->mode;

        // Verify the model belongs to the provider
        $model = AiModel::where('id', $request->model_id)
            ->where('provider_id', $request->provider_id)
            ->firstOrFail();

        // Verify tenant has API key for this provider
        $hasKey = DB::table('ai_provider_configs')
            ->where('org_id', $orgId)
            ->where('provider_id', $request->provider_id)
            ->exists();

        if (!$hasKey) {
            return response()->json(['error' => 'API key belum disimpan untuk provider ini'], 400);
        }

        $fields = $mode === 'chat'
            ? ['chat_provider_id' => $request->provider_id, 'chat_model_id' => $request->model_id]
            : ['agent_provider_id' => $request->provider_id, 'agent_model_id' => $request->model_id];

        DB::table('ai_active_selections')->updateOrInsert(
            ['org_id' => $orgId],
            array_merge($fields, ['updated_at' => now(), 'created_at' => DB::raw('COALESCE(created_at, NOW())')])
        );

        \App\Models\AuditLog::log('ai_provider', $request->provider_id, "active_{$mode}_model_set", [
            'provider' => $model->provider->name,
            'model' => $model->name,
        ], 'manual');

        return response()->json([
            'message' => "Model {$model->name} aktif untuk mode {$mode}",
            'mode' => $mode,
            'provider' => $model->provider->name,
            'model' => $model->name,
        ]);
    }

    /**
     * Remove API key for a provider
     */
    public function removeApiKey(Request $request)
    {
        $request->validate(['provider_id' => 'required|exists:ai_providers,id']);

        $orgId = $request->user()->org_id;

        // Clear active selections if this provider was active
        $selection = DB::table('ai_active_selections')->where('org_id', $orgId)->first();
        if ($selection) {
            $updates = [];
            if ($selection->chat_provider_id == $request->provider_id) {
                $updates['chat_provider_id'] = null;
                $updates['chat_model_id'] = null;
            }
            if ($selection->agent_provider_id == $request->provider_id) {
                $updates['agent_provider_id'] = null;
                $updates['agent_model_id'] = null;
            }
            if (!empty($updates)) {
                DB::table('ai_active_selections')->where('org_id', $orgId)->update($updates);
            }
        }

        DB::table('ai_provider_configs')
            ->where('org_id', $orgId)
            ->where('provider_id', $request->provider_id)
            ->delete();

        return response()->json(['message' => 'API key removed']);
    }

    /**
     * Get the active model config for internal use (returns decrypted key)
     * Used by AiService internally — not exposed as API route
     */
    public static function getActiveConfig(int $orgId, string $mode = 'chat'): ?array
    {
        $selection = DB::table('ai_active_selections')->where('org_id', $orgId)->first();
        if (!$selection) return null;

        $providerId = $mode === 'agent' ? $selection->agent_provider_id : $selection->chat_provider_id;
        $modelId = $mode === 'agent' ? $selection->agent_model_id : $selection->chat_model_id;

        if (!$providerId || !$modelId) return null;

        $provider = AiProvider::find($providerId);
        $model = AiModel::find($modelId);
        $config = DB::table('ai_provider_configs')
            ->where('org_id', $orgId)
            ->where('provider_id', $providerId)
            ->first();

        if (!$provider || !$model || !$config) return null;

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key' => decrypt($config->api_key_encrypted),
            'base_url' => $provider->api_base_url,
            'auth_header' => $provider->auth_header,
            'auth_prefix' => $provider->auth_prefix,
        ];
    }
}
