<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DsrApp;
use Illuminate\Http\Request;

/**
 * CRUD untuk DsrApp — registered klien external apps.
 * Tenant-scoped (org_id enforced).
 *
 * Routes: /api/dsr-apps
 */
class DsrAppController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = DsrApp::where('org_id', $user->org_id)->orderBy('created_at', 'desc');

        if ($request->boolean('include_trashed')) {
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
        ]);

        $appCode = $data['app_code'] ?? DsrApp::deriveAppCode($data['name']);

        // Ensure unique app_code per org
        $base = $appCode; $i = 1;
        while (DsrApp::where('org_id', $user->org_id)->where('app_code', $appCode)->exists()) {
            $appCode = $base . $i;
            $i++;
        }

        $app = DsrApp::create(array_merge($data, [
            'org_id' => $user->org_id,
            'app_code' => $appCode,
            'created_by' => $user->id,
            'is_active' => true,
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
     * GET /api/dsr-apps/{id}/embed-snippet
     * Return JS snippet siap copy-paste.
     */
    public function embedSnippet(Request $request, string $id)
    {
        $user = $request->user();
        $app = DsrApp::where('org_id', $user->org_id)->findOrFail($id);
        return response()->json(['snippet' => $this->buildEmbedSnippet($app)]);
    }

    private function buildEmbedSnippet(DsrApp $app): string
    {
        $base = config('app.url') ?: 'https://app.privasimu.com';
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
