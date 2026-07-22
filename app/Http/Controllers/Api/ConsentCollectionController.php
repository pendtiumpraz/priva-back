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
            Cache::forget('consent:cp_by_client_key:'.sha1($cp->client_key));
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
            'details' => ['client_key_prefix' => substr($clientKey, 0, 16).'…'],
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
            Cache::forget('consent:cp_by_embed_token:'.sha1($cp->embed_token));
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

    /**
     * Baca konfigurasi widget yang BENAR-BENAR tersimpan (kolom + subset
     * settings JSON yang dipakai widget runtime). Dipakai tab "Widget" di
     * dashboard supaya form-nya di-seed dari nilai tersimpan, bukan default
     * hard-coded.
     */
    public function widgetConfig(Request $request, string $id)
    {
        $user = $request->user();
        $cp = ConsentCollectionPoint::where('org_id', $user->org_id)->findOrFail($id);

        return response()->json(['data' => $this->widgetConfigPayload($cp)]);
    }

    /**
     * Simpan konfigurasi widget. Sengaja terpisah dari PUT /m/consent/{id}
     * (universal CRUD) karena endpoint itu MENIMPA seluruh kolom `settings`
     * dengan payload yang dikirim — partial save dari tab Widget akan
     * menghapus key lain (logo_url, linked_ropa_id, powered_by_*).
     * Di sini `settings` di-MERGE dan tiap field divalidasi.
     */
    public function saveWidgetConfig(Request $request, string $id)
    {
        $user = $request->user();
        $cp = ConsentCollectionPoint::where('org_id', $user->org_id)->findOrFail($id);

        $data = $request->validate([
            // Kolom tabel
            'display_mode' => ['sometimes', 'in:banner_bottom,banner_top,modal_center,fullscreen,inline'],
            'display_frequency' => ['sometimes', 'in:once,session,every_load'],
            'audience' => ['sometimes', 'in:anonymous_only,logged_in_only,both'],
            'locale' => ['sometimes', 'in:id,en'],
            'allowed_domains' => ['sometimes', 'array', 'max:50'],
            'allowed_domains.*' => ['string', 'max:255'],
            'auth_methods' => ['sometimes', 'array'],
            'auth_methods.widget' => ['sometimes', 'boolean'],
            'auth_methods.api_key' => ['sometimes', 'boolean'],
            'captcha_provider' => ['sometimes', 'nullable', 'in:recaptcha_v2,recaptcha_v3,hcaptcha,turnstile'],
            'captcha_site_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'captcha_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            // settings JSON (merge, bukan replace)
            'settings' => ['sometimes', 'array'],
            'settings.primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.accent_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.banner_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.modal_intro_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'settings.show_powered_by' => ['sometimes', 'boolean'],
            'settings.powered_by_text' => ['sometimes', 'nullable', 'string', 'max:120'],
            'settings.powered_by_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'settings.powered_by_logo' => ['sometimes', 'nullable', 'url', 'max:255'],
            'settings.guardian_mode' => ['sometimes', 'boolean'],
            'settings.guardian_label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'settings.guardian_relation_options' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $before = $this->widgetConfigPayload($cp);

        $update = collect($data)->except('settings')->all();

        // captcha_secret kosong = "jangan diubah" (secret tidak pernah dibaca
        // balik ke UI, jadi form mengirim string kosong kalau user tak mengetik).
        if (array_key_exists('captcha_secret', $update) && ($update['captcha_secret'] ?? '') === '') {
            unset($update['captcha_secret']);
        }

        if (array_key_exists('auth_methods', $update)) {
            $update['auth_methods'] = array_merge(
                $cp->auth_methods ?? ['widget' => true, 'api_key' => false],
                array_map(fn ($v) => (bool) $v, $update['auth_methods'])
            );
        }

        if (array_key_exists('settings', $data)) {
            $update['settings'] = array_merge($cp->settings ?? [], $data['settings']);
        }

        if ($update !== []) {
            $cp->update($update);
        }

        $after = $this->widgetConfigPayload($cp->fresh());

        AuditLog::create([
            'module' => 'consent',
            'record_id' => $cp->id,
            'action' => 'consent_collection.widget_config_update',
            'user_id' => $user->id,
            'user_name' => $user->name ?? null,
            'user_role' => $user->role ?? null,
            'section' => 'widget_config',
            'changes' => ['before' => $before, 'after' => $after],
            'ip_address' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Konfigurasi widget tersimpan.',
            'data' => $after,
        ]);
    }

    /**
     * Bentuk response tunggal untuk read + write, supaya UI selalu menampilkan
     * nilai yang benar-benar ada di database. `captcha_secret` sengaja TIDAK
     * disertakan (hanya flag terpasang/tidak).
     */
    private function widgetConfigPayload(ConsentCollectionPoint $cp): array
    {
        $s = $cp->settings ?? [];

        return [
            'display_mode' => $cp->display_mode,
            'display_frequency' => $cp->display_frequency,
            'audience' => $cp->audience,
            'locale' => $cp->locale,
            'allowed_domains' => $cp->allowed_domains ?? [],
            'auth_methods' => [
                'widget' => ($cp->auth_methods['widget'] ?? true) === true,
                'api_key' => ($cp->auth_methods['api_key'] ?? false) === true,
            ],
            'captcha_provider' => $cp->captcha_provider,
            'captcha_site_key' => $cp->captcha_site_key,
            'captcha_secret_set' => ! empty($cp->captcha_secret),
            'settings' => [
                'primary_color' => $s['primary_color'] ?? null,
                'accent_color' => $s['accent_color'] ?? null,
                'banner_text' => $s['banner_text'] ?? null,
                'modal_intro_text' => $s['modal_intro_text'] ?? null,
                'show_powered_by' => $s['show_powered_by'] ?? true,
                'powered_by_text' => $s['powered_by_text'] ?? null,
                'powered_by_url' => $s['powered_by_url'] ?? null,
                'powered_by_logo' => $s['powered_by_logo'] ?? null,
                'logo_url' => $s['logo_url'] ?? null,
                'guardian_mode' => $s['guardian_mode'] ?? false,
                'guardian_label' => $s['guardian_label'] ?? null,
                'guardian_relation_options' => $s['guardian_relation_options'] ?? null,
            ],
        ];
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
