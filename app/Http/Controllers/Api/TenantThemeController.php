<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TenantTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Per-tenant branding & theme CRUD.
 *
 * Isolation contract:
 *  - Tenant admin (org_id set) → only sees / writes rows where org_id = auth.user.org_id.
 *  - Root / superadmin (no org_id) → only sees / writes rows where org_id IS NULL
 *    (platform-level theme).
 *  - Never returns a row whose org_id does not belong to the caller's scope.
 */
class TenantThemeController extends Controller
{
    /**
     * Resolve the scope for the current user.
     * Returns ['field' => 'org_id', 'value' => <uuid|null>].
     */
    private function scope(Request $request): array
    {
        $user = $request->user();
        if (in_array($user->role, ['root', 'superadmin'], true) && !$user->org_id) {
            return ['value' => null, 'is_platform' => true];
        }
        return ['value' => $user->org_id, 'is_platform' => false];
    }

    public function index(Request $request)
    {
        $scope = $this->scope($request);
        $q = TenantTheme::query();
        if ($scope['value'] === null) $q->whereNull('org_id');
        else $q->where('org_id', $scope['value']);

        return response()->json(['data' => $q->orderByDesc('is_active')->orderByDesc('updated_at')->get()]);
    }

    public function active(Request $request)
    {
        $scope = $this->scope($request);
        $q = TenantTheme::query()->where('is_active', true);
        if ($scope['value'] === null) $q->whereNull('org_id');
        else $q->where('org_id', $scope['value']);

        $theme = $q->first();
        return response()->json(['data' => $theme]);
    }

    public function show(Request $request, string $id)
    {
        $theme = $this->findScoped($request, $id);
        return response()->json(['data' => $theme]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'palette' => 'nullable|array',
            'layout_preset' => 'nullable|string|in:classic,compact,brand-heavy,minimal',
            'font_family' => 'nullable|string|max:60',
            'logo_url' => 'nullable|string|max:500',
            'favicon_url' => 'nullable|string|max:500',
        ]);

        $scope = $this->scope($request);
        $theme = TenantTheme::create([
            'org_id' => $scope['value'],
            'name' => $data['name'],
            'palette' => $data['palette'] ?? TenantTheme::defaultPalette(),
            'layout_preset' => $data['layout_preset'] ?? 'classic',
            'font_family' => $data['font_family'] ?? 'Inter',
            'logo_url' => $data['logo_url'] ?? null,
            'favicon_url' => $data['favicon_url'] ?? null,
            'is_active' => false,
            'created_by' => $request->user()->id,
        ]);

        $this->audit('theme_created', $theme->id, ['org_id' => $scope['value'], 'name' => $theme->name]);
        return response()->json(['data' => $theme], 201);
    }

    public function update(Request $request, string $id)
    {
        $theme = $this->findScoped($request, $id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'palette' => 'sometimes|array',
            'layout_preset' => 'sometimes|string|in:classic,compact,brand-heavy,minimal',
            'font_family' => 'sometimes|string|max:60',
            'logo_url' => 'sometimes|nullable|string|max:500',
            'favicon_url' => 'sometimes|nullable|string|max:500',
        ]);

        $theme->update($data);
        $this->audit('theme_updated', $theme->id, ['changes' => array_keys($data)]);
        return response()->json(['data' => $theme]);
    }

    public function destroy(Request $request, string $id)
    {
        $theme = $this->findScoped($request, $id);
        if ($theme->is_active) {
            return response()->json(['message' => 'Tidak bisa hapus tema yang sedang aktif'], 422);
        }
        $theme->delete();
        $this->audit('theme_deleted', $id, []);
        return response()->json(['message' => 'Tema dihapus']);
    }

    public function setActive(Request $request, string $id)
    {
        $theme = $this->findScoped($request, $id);
        $scope = $this->scope($request);

        // Deactivate all other themes in the same scope
        $q = TenantTheme::query();
        if ($scope['value'] === null) $q->whereNull('org_id');
        else $q->where('org_id', $scope['value']);
        $q->where('id', '!=', $theme->id)->update(['is_active' => false]);

        $theme->update(['is_active' => true]);
        $this->audit('theme_activated', $theme->id, []);
        return response()->json(['data' => $theme]);
    }

    public function uploadAsset(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:2048|mimes:png,jpg,jpeg,svg,webp,ico,gif',
            'kind' => 'required|in:logo,favicon',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $scope = $this->scope($request);
        $scopeDir = $scope['value'] ?: 'platform';
        $filename = $request->kind . '_' . Str::random(12) . '.' . $ext;
        $path = "themes/{$scopeDir}/{$filename}";

        Storage::disk('public')->putFileAs("themes/{$scopeDir}", $file, $filename);

        $url = Storage::disk('public')->url($path);
        return response()->json(['url' => $url, 'path' => $path]);
    }

    private function findScoped(Request $request, string $id): TenantTheme
    {
        $scope = $this->scope($request);
        $q = TenantTheme::where('id', $id);
        if ($scope['value'] === null) $q->whereNull('org_id');
        else $q->where('org_id', $scope['value']);

        $theme = $q->firstOrFail();
        return $theme;
    }

    private function audit(string $action, ?string $recordId, array $payload): void
    {
        try {
            AuditLog::log('tenant_themes', $recordId, $action, $payload, 'theme');
        } catch (\Throwable $e) { \Log::warning("Audit log failed: {$e->getMessage()}"); }
    }
}
