<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\TenantTheme;
use App\Services\AiService;
use App\Services\TenantStorageService;
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

    private const MAX_THEMES_PER_SCOPE = 3;

    /**
     * Enforce the 3-themes-per-scope cap. Editing existing rows is always
     * allowed; only new-row creation (store/generate/import) counts.
     * Returns a JsonResponse when limit hit, null when ok.
     */
    private function assertUnderLimit(array $scope): ?\Illuminate\Http\JsonResponse
    {
        $q = TenantTheme::query();
        if ($scope['value'] === null) $q->whereNull('org_id');
        else $q->where('org_id', $scope['value']);

        $count = $q->count();
        if ($count >= self::MAX_THEMES_PER_SCOPE) {
            return response()->json([
                'message' => 'Batas tema tercapai. Setiap akun maksimal ' . self::MAX_THEMES_PER_SCOPE . ' tema. Hapus tema lama untuk membuat yang baru.',
                'limit' => self::MAX_THEMES_PER_SCOPE,
                'current' => $count,
            ], 422);
        }
        return null;
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
        if ($limit = $this->assertUnderLimit($scope)) return $limit;
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

    /**
     * Deactivate a theme without activating any other — the app falls back
     * to the built-in default palette (globals.css baseline).
     */
    public function deactivate(Request $request, string $id)
    {
        $theme = $this->findScoped($request, $id);
        $theme->update(['is_active' => false]);
        $this->audit('theme_deactivated', $theme->id, []);
        return response()->json(['data' => $theme]);
    }

    /**
     * Deactivate ALL themes in the caller's scope → app reverts to built-in
     * default palette. Used by the virtual "Default" card's Activate button.
     */
    public function useDefault(Request $request)
    {
        $scope = $this->scope($request);
        $q = TenantTheme::query();
        if ($scope['value'] === null) $q->whereNull('org_id');
        else $q->where('org_id', $scope['value']);
        $q->update(['is_active' => false]);

        $this->audit('theme_use_default', null, ['scope' => $scope['value']]);
        return response()->json(['message' => 'Menggunakan tema default']);
    }

    /**
     * AI-generate a theme from tenant onboarding context + optional preferences.
     * Always saved as a draft (is_active=false). User reviews + activates manually.
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'preferences' => 'nullable|string|max:2000',
            'name' => 'nullable|string|max:120',
        ]);

        $user = $request->user();
        $scope = $this->scope($request);
        if ($limit = $this->assertUnderLimit($scope)) return $limit;

        // Context pull: onboarding fields if tenant-scoped, otherwise a
        // generic "platform" context for root/superadmin themes.
        $contextLines = [];
        if ($scope['value']) {
            $org = Organization::find($scope['value']);
            if ($org) {
                $contextLines[] = 'Company: ' . $org->name;
                if ($org->industry) $contextLines[] = 'Industry: ' . $org->industry;
                if ($org->business_model) $contextLines[] = 'Business model: ' . $org->business_model;
                if ($org->company_size) $contextLines[] = 'Company size: ' . $org->company_size;
                if (is_array($org->data_subjects_type) && count($org->data_subjects_type) > 0) {
                    $contextLines[] = 'Data subjects: ' . implode(', ', $org->data_subjects_type);
                }
                if (is_array($org->core_systems) && count($org->core_systems) > 0) {
                    $contextLines[] = 'Core systems: ' . implode(', ', $org->core_systems);
                }
            }
        } else {
            $contextLines[] = 'Platform-level theme (Privasimu Nexus), target: root/superadmin interface.';
        }

        $prefs = trim($data['preferences'] ?? '');
        if ($prefs !== '') $contextLines[] = 'Designer preferences: ' . $prefs;

        $context = implode("\n", $contextLines);

        $systemPrompt = <<<PROMPT
You are a senior product designer tasked with generating a web app theme JSON for a B2B SaaS compliance platform. You MUST reply with a SINGLE JSON object and nothing else. No markdown, no prose.

Schema:
{
  "name": "short creative theme name, 2-4 words",
  "palette": {
    "primary": "#hex",
    "accent": "#hex",
    "bg": "#hex (light or dark based on mood)",
    "card_bg": "#hex (contrasts with bg)",
    "text": "#hex (high contrast on card_bg)",
    "text_muted": "#hex",
    "border": "#hex (subtle)",
    "danger": "#hex (red-ish)",
    "success": "#hex (green-ish)"
  },
  "gradients": {
    "primary": { "from": "#hex", "to": "#hex", "angle": 0-360 } | null,
    "accent":  { "from": "#hex", "to": "#hex", "angle": 0-360 } | null
  },
  "opacities": { "card_bg": 0.0-1.0, "sidebar_bg": 0.0-1.0 },
  "radius": 4-20,
  "shadow": "none" | "soft" | "medium" | "strong",
  "layout_preset": "classic" | "compact" | "brand-heavy" | "minimal",
  "font_family": "Inter" | "Plus Jakarta Sans" | "Space Grotesk" | "Manrope" | "Roboto",
  "reasoning": "1-2 sentence rationale tying the palette to the company context"
}

Rules:
- ALL colors in #RRGGBB hex, validated. No rgba/hsl/named colors.
- Ensure WCAG AA (contrast ratio ≥ 4.5) between text and card_bg.
- Match the industry and mood. Finance → trusted blue/navy. Healthcare → calm teal/green. Legal → deep conservative. Tech/startup → bold gradient. Retail → warm accent.
- If reply should feel "creative", return gradients populated; otherwise gradients can be null for flat look.
- Opacities default 1.0; set 0.85-0.95 only when glassmorphism fits the vibe.
- Output pure JSON, no code fence.
PROMPT;

        $userPrompt = "Context:\n{$context}\n\nGenerate the theme JSON now.";

        $ai = new AiService($scope['value']);
        if (!$ai->isAvailable()) {
            return response()->json(['message' => 'AI provider belum dikonfigurasi. Atur di Settings → AI Providers.'], 422);
        }

        $result = $ai->ask($systemPrompt, $userPrompt, 1500);
        if (!$result || isset($result['raw'])) {
            return response()->json([
                'message' => 'AI tidak mengembalikan JSON valid. Coba lagi atau ubah preferensi.',
                'debug' => $result ?? null,
            ], 502);
        }

        $palette = $result['palette'] ?? [];
        $defaults = TenantTheme::defaultPalette();
        foreach ($defaults as $k => $v) {
            if (empty($palette[$k]) || !preg_match('/^#[0-9a-f]{6}$/i', (string)($palette[$k] ?? ''))) {
                $palette[$k] = $v; // fall back on any malformed slot
            }
        }

        // Extended palette fields (gradient, opacity, radius, shadow) live inside
        // the palette JSON for schema flexibility — no migration needed.
        $palette['_gradients'] = $result['gradients'] ?? null;
        $palette['_opacities'] = $result['opacities'] ?? null;
        $palette['_radius'] = (int)($result['radius'] ?? 10);
        $palette['_shadow'] = $result['shadow'] ?? 'medium';
        $palette['_reasoning'] = (string)($result['reasoning'] ?? '');

        $layoutPreset = in_array($result['layout_preset'] ?? '', ['classic', 'compact', 'brand-heavy', 'minimal'], true)
            ? $result['layout_preset']
            : 'classic';

        $themeName = $data['name']
            ?? (is_string($result['name'] ?? null) ? substr(trim($result['name']), 0, 120) : 'AI Generated Theme');

        $theme = TenantTheme::create([
            'org_id' => $scope['value'],
            'name' => $themeName,
            'palette' => $palette,
            'layout_preset' => $layoutPreset,
            'font_family' => $result['font_family'] ?? 'Inter',
            'logo_url' => null,
            'favicon_url' => null,
            'is_active' => false, // ALWAYS draft — user must manually activate
            'created_by' => $user->id,
        ]);

        $this->audit('theme_ai_generated', $theme->id, [
            'org_id' => $scope['value'],
            'preferences_len' => strlen($prefs),
            'reasoning' => $palette['_reasoning'] ?? null,
        ]);

        return response()->json(['data' => $theme, 'reasoning' => $palette['_reasoning'] ?? null], 201);
    }

    /**
     * Export a theme as downloadable JSON (for cross-tenant sharing in holding
     * groups). Response is the theme record; frontend handles the Blob save.
     */
    public function export(Request $request, string $id)
    {
        $theme = $this->findScoped($request, $id);
        return response()->json([
            'schema_version' => 1,
            'name' => $theme->name,
            'palette' => $theme->palette,
            'layout_preset' => $theme->layout_preset,
            'font_family' => $theme->font_family,
            // Intentionally skipping logo_url / favicon_url — those are tenant-
            // scoped storage URLs that won't work in another tenant's context.
            'exported_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Import a theme from an exported JSON blob. Saved as draft.
     */
    public function import(Request $request)
    {
        $data = $request->validate([
            'schema_version' => 'required|integer|in:1',
            'name' => 'required|string|max:120',
            'palette' => 'required|array',
            'layout_preset' => 'nullable|string|in:classic,compact,brand-heavy,minimal',
            'font_family' => 'nullable|string|max:60',
        ]);

        $scope = $this->scope($request);
        if ($limit = $this->assertUnderLimit($scope)) return $limit;
        $theme = TenantTheme::create([
            'org_id' => $scope['value'],
            'name' => $data['name'] . ' (imported)',
            'palette' => $data['palette'],
            'layout_preset' => $data['layout_preset'] ?? 'classic',
            'font_family' => $data['font_family'] ?? 'Inter',
            'is_active' => false,
            'created_by' => $request->user()->id,
        ]);
        $this->audit('theme_imported', $theme->id, ['name' => $data['name']]);
        return response()->json(['data' => $theme], 201);
    }

    public function uploadAsset(Request $request, TenantStorageService $storage)
    {
        $request->validate([
            'file' => 'required|file|max:2048|mimes:png,jpg,jpeg,svg,webp,ico,gif',
            'kind' => 'required|in:logo,favicon',
        ]);

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $scope = $this->scope($request);
        $filename = $request->kind . '_' . Str::random(12) . '.' . $ext;

        // Platform-scope (no org_id): use public disk directly — no tenant context.
        if ($scope['value'] === null) {
            $path = "themes/platform/{$filename}";
            Storage::disk('public')->putFileAs('themes/platform', $file, $filename);
            return response()->json(['url' => Storage::disk('public')->url($path), 'path' => $path]);
        }

        // Tenant scope: route through TenantStorageService so it honors tenant cloud config.
        $org = Organization::findOrFail($scope['value']);
        $result = $storage->storePublicAsset($org, $file, "themes/{$request->kind}", $filename);
        return response()->json(['url' => $result['url'], 'path' => $result['path'], 'driver' => $result['driver']]);
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
