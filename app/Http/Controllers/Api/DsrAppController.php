<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DsrApp;
use App\Models\Organization;
use App\Models\User;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;

/**
 * CRUD untuk DsrApp — registered klien external apps.
 * Tenant-scoped (org_id enforced).
 *
 * Routes: /api/dsr-apps
 */
class DsrAppController extends Controller
{
    /**
     * GET /api/dsr-apps/assignable-users
     * Lightweight user list for assignee picker — anyone with dsr:read can see
     * all org members (NOT just admin), so DPO can pick the right person on the
     * team. Returns minimal fields only.
     */
    public function assignableUsers(Request $request)
    {
        $user = $request->user();
        $users = User::where('org_id', $user->org_id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role,
            ]);

        return response()->json(['data' => $users]);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = DsrApp::where('org_id', $user->org_id)->orderBy('created_at', 'desc');

        // Trash mode — frontend sends ?trash=1; legacy ?include_trashed kept as alias.
        if ($request->boolean('trash')) {
            $query->onlyTrashed();
        } elseif ($request->boolean('include_trashed')) {
            $query->withTrashed();
        }

        if ($request->filled('search')) {
            $s = $request->input('search');
            $query->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                    ->orWhere('app_code', 'like', "%{$s}%");
            });
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $app = DsrApp::where('org_id', $user->org_id)->findOrFail($id);

        return response()->json(['data' => $app]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'app_code' => 'nullable|string|max:32|regex:/^[A-Z0-9_-]+$/',
            'description' => 'nullable|string',
            'allowed_domains' => 'nullable|array',
            'allowed_domains.*' => 'string|max:200',
            'default_information_system_ids' => 'nullable|array',
            'default_information_system_ids.*' => 'uuid',
            'default_assignee_user_id' => 'nullable|uuid',
            'webhook_url' => 'nullable|url|max:500',
            'branding' => 'nullable|array',
            'requires_nda_for_access' => 'nullable|boolean',
            'nda_template_doc_id' => 'nullable|uuid',
            'nda_signing_method' => 'nullable|in:e_signature,typed_acknowledgement,upload_signed_pdf',
            'captcha_provider' => 'nullable|in:turnstile,hcaptcha,recaptcha_v3',
            'captcha_site_key' => 'nullable|string|max:200',
            'captcha_secret' => 'nullable|string|max:500',
            'auth_methods' => 'nullable|array',
            'auth_methods.widget' => 'nullable|boolean',
            'auth_methods.api_key' => 'nullable|boolean',
            'locale' => 'nullable|in:id,en',
            'is_active' => 'nullable|boolean',
        ]);

        $appCode = $data['app_code'] ?? DsrApp::deriveAppCode($data['name']);

        // Ensure unique app_code per org
        $base = $appCode;
        $i = 1;
        while (DsrApp::where('org_id', $user->org_id)->where('app_code', $appCode)->exists()) {
            $appCode = $base.$i;
            $i++;
        }

        $app = DsrApp::create(array_merge($data, [
            'org_id' => $user->org_id,
            'app_code' => $appCode,
            'created_by' => $user->id,
            'is_active' => $data['is_active'] ?? true,
        ]));

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $app->id,
            'action' => 'dsr_app.create',
            'details' => ['name' => $app->name, 'app_code' => $app->app_code],
        ]);

        return response()->json([
            'message' => 'DSR App registered',
            'data' => $app,
            'embed_snippet' => $this->buildEmbedSnippet($app),
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $app = DsrApp::where('org_id', $user->org_id)->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:200',
            'description' => 'sometimes|nullable|string',
            'allowed_domains' => 'sometimes|nullable|array',
            'default_information_system_ids' => 'sometimes|nullable|array',
            'default_assignee_user_id' => 'sometimes|nullable|uuid',
            'webhook_url' => 'sometimes|nullable|url|max:500',
            'branding' => 'sometimes|nullable|array',
            'is_active' => 'sometimes|boolean',
            'requires_nda_for_access' => 'sometimes|boolean',
            'nda_template_doc_id' => 'sometimes|nullable|uuid',
            'nda_signing_method' => 'sometimes|in:e_signature,typed_acknowledgement,upload_signed_pdf',
            'captcha_provider' => 'sometimes|nullable|in:turnstile,hcaptcha,recaptcha_v3',
            'captcha_site_key' => 'sometimes|nullable|string|max:200',
            'captcha_secret' => 'sometimes|nullable|string|max:500',
            'auth_methods' => 'sometimes|nullable|array',
            'auth_methods.widget' => 'sometimes|nullable|boolean',
            'auth_methods.api_key' => 'sometimes|nullable|boolean',
            'locale' => 'sometimes|nullable|in:id,en',
        ]);

        $app->update($data);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $app->id,
            'action' => 'dsr_app.update',
            'details' => ['fields_changed' => array_keys($data)],
        ]);

        return response()->json(['message' => 'DSR App updated', 'data' => $app->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $app = DsrApp::where('org_id', $user->org_id)->findOrFail($id);
        $app->delete();
        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $app->id,
            'action' => 'dsr_app.delete',
        ]);

        return response()->json(['message' => 'DSR App moved to trash']);
    }

    public function restore(Request $request, string $id)
    {
        $user = $request->user();
        $app = DsrApp::withTrashed()->where('org_id', $user->org_id)->findOrFail($id);
        $app->restore();

        return response()->json(['message' => 'DSR App restored', 'data' => $app->fresh()]);
    }

    /**
     * POST /api/dsr-apps/{id}/regenerate-token
     * Rotate embed_token (kalau dicurigai bocor). Old token immediately invalid.
     */
    public function regenerateToken(Request $request, string $id)
    {
        $user = $request->user();
        $app = DsrApp::where('org_id', $user->org_id)->findOrFail($id);
        $app->update(['embed_token' => DsrApp::generateUniqueToken()]);
        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $app->id,
            'action' => 'dsr_app.regenerate_token',
            'details' => ['warning' => 'old embed_token invalidated, update widget script'],
        ]);

        return response()->json([
            'message' => 'Embed token regenerated. Update widget script in production.',
            'embed_token' => $app->embed_token,
            'embed_snippet' => $this->buildEmbedSnippet($app->fresh()),
        ]);
    }

    /**
     * POST /api/dsr-apps/{id}/regenerate-api-keys
     * Generate fresh client_key + server_key. Returns server_key plaintext ONCE
     * — caller must capture it (we encrypt at rest, no way to view again).
     *
     * Side-effect: invalidates cache, increments api_keys_last_rotated_at.
     */
    public function regenerateApiKeys(Request $request, string $id)
    {
        $user = $request->user();
        $app = DsrApp::where('org_id', $user->org_id)->findOrFail($id);

        [$clientKey, $serverKeyPlain] = DsrApp::generateApiKeyPair();

        // Bust cached old client_key resolver
        if ($app->client_key) {
            \Cache::forget('dsr_app_by_client_key:'.sha1($app->client_key));
        }

        // Ensure auth_methods enables api_key
        $authMethods = $app->auth_methods ?? ['widget' => true, 'api_key' => false];
        $authMethods['api_key'] = true;

        $app->update([
            'client_key' => $clientKey,
            'server_key' => $serverKeyPlain,           // EncryptedString cast wraps it
            'auth_methods' => $authMethods,
            'api_keys_last_rotated_at' => now(),
        ]);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'dsr', 'record_id' => $app->id,
            'action' => 'dsr_app.regenerate_api_keys',
            'details' => ['client_key_prefix' => substr($clientKey, 0, 16).'…'],
        ]);

        return response()->json([
            'message' => 'API keys regenerated. SERVER KEY DITAMPILKAN HANYA SEKALI — simpan sekarang!',
            'client_key' => $clientKey,
            'server_key' => $serverKeyPlain,
            'warning' => 'Server key tidak bisa di-view lagi setelah ini. Simpan di password manager / secret store backend Anda.',
            'rotated_at' => $app->api_keys_last_rotated_at,
        ]);
    }

    /**
     * GET /api/dsr-apps/{id}/embed-snippet
     * Return JS snippet siap copy-paste.
     */
    public function embedSnippet(Request $request, string $id)
    {
        $user = $request->user();
        $app = DsrApp::where('org_id', $user->org_id)->findOrFail($id);

        return response()->json(['snippet' => $this->buildEmbedSnippet($app)]);
    }

    /**
     * POST /api/dsr-apps/upload-logo (multipart, file)
     * POST /api/dsr-apps/{id}/upload-logo (also persists to existing app)
     *
     * Upload widget branding logo. Auto-routing via TenantStorageService:
     *   - Tenant has cloud storage configured → uploads to their disk (S3/MinIO/GCS)
     *   - Otherwise → falls back to Privasimu `public` disk (served via storage:link)
     *
     * Returns {url, path, driver}. Frontend stores `url` into branding.logo_url.
     */
    public function uploadLogo(Request $request, TenantStorageService $storage, ?string $id = null)
    {
        $user = $request->user();
        $request->validate([
            'file' => 'required|file|image|mimes:png,jpg,jpeg,svg,webp|max:2048',
        ]);

        $org = Organization::findOrFail($user->org_id);
        $stored = $storage->storePublicAsset(
            $org,
            $request->file('file'),
            'dsr-apps/logos'
        );

        // If app id provided, persist to its branding atomically.
        if ($id) {
            $app = DsrApp::where('org_id', $user->org_id)->findOrFail($id);
            $branding = $app->branding ?? [];
            $branding['logo_url'] = $stored['url'];
            $app->update(['branding' => $branding]);

            AuditLog::create([
                'org_id' => $user->org_id, 'user_id' => $user->id,
                'module' => 'dsr', 'record_id' => $app->id,
                'action' => 'dsr_app.logo_upload',
                'details' => ['driver' => $stored['driver'], 'path' => $stored['path']],
            ]);
        }

        return response()->json([
            'url' => $stored['url'],
            'path' => $stored['path'],
            'driver' => $stored['driver'],
            'is_tenant_storage' => $stored['driver'] !== 'public',
        ]);
    }

    private function buildEmbedSnippet(DsrApp $app): string
    {
        // Auto-detect base from APP_URL or current request host (white-label safe).
        // Klien on-prem / localhost / custom domain semua jalan.
        $base = rtrim(config('app.url') ?: url('/'), '/');
        $token = $app->embed_token;
        $position = $app->branding['position'] ?? 'bottom-right';
        $btnText = $app->branding['button_text'] ?? '🔒 Privacy Request';

        return <<<HTML
<!-- Privasimu DSR Widget — {$app->name} -->
<script src="{$base}/dsr-widget.js"
        data-embed-token="{$token}"
        data-button-text="{$btnText}"
        data-button-position="{$position}"
        async></script>
HTML;
    }
}
