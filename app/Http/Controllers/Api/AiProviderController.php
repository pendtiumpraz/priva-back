<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiProvider;
use App\Models\AiModel;
use Illuminate\Http\Request;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class AiProviderController extends Controller
{
    /**
     * Resolve org_id — SuperAdmin may not have one, fallback to first org
     */
    private function resolveOrgId(Request $request): ?string
    {
        $orgId = $request->user()->org_id;
        if (!$orgId && $request->user()->role === 'superadmin') {
            $org = Organization::first();
            return $org?->id;
        }
        return $orgId;
    }

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
        $orgId = $this->resolveOrgId($request);

        if (!$orgId) {
            return response()->json(['configs' => (object)[], 'selection' => null]);
        }

        // Get saved provider configs (API keys — masked)
        $configs = DB::table('ai_provider_configs')
            ->where('org_id', $orgId)
            ->get()
            ->map(function ($c) {
                try {
                    $key = decrypt($c->api_key_encrypted);
                    $c->api_key_masked = substr($key, 0, 8) . str_repeat('•', 20) . substr($key, -4);
                } catch (\Exception $e) {
                    $c->api_key_masked = '••••••••••••••••••••';
                }
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
        try {
            $request->validate([
                'provider_id' => 'required|integer',
                'api_key' => 'required|string|min:8',
                'extra_config' => 'nullable|array',
            ]);

            // Verify provider exists
            $provider = AiProvider::find($request->provider_id);
            if (!$provider) {
                return response()->json(['message' => 'Provider tidak ditemukan. Pastikan migration dan seeder sudah dijalankan.'], 404);
            }

            $orgId = $this->resolveOrgId($request);

            if (!$orgId) {
                return response()->json(['message' => 'Tidak ada organisasi. Buat organisasi terlebih dahulu.'], 400);
            }

            // Check if exists
            $existing = DB::table('ai_provider_configs')
                ->where('org_id', $orgId)
                ->where('provider_id', $request->provider_id)
                ->first();

            if ($existing) {
                DB::table('ai_provider_configs')
                    ->where('id', $existing->id)
                    ->update([
                        'api_key_encrypted' => encrypt($request->api_key),
                        'extra_config' => $request->extra_config ? json_encode($request->extra_config) : null,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('ai_provider_configs')->insert([
                    'org_id' => $orgId,
                    'provider_id' => $request->provider_id,
                    'api_key_encrypted' => encrypt($request->api_key),
                    'extra_config' => $request->extra_config ? json_encode($request->extra_config) : null,
                    'is_verified' => false,
                    'verified_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            try {
                \App\Models\AuditLog::log('ai_provider', (string)$request->provider_id, 'api_key_saved', [
                    'provider_id' => $request->provider_id,
                ], 'manual');
            } catch (\Exception $e) {
                // Don't fail the save if audit log fails
            }

            return response()->json(['message' => 'API key saved successfully']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e; // Let Laravel handle validation errors normally
        } catch (\Exception $e) {
            \Log::error('AI Provider saveApiKey error: ' . $e->getMessage() . ' | File: ' . $e->getFile() . ':' . $e->getLine());
            return response()->json([
                'message' => 'Error: ' . $e->getMessage(),
                'file' => basename($e->getFile()) . ':' . $e->getLine(),
            ], 500);
        }
    }

    /**
     * Test API key for a provider
     */
    public function testConnection(Request $request)
    {
        $request->validate([
            'provider_id' => 'required|integer',
        ]);

        $provider = AiProvider::findOrFail($request->provider_id);
        $orgId = $this->resolveOrgId($request);

        // Get API key: from request body or from saved config
        $apiKey = $request->api_key;
        if (!$apiKey || $apiKey === 'saved') {
            // Load saved key
            $config = DB::table('ai_provider_configs')
                ->where('org_id', $orgId)
                ->where('provider_id', $request->provider_id)
                ->first();
            if (!$config) {
                return response()->json(['success' => false, 'message' => 'API key belum disimpan'], 400);
            }
            try {
                $apiKey = decrypt($config->api_key_encrypted);
            } catch (\Exception $e) {
                return response()->json(['success' => false, 'message' => 'Gagal decrypt API key'], 500);
            }
        }

        try {
            // Try a minimal chat completion to verify
            $headers = ['Content-Type' => 'application/json'];
            if ($provider->auth_prefix) {
                $headers[$provider->auth_header] = $provider->auth_prefix . ' ' . $apiKey;
            } else {
                $headers[$provider->auth_header] = $apiKey;
            }

            $baseUrl = rtrim($provider->api_base_url, '/');

            $response = Http::timeout(15)
                ->withoutVerifying()
                ->withHeaders($headers)
                ->post($baseUrl . '/chat/completions', [
                    'model' => $provider->models()->where('is_active', true)->first()?->model_id ?? 'gpt-4o',
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                    'max_tokens' => 5,
                ]);

            if ($response->successful()) {
                // Mark as verified
                DB::table('ai_provider_configs')
                    ->where('org_id', $orgId)
                    ->where('provider_id', $request->provider_id)
                    ->update(['is_verified' => true, 'verified_at' => now()]);

                return response()->json([
                    'success' => true,
                    'message' => "Berhasil terhubung ke {$provider->name} ✓",
                ]);
            } else {
                $errBody = $response->json();
                $errMsg = $errBody['error']['message'] ?? $response->body();
                return response()->json([
                    'success' => false,
                    'message' => "Gagal: " . substr($errMsg, 0, 200),
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
            'provider_id' => 'required|integer',
            'model_id' => 'required|integer',
        ]);

        $orgId = $this->resolveOrgId($request);
        if (!$orgId) {
            return response()->json(['error' => 'Tidak ada organisasi.'], 400);
        }

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

        // Check if exists
        $existing = DB::table('ai_active_selections')->where('org_id', $orgId)->first();

        if ($existing) {
            DB::table('ai_active_selections')
                ->where('org_id', $orgId)
                ->update(array_merge($fields, ['updated_at' => now()]));
        } else {
            DB::table('ai_active_selections')->insert(
                array_merge(['org_id' => $orgId], $fields, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        try {
            \App\Models\AuditLog::log('ai_provider', (string)$request->provider_id, "active_{$mode}_model_set", [
                'provider' => $model->provider->name,
                'model' => $model->name,
            ], 'manual');
        } catch (\Exception $e) {
            // Don't fail if audit log fails
        }

        return response()->json([
            'message' => "Model {$model->name} aktif untuk mode {$mode}",
            'mode' => $mode,
            'provider' => $model->provider->name ?? '',
            'model' => $model->name,
        ]);
    }

    /**
     * Remove API key for a provider
     */
    public function removeApiKey(Request $request)
    {
        $request->validate(['provider_id' => 'required|integer']);

        $orgId = $this->resolveOrgId($request);

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
    public static function getActiveConfig(string $orgId, string $mode = 'chat'): ?array
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
