<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\TenantTheme;
use Illuminate\Http\Request;

/**
 * CRUD for tenant document templates.
 *
 * - System defaults (org_id=null) are read-only; tenants can clone.
 * - GET /document-templates returns [system defaults + tenant's own].
 * - Tenant can create/update/delete their own templates.
 * - POST /document-templates/{id}/activate sets TenantTheme.active_document_template_id.
 */
class DocumentTemplateController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;

        $rows = DocumentTemplate::where(function ($q) use ($orgId) {
                $q->whereNull('org_id')->orWhere('org_id', $orgId);
            })
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(function ($t) {
                // Merge DEFAULT_CONFIG with stored partial so frontend always
                // has every field populated — avoids UI crashes on undefined.
                $t->config = $t->mergedConfig();
                return $t;
            });

        // TenantTheme has several NOT NULL columns (name, palette). We only
        // care about reading active_document_template_id here — do NOT
        // auto-create the theme row; just look it up. If none exists, the
        // tenant hasn't picked an active template yet — that's fine.
        $theme = $orgId
            ? TenantTheme::where('org_id', $orgId)->whereNotNull('active_document_template_id')->first()
            : null;

        return response()->json([
            'data' => $rows,
            'active_id' => $theme?->active_document_template_id,
            'default_config' => DocumentTemplate::DEFAULT_CONFIG,
        ]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $tpl = DocumentTemplate::where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->findOrFail($id);

        return response()->json([
            'data' => $tpl,
            'merged_config' => $tpl->mergedConfig(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'config' => 'required|array',
            'clone_from' => 'nullable|uuid', // optional: start from an existing template
        ]);

        $config = $data['config'];
        if (!empty($data['clone_from'])) {
            $src = DocumentTemplate::where(function ($q) use ($user) {
                    $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
                })->find($data['clone_from']);
            if ($src) {
                $config = array_merge($src->config ?? [], $config);
            }
        }

        $tpl = DocumentTemplate::create([
            'org_id' => $user->org_id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'config' => $config,
            'is_system' => false,
            'is_default' => false,
            'created_by' => $user->id,
        ]);

        return response()->json(['data' => $tpl], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $tpl = DocumentTemplate::where('org_id', $user->org_id)->findOrFail($id);
        if ($tpl->is_system) {
            return response()->json(['message' => 'Template sistem tidak bisa diedit — gunakan Clone dulu.'], 422);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'config' => 'sometimes|array',
        ]);
        $tpl->update($data);
        return response()->json(['data' => $tpl]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $tpl = DocumentTemplate::where('org_id', $user->org_id)->findOrFail($id);
        if ($tpl->is_system) {
            return response()->json(['message' => 'Template sistem tidak bisa dihapus.'], 422);
        }

        // Unset active if this was active.
        TenantTheme::where('org_id', $user->org_id)
            ->where('active_document_template_id', $tpl->id)
            ->update(['active_document_template_id' => null]);

        $tpl->delete();
        return response()->json(['message' => 'Template dihapus.']);
    }

    /** Set as active template for this tenant. */
    public function activate(Request $request, string $id)
    {
        $user = $request->user();
        if (!$this->canEdit($user)) return response()->json(['message' => 'Tidak diizinkan.'], 403);

        $tpl = DocumentTemplate::where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->findOrFail($id);

        // Create a minimal TenantTheme row if this tenant doesn't have one
        // yet. palette/name are required NOT NULL — provide safe defaults.
        $theme = TenantTheme::firstOrCreate(
            ['org_id' => $user->org_id],
            [
                'name' => 'Default',
                'palette' => [
                    'primary' => '#2563eb', 'accent' => '#8b5cf6',
                    'bg' => '#f8fafc', 'card_bg' => '#ffffff',
                    'text' => '#0f172a', 'text_muted' => '#64748b',
                    'border' => '#e2e8f0', 'danger' => '#dc2626', 'success' => '#16a34a',
                ],
                'layout_preset' => 'classic',
                'font_family' => 'Inter',
                'is_active' => false,
            ]
        );
        $theme->active_document_template_id = $tpl->id;
        $theme->save();
        $tpl->increment('usage_count');

        return response()->json(['message' => 'Template diaktifkan.', 'active_id' => $tpl->id]);
    }

    private function canEdit($user): bool
    {
        return in_array($user->role, ['root', 'superadmin', 'admin'], true);
    }

    /**
     * Preview a template with sample data — renders a demo PDF so the
     * /branding editor can show live result. Accepts an ad-hoc config
     * so the editor can preview before saving.
     */
    public function preview(Request $request)
    {
        $user = $request->user();
        $config = $request->input('config', []);
        $merged = array_merge(DocumentTemplate::DEFAULT_CONFIG, $config);

        $org = \App\Models\Organization::find($user->org_id);
        $payload = [
            'config' => $merged,
            'orgName' => $org?->name ?? 'Sample Organization',
            'orgLogoUrl' => $org?->logo_url ?? null,
            'orgAddress' => $org?->address ?? 'Sample Address',
            'orgWebsite' => $org?->website ?? 'example.com',
            'today' => now()->locale('id')->isoFormat('D MMMM Y'),
            'generatedBy' => $user->name,
            'generatedAt' => now()->locale('id')->isoFormat('D MMMM Y · HH:mm'),
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.templates.preview', $payload)
            ->setPaper($merged['page_size'] ?? 'a4')
            ->setOption(['isHtml5ParserEnabled' => true, 'defaultFont' => $merged['font_family'] ?? 'DejaVu Sans']);

        return $pdf->stream('template-preview.pdf');
    }
}
