<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsentCollectionPoint;
use App\Models\Organization;
use App\Services\TenantStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Collection-point-level helpers (similar to DsrAppController):
 *   - regenerate-api-keys (returns server_key plaintext ONCE)
 *   - regenerate-embed-token
 *   - embed-snippet (JS tag for klien)
 *   - upload-logo (auto-route ke tenant/Privasimu storage)
 */
class ConsentCollectionController extends Controller
{
    public function regenerateApiKeys(Request $request, string $id)
    {
        $user = $request->user();
        $cp = ConsentCollectionPoint::where('org_id', $user->org_id)->findOrFail($id);

        [$clientKey, $serverKeyPlain] = ConsentCollectionPoint::generateApiKeyPair();

        if ($cp->client_key) {
            Cache::forget('consent:cp_by_client_key:' . sha1($cp->client_key));
        }

        $authMethods = $cp->auth_methods ?? ['widget' => true, 'api_key' => false];
        $authMethods['api_key'] = true;

        $cp->update([
            'client_key' => $clientKey,
            'server_key' => $serverKeyPlain,
            'auth_methods' => $authMethods,
            'api_keys_last_rotated_at' => now(),
        ]);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'consent', 'record_id' => $cp->id,
            'action' => 'consent_collection.regenerate_api_keys',
            'details' => ['client_key_prefix' => substr($clientKey, 0, 16) . '…'],
        ]);

        return response()->json([
            'message' => 'API keys regenerated. SERVER KEY DITAMPILKAN HANYA SEKALI — simpan sekarang!',
            'client_key' => $clientKey,
            'server_key' => $serverKeyPlain,
            'warning' => 'Server key tidak bisa di-view lagi setelah ini.',
            'rotated_at' => $cp->api_keys_last_rotated_at,
        ]);
    }

    public function regenerateEmbedToken(Request $request, string $id)
    {
        $user = $request->user();
        $cp = ConsentCollectionPoint::where('org_id', $user->org_id)->findOrFail($id);

        if ($cp->embed_token) {
            Cache::forget('consent:cp_by_embed_token:' . sha1($cp->embed_token));
        }

        $cp->update(['embed_token' => ConsentCollectionPoint::generateUniqueToken()]);

        AuditLog::create([
            'org_id' => $user->org_id, 'user_id' => $user->id,
            'module' => 'consent', 'record_id' => $cp->id,
            'action' => 'consent_collection.regenerate_embed_token',
            'details' => [],
        ]);

        return response()->json([
            'message' => 'Embed token regenerated. Update snippet di semua website klien.',
            'embed_token' => $cp->embed_token,
        ]);
    }

    public function embedSnippet(Request $request, string $id)
    {
        $user = $request->user();
        $cp = ConsentCollectionPoint::where('org_id', $user->org_id)->findOrFail($id);
        return response()->json(['snippet' => $this->buildEmbedSnippet($cp)]);
    }

    public function uploadLogo(Request $request, TenantStorageService $storage, ?string $id = null)
    {
        $user = $request->user();
        $request->validate([
            'file' => 'required|file|image|mimes:png,jpg,jpeg,svg,webp|max:2048',
        ]);
        $org = Organization::findOrFail($user->org_id);
        $stored = $storage->storePublicAsset($org, $request->file('file'), 'consent/logos');

        if ($id) {
            $cp = ConsentCollectionPoint::where('org_id', $org->id)->findOrFail($id);
            $settings = $cp->settings ?? [];
            $settings['logo_url'] = $stored['url'];
            $cp->update(['settings' => $settings]);

            AuditLog::create([
                'org_id' => $org->id, 'user_id' => $user->id,
                'module' => 'consent', 'record_id' => $cp->id,
                'action' => 'consent_collection.logo_upload',
                'details' => ['driver' => $stored['driver']],
            ]);
        }

        return response()->json([
            'url' => $stored['url'],
            'driver' => $stored['driver'],
            'is_tenant_storage' => $stored['driver'] !== 'public',
        ]);
    }

    private function buildEmbedSnippet(ConsentCollectionPoint $cp): string
    {
        $base = rtrim(config('app.url') ?: url('/'), '/');
        $token = $cp->embed_token ?: $cp->collection_id; // legacy fallback
        return <<<HTML
<!-- Privasimu Consent Banner — {$cp->name} -->
<script src="{$base}/consent-banner.js"
        data-collection-id="{$token}"
        async></script>
HTML;
    }
}
