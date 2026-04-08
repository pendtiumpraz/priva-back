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
     * Get org_id from user
     */
    private function resolveOrgId(Request $request): ?string
    {
        return $request->user()->org_id;
    }

    /**
     * List all providers with their models (public — active only)
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

    // ==================== ADMIN CRUD: PROVIDERS ====================

    /**
     * Admin: list all providers (including inactive, with trashed count)
     */
    public function adminIndex()
    {
        $providers = AiProvider::withCount(['models', 'models as trashed_models_count' => function ($q) {
                $q->onlyTrashed();
            }])
            ->with(['models' => function ($q) {
                $q->orderBy('sort_order');
            }])
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $providers]);
    }

    /**
     * Admin: create a new provider
     */
    public function storeProvider(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:50|unique:ai_providers,slug',
            'api_base_url' => 'required|url|max:500',
            'auth_header' => 'nullable|string|max:100',
            'auth_prefix' => 'nullable|string|max:50',
            'supports_tools' => 'boolean',
            'supports_streaming' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:500',
            'icon' => 'nullable|string|max:100',
        ]);

        $maxSort = AiProvider::max('sort_order') ?? 0;

        $provider = AiProvider::create(array_merge($request->only([
            'name', 'slug', 'api_base_url', 'auth_header', 'auth_prefix',
            'supports_tools', 'supports_streaming', 'is_active',
            'description', 'website', 'icon',
        ]), [
            'auth_header' => $request->auth_header ?? 'Authorization',
            'auth_prefix' => $request->auth_prefix ?? 'Bearer',
            'sort_order' => $maxSort + 1,
        ]));

        return response()->json(['message' => 'Provider berhasil dibuat.', 'data' => $provider], 201);
    }

    /**
     * Admin: update a provider
     */
    public function updateProvider(Request $request, int $id)
    {
        $provider = AiProvider::findOrFail($id);
        $request->validate([
            'name' => 'string|max:255',
            'slug' => 'string|max:50|unique:ai_providers,slug,' . $id,
            'api_base_url' => 'url|max:500',
            'auth_header' => 'nullable|string|max:100',
            'auth_prefix' => 'nullable|string|max:50',
            'supports_tools' => 'boolean',
            'supports_streaming' => 'boolean',
            'is_active' => 'boolean',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:500',
            'icon' => 'nullable|string|max:100',
            'sort_order' => 'nullable|integer',
        ]);

        $provider->update($request->only([
            'name', 'slug', 'api_base_url', 'auth_header', 'auth_prefix',
            'supports_tools', 'supports_streaming', 'is_active',
            'description', 'website', 'icon', 'sort_order',
        ]));

        return response()->json(['message' => 'Provider diperbarui.', 'data' => $provider]);
    }

    /**
     * Admin: soft delete a provider
     */
    public function destroyProvider(int $id)
    {
        $provider = AiProvider::findOrFail($id);
        $provider->delete(); // soft delete
        // Also soft-delete its models
        AiModel::where('provider_id', $id)->delete();

        return response()->json(['message' => "{$provider->name} dipindahkan ke trash."]);
    }

    /**
     * Admin: list trashed providers
     */
    public function trashedProviders()
    {
        $trashed = AiProvider::onlyTrashed()
            ->withCount(['models' => function ($q) { $q->withTrashed(); }])
            ->orderBy('deleted_at', 'desc')
            ->get();

        return response()->json(['data' => $trashed]);
    }

    /**
     * Admin: restore a trashed provider
     */
    public function restoreProvider(int $id)
    {
        $provider = AiProvider::onlyTrashed()->findOrFail($id);
        $provider->restore();
        // Also restore its models
        AiModel::onlyTrashed()->where('provider_id', $id)->restore();

        return response()->json(['message' => "{$provider->name} berhasil di-restore."]);
    }

    /**
     * Admin: permanently delete a trashed provider
     */
    public function forceDeleteProvider(int $id)
    {
        $provider = AiProvider::onlyTrashed()->findOrFail($id);
        // Force delete models first
        AiModel::onlyTrashed()->where('provider_id', $id)->forceDelete();
        $provider->forceDelete();

        return response()->json(['message' => 'Provider dihapus permanen.']);
    }

    // ==================== ADMIN CRUD: MODELS ====================

    /**
     * Admin: list models for a provider (including inactive)
     */
    public function listModels(int $providerId)
    {
        $models = AiModel::where('provider_id', $providerId)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $models]);
    }

    /**
     * Admin: create a model for a provider
     */
    public function storeModel(Request $request, int $providerId)
    {
        AiProvider::findOrFail($providerId);
        $request->validate([
            'model_id' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'category' => 'nullable|string|max:100',
            'context_window' => 'nullable|integer',
            'max_output_tokens' => 'nullable|integer',
            'supports_tools' => 'boolean',
            'supports_vision' => 'boolean',
            'is_reasoning' => 'boolean',
            'recommended_for_agent' => 'boolean',
            'input_price_per_m' => 'nullable|numeric|min:0',
            'output_price_per_m' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $maxSort = AiModel::where('provider_id', $providerId)->max('sort_order') ?? 0;

        $model = AiModel::create(array_merge($request->only([
            'model_id', 'name', 'category', 'context_window', 'max_output_tokens',
            'supports_tools', 'supports_vision', 'is_reasoning', 'recommended_for_agent',
            'input_price_per_m', 'output_price_per_m', 'is_active',
        ]), [
            'provider_id' => $providerId,
            'sort_order' => $maxSort + 1,
        ]));

        return response()->json(['message' => 'Model berhasil dibuat.', 'data' => $model], 201);
    }

    /**
     * Admin: update a model
     */
    public function updateModel(Request $request, int $modelId)
    {
        $model = AiModel::findOrFail($modelId);
        $request->validate([
            'model_id' => 'string|max:255',
            'name' => 'string|max:255',
            'category' => 'nullable|string|max:100',
            'context_window' => 'nullable|integer',
            'max_output_tokens' => 'nullable|integer',
            'supports_tools' => 'boolean',
            'supports_vision' => 'boolean',
            'is_reasoning' => 'boolean',
            'recommended_for_agent' => 'boolean',
            'input_price_per_m' => 'nullable|numeric|min:0',
            'output_price_per_m' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $model->update($request->only([
            'model_id', 'name', 'category', 'context_window', 'max_output_tokens',
            'supports_tools', 'supports_vision', 'is_reasoning', 'recommended_for_agent',
            'input_price_per_m', 'output_price_per_m', 'is_active', 'sort_order',
        ]));

        return response()->json(['message' => 'Model diperbarui.', 'data' => $model]);
    }

    /**
     * Admin: soft delete a model
     */
    public function destroyModel(int $modelId)
    {
        $model = AiModel::findOrFail($modelId);
        $model->delete();

        return response()->json(['message' => "{$model->name} dipindahkan ke trash."]);
    }

    /**
     * Admin: list trashed models for a provider
     */
    public function trashedModels(int $providerId)
    {
        $models = AiModel::onlyTrashed()
            ->where('provider_id', $providerId)
            ->orderBy('deleted_at', 'desc')
            ->get();

        return response()->json(['data' => $models]);
    }

    /**
     * Admin: restore a trashed model
     */
    public function restoreModel(int $modelId)
    {
        $model = AiModel::onlyTrashed()->findOrFail($modelId);
        $model->restore();

        return response()->json(['message' => "{$model->name} berhasil di-restore."]);
    }

    /**
     * Admin: permanently delete a trashed model
     */
    public function forceDeleteModel(int $modelId)
    {
        $model = AiModel::onlyTrashed()->findOrFail($modelId);
        $model->forceDelete();

        return response()->json(['message' => 'Model dihapus permanen.']);
    }

    /**
     * Get per-tenant AI configuration (saved API keys + active selections)
     */
    public function getConfig(Request $request)
    {
        $orgId = $this->resolveOrgId($request);
        $isSuperAdmin = $request->user()->role === 'superadmin';

        if (!$orgId && !$isSuperAdmin) {
            return response()->json(['configs' => (object)[], 'selection' => null]);
        }

        // Get saved provider configs (API keys — masked)
        $configsQuery = DB::table('ai_provider_configs');
        if ($orgId) {
            $configsQuery->where('org_id', $orgId);
        } else {
            $configsQuery->whereNull('org_id');
        }
        $configs = $configsQuery->get()
            ->map(function ($c) {
                $key = $c->api_key_encrypted;
                $c->api_key_masked = substr($key, 0, 8) . str_repeat('•', max(0, strlen($key) - 12)) . substr($key, -4);
                unset($c->api_key_encrypted);
                return $c;
            })
            ->keyBy('provider_id');

        // Get active selections
        $selectionQuery = DB::table('ai_active_selections');
        if ($orgId) {
            $selectionQuery->where('org_id', $orgId);
        } else {
            $selectionQuery->whereNull('org_id');
        }
        $selection = $selectionQuery->first();

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
            $isSuperAdmin = $request->user()->role === 'superadmin';

            if (!$orgId && !$isSuperAdmin) {
                return response()->json(['message' => 'Tidak ada organisasi.'], 400);
            }

            // Check if exists
            $query = DB::table('ai_provider_configs')
                ->where('provider_id', $request->provider_id);
            if ($orgId) {
                $query->where('org_id', $orgId);
            } else {
                $query->whereNull('org_id');
            }
            $existing = $query->first();

            if ($existing) {
                DB::table('ai_provider_configs')
                    ->where('id', $existing->id)
                    ->update([
                        'api_key_encrypted' => $request->api_key,
                        'extra_config' => $request->extra_config ? json_encode($request->extra_config) : null,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('ai_provider_configs')->insert([
                    'org_id' => $orgId,
                    'provider_id' => $request->provider_id,
                    'api_key_encrypted' => $request->api_key,
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
            $configQuery = DB::table('ai_provider_configs')
                ->where('provider_id', $request->provider_id);
            if ($orgId) {
                $configQuery->where('org_id', $orgId);
            } else {
                $configQuery->whereNull('org_id');
            }
            $config = $configQuery->first();
            if (!$config) {
                return response()->json(['success' => false, 'message' => 'API key belum disimpan'], 400);
            }
            $apiKey = $config->api_key_encrypted;
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
            'mode' => 'required|in:chat,agent,document',
            'provider_id' => 'required|integer',
            'model_id' => 'required|integer',
        ]);

        $orgId = $this->resolveOrgId($request);
        $isSuperAdmin = $request->user()->role === 'superadmin';
        
        if (!$orgId && !$isSuperAdmin) {
            return response()->json(['error' => 'Tidak ada organisasi.'], 400);
        }

        $mode = $request->mode;

        // Verify the model belongs to the provider
        $model = AiModel::where('id', $request->model_id)
            ->where('provider_id', $request->provider_id)
            ->firstOrFail();

        // Verify tenant has API key for this provider
        $hasKeyQuery = DB::table('ai_provider_configs')
            ->where('provider_id', $request->provider_id);
            
        if ($orgId) {
            $hasKeyQuery->where('org_id', $orgId);
        } else {
            $hasKeyQuery->whereNull('org_id');
        }
        $hasKey = $hasKeyQuery->exists();

        if (!$hasKey) {
            return response()->json(['error' => 'API key belum disimpan untuk provider ini'], 400);
        }

        $fields = match($mode) {
            'chat'     => ['chat_provider_id' => $request->provider_id, 'chat_model_id' => $request->model_id],
            'agent'    => ['agent_provider_id' => $request->provider_id, 'agent_model_id' => $request->model_id],
            'document' => ['document_provider_id' => $request->provider_id, 'document_model_id' => $request->model_id],
        };

        // Check if exists
        $selQuery = DB::table('ai_active_selections');
        if ($orgId) {
            $selQuery->where('org_id', $orgId);
        } else {
            $selQuery->whereNull('org_id');
        }
        $existing = $selQuery->first();

        if ($existing) {
            $updateQuery = DB::table('ai_active_selections');
            if ($orgId) {
                $updateQuery->where('org_id', $orgId);
            } else {
                $updateQuery->whereNull('org_id');
            }
            $updateQuery->update(array_merge($fields, ['updated_at' => now()]));
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
        $selectionQuery = DB::table('ai_active_selections');
        if ($orgId) {
            $selectionQuery->where('org_id', $orgId);
        } else {
            $selectionQuery->whereNull('org_id');
        }
        $selection = $selectionQuery->first();

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
            if (isset($selection->document_provider_id) && $selection->document_provider_id == $request->provider_id) {
                $updates['document_provider_id'] = null;
                $updates['document_model_id'] = null;
            }
            if (!empty($updates)) {
                $updateQuery = DB::table('ai_active_selections');
                if ($orgId) {
                    $updateQuery->where('org_id', $orgId);
                } else {
                    $updateQuery->whereNull('org_id');
                }
                $updateQuery->update($updates);
            }
        }

        $deleteQuery = DB::table('ai_provider_configs')
            ->where('provider_id', $request->provider_id);
        if ($orgId) {
            $deleteQuery->where('org_id', $orgId);
        } else {
            $deleteQuery->whereNull('org_id');
        }
        $deleteQuery->delete();

        return response()->json(['message' => 'API key removed']);
    }

    /**
     * Get the active model config for internal use.
     * Used by AiService internally — not exposed as API route.
     * 
     * Lookup order:
     * 1. Tenant-specific config (org_id = $orgId)
     * 2. Global/SuperAdmin config (org_id = NULL) as fallback
     */
    public static function getActiveConfig(?string $orgId, string $mode = 'chat'): ?array
    {
        // 1. Try tenant-specific config first
        $result = self::loadConfigForOrg($orgId, $mode);
        if ($result) return $result;

        // 2. Fallback to global config (org_id = NULL) if tenant has no own config
        if ($orgId) {
            $result = self::loadConfigForOrg(null, $mode);
            if ($result) return $result;
        }

        return null;
    }

    /**
     * Load config for a specific org_id (or NULL for global).
     */
    private static function loadConfigForOrg(?string $orgId, string $mode): ?array
    {
        $selQuery = DB::table('ai_active_selections');
        if ($orgId) {
            $selQuery->where('org_id', $orgId);
        } else {
            $selQuery->whereNull('org_id');
        }
        $selection = $selQuery->first();

        if (!$selection) return null;

        $providerId = match($mode) {
            'agent'    => $selection->agent_provider_id,
            'document' => $selection->document_provider_id ?? $selection->chat_provider_id,
            default    => $selection->chat_provider_id,
        };
        $modelId = match($mode) {
            'agent'    => $selection->agent_model_id,
            'document' => $selection->document_model_id ?? $selection->chat_model_id,
            default    => $selection->chat_model_id,
        };

        if (!$providerId || !$modelId) return null;

        $provider = AiProvider::find($providerId);
        $model = AiModel::find($modelId);

        // Try tenant-specific API key first, then global
        $configQuery = DB::table('ai_provider_configs')
            ->where('provider_id', $providerId);
        if ($orgId) {
            $configQuery->where('org_id', $orgId);
        } else {
            $configQuery->whereNull('org_id');
        }
        $config = $configQuery->first();

        // If tenant has no API key for this provider, try global key
        if (!$config && $orgId) {
            $config = DB::table('ai_provider_configs')
                ->where('provider_id', $providerId)
                ->whereNull('org_id')
                ->first();
        }

        if (!$provider || !$model || !$config) return null;

        return [
            'provider' => $provider,
            'model' => $model,
            'api_key' => $config->api_key_encrypted,
            'base_url' => $provider->api_base_url,
            'auth_header' => $provider->auth_header,
            'auth_prefix' => $provider->auth_prefix,
        ];
    }
}
