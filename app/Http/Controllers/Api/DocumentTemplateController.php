<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\Organization;
use App\Models\TenantTheme;
use App\Services\DocxTemplateService;
use App\Services\TenantStorageService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        // withoutGlobalScope('org'): sistem template org_id NULL akan ke-filter
        // oleh BelongsToOrg scope. Kita kontrol visibility manual di sini.
        $rows = DocumentTemplate::withoutGlobalScope('org')
            ->where(function ($q) use ($orgId) {
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

        $customCount = $orgId ? DocumentTemplate::where('org_id', $orgId)->count() : 0;

        return response()->json([
            'data' => $rows,
            'active_id' => $theme?->active_document_template_id,
            'default_config' => DocumentTemplate::DEFAULT_CONFIG,
            'tenant_limit' => self::TENANT_TEMPLATE_LIMIT,
            'tenant_custom_count' => $customCount,
        ]);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $tpl = DocumentTemplate::withoutGlobalScope('org')
            ->where(function ($q) use ($user) {
                $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
            })->findOrFail($id);

        return response()->json([
            'data' => $tpl,
            'merged_config' => $tpl->mergedConfig(),
        ]);
    }

    /** Max custom templates per tenant. */
    public const TENANT_TEMPLATE_LIMIT = 3;

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        // Enforce per-tenant limit on custom templates.
        if ($user->org_id) {
            $count = DocumentTemplate::where('org_id', $user->org_id)->count();
            if ($count >= self::TENANT_TEMPLATE_LIMIT) {
                return response()->json([
                    'message' => 'Batas maksimal '.self::TENANT_TEMPLATE_LIMIT.' template custom per tenant sudah tercapai. Hapus template yang tidak dipakai terlebih dahulu.',
                    'limit' => self::TENANT_TEMPLATE_LIMIT,
                    'current' => $count,
                ], 422);
            }
        }

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'config' => 'required|array',
            'clone_from' => 'nullable|uuid', // optional: start from an existing template
        ]);

        $config = $data['config'];
        if (! empty($data['clone_from'])) {
            $src = DocumentTemplate::withoutGlobalScope('org')
                ->where(function ($q) use ($user) {
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
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        // Look up target. Tenant-owned → edit in place. System preset →
        // auto-fork silently to tenant copy (copy-on-write) so "Edit" UX
        // just works.
        $tpl = DocumentTemplate::withoutGlobalScope('org')
            ->where(function ($q) use ($user) {
                $q->where('org_id', $user->org_id)->orWhereNull('org_id');
        })->findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'description' => 'nullable|string|max:500',
            'config' => 'sometimes|array',
        ]);

        if ($tpl->is_system) {
            // Root/superadmin at platform scope may edit system defaults directly.
            if (in_array($user->role, ['root', 'superadmin'], true) && ! $user->org_id) {
                $tpl->update($data);

                return response()->json(['data' => $tpl]);
            }

            // Tenant edits to a system preset → fork to tenant copy. Enforce limit.
            if ($user->org_id) {
                $count = DocumentTemplate::where('org_id', $user->org_id)->count();
                if ($count >= self::TENANT_TEMPLATE_LIMIT) {
                    return response()->json([
                        'message' => 'Batas maksimal '.self::TENANT_TEMPLATE_LIMIT.' template custom per tenant sudah tercapai. Hapus template yang tidak dipakai terlebih dahulu.',
                        'limit' => self::TENANT_TEMPLATE_LIMIT,
                        'current' => $count,
                    ], 422);
                }
            }

            $fork = DocumentTemplate::create([
                'org_id' => $user->org_id,
                'name' => $data['name'] ?? $tpl->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $tpl->description,
                'config' => array_merge($tpl->config ?? [], $data['config'] ?? []),
                'is_system' => false,
                'is_default' => false,
                'created_by' => $user->id,
            ]);

            return response()->json(['data' => $fork, 'forked_from' => $tpl->id]);
        }

        $tpl->update($data);

        return response()->json(['data' => $tpl]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

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
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }

        $tpl = DocumentTemplate::withoutGlobalScope('org')
            ->where(function ($q) use ($user) {
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

    /** Phase H1 — document kinds the per-kind map supports. */
    public const DOCUMENT_KINDS = [
        'default' => 'Default — dipakai kalau kind tertentu tidak di-assign',
        'ropa' => 'RoPA Export',
        'dpia' => 'DPIA Export',
        'gap_report' => 'Gap Assessment Report',
        'breach_report' => 'Breach Full Report',
        'breach_komdigi' => 'Breach — Surat Notifikasi KOMDIGI',
        'breach_subject' => 'Breach — Surat Notifikasi Subjek Data',
        'posture' => 'Data Posture Score Report',
    ];

    /**
     * GET /document-templates/active-map
     *
     * Returns:
     *   - kinds: list of supported document kinds with labels
     *   - map: current tenant assignment { kind: template_id }
     *   - legacy_id: current value of TenantTheme.active_document_template_id
     *     (shown as "default" fallback when map is empty)
     *   - default_system_id: id of is_default=true system preset
     */
    public function activeMap(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;
        $theme = $orgId ? TenantTheme::where('org_id', $orgId)->first() : null;
        $systemDefault = DocumentTemplate::withoutGlobalScope('org')
            ->whereNull('org_id')
            ->where('is_default', true)
            ->first();

        return response()->json([
            'kinds' => self::DOCUMENT_KINDS,
            'map' => is_array($theme->active_template_map ?? null) ? $theme->active_template_map : [],
            'legacy_id' => $theme?->active_document_template_id,
            'default_system_id' => $systemDefault?->id,
        ]);
    }

    /**
     * PUT /document-templates/active-map  { map: { kind: id|null } }
     *
     * Writes the entire map in one call. null values clear that kind (lookup
     * falls through to map.default → legacy_id → system default). Also
     * mirrors `map.default` into the legacy active_document_template_id so
     * old code paths that haven't migrated still pick up the right template.
     */
    public function updateActiveMap(Request $request)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }
        if (! $user->org_id) {
            return response()->json(['message' => 'Butuh konteks tenant.'], 422);
        }

        $data = $request->validate([
            'map' => 'required|array',
            'map.*' => 'nullable|uuid',
        ]);

        // Only accept known kinds.
        $clean = [];
        foreach ($data['map'] as $kind => $id) {
            if (! array_key_exists($kind, self::DOCUMENT_KINDS)) {
                continue;
            }
            if ($id) {
                // Verify the template exists and is accessible to this tenant.
                $tpl = DocumentTemplate::withoutGlobalScope('org')
                    ->where('id', $id)
                    ->where(function ($q) use ($user) {
                        $q->whereNull('org_id')->orWhere('org_id', $user->org_id);
                    })->first();
                if (! $tpl) {
                    continue;
                }
                $clean[$kind] = $id;
            }
        }

        $theme = TenantTheme::firstOrCreate(
            ['org_id' => $user->org_id],
            [
                'name' => 'Default',
                'palette' => TenantTheme::defaultPalette(),
                'layout_preset' => 'classic',
                'font_family' => 'Inter',
                'is_active' => false,
            ]
        );
        $theme->active_template_map = $clean;
        // Mirror map.default into legacy single field so lookups that still
        // read active_document_template_id pick it up.
        if (isset($clean['default'])) {
            $theme->active_document_template_id = $clean['default'];
        }
        $theme->save();

        return response()->json([
            'map' => $clean,
            'legacy_id' => $theme->active_document_template_id,
        ]);
    }

    /**
     * Phase H2 — daftar slug template React PDF yang valid.
     *
     * Mirror dari `frontend/src/lib/pdf/registry.ts`. Backend hanya
     * menerima slug dari daftar ini supaya tidak menyimpan nilai
     * sembarangan. Bila ada penambahan template di frontend, daftar
     * ini WAJIB diperbarui juga.
     */
    public const TEMPLATE_SLUGS = [
        'midnight-indigo',
        'editorial-classic',
        'pure-minimal',
        'lilac-modernist',
        'sage-corporate',
        'terracotta-heritage',
        'geometric-tech',
        'slate-architectural',
        'newsprint-gazette',
        'onyx-premium',
        'bauhaus-primary',
        'japandi-zen',
        'brutalist-mono',
        'memphis-postmodern',
        'art-deco-gold',
        'botanical-vintage',
        'swiss-international',
        'risograph-duotone',
        'manuscript-vellum',
        'cyber-glass',
    ];

    public const DEFAULT_TEMPLATE_SLUG = 'midnight-indigo';

    /**
     * GET /template-slug/active
     *
     * Mengembalikan slug template React PDF yang aktif untuk tenant
     * saat ini. Jika tenant belum memilih, kembalikan null — caller
     * (frontend) yang memutuskan default.
     */
    public function getActiveSlug(Request $request)
    {
        $user = $request->user();
        $orgId = $user->org_id;
        $slug = null;
        if ($orgId) {
            $theme = TenantTheme::where('org_id', $orgId)->first();
            $slug = $theme?->active_template_slug;
        }

        return response()->json([
            'slug' => $slug,
            'default_slug' => self::DEFAULT_TEMPLATE_SLUG,
        ]);
    }

    /**
     * PUT /template-slug/active  { slug: string }
     *
     * Menyimpan slug template aktif ke tenant_themes.active_template_slug.
     * Slug divalidasi terhadap TEMPLATE_SLUGS supaya hanya nilai yang
     * dikenali frontend yang masuk DB.
     */
    public function setActiveSlug(Request $request)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Anda tidak memiliki izin untuk mengubah template aktif.'], 403);
        }
        if (! $user->org_id) {
            return response()->json(['message' => 'Pengaturan template aktif membutuhkan konteks tenant.'], 422);
        }

        $data = $request->validate([
            'slug' => 'required|string|max:64',
        ]);

        if (! in_array($data['slug'], self::TEMPLATE_SLUGS, true)) {
            return response()->json([
                'message' => 'Slug template tidak dikenali. Silakan pilih template yang tersedia di daftar.',
            ], 422);
        }

        $theme = TenantTheme::firstOrCreate(
            ['org_id' => $user->org_id],
            [
                'name' => 'Default',
                'palette' => TenantTheme::defaultPalette(),
                'layout_preset' => 'classic',
                'font_family' => 'Inter',
                'is_active' => false,
            ]
        );
        $theme->active_template_slug = $data['slug'];
        $theme->save();

        return response()->json([
            'message' => 'Template aktif telah disimpan.',
            'slug' => $theme->active_template_slug,
        ]);
    }

    /**
     * Upload a .docx template with placeholder variables for a specific export kind.
     * Stores via TenantStorageService as a private tenant file and updates the
     * active DocumentTemplate's `docx_templates` map.
     *
     * POST /document-templates/{id}/upload-docx  { file, kind }
     * kind: ropa | dpia | gap
     * Returns: { template: DocumentTemplate, kind }
     */
    public function uploadDocx(Request $request, string $id, TenantStorageService $storage)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }
        if (! $user->org_id) {
            return response()->json(['message' => 'Upload DOCX memerlukan konteks tenant.'], 422);
        }

        $request->validate([
            'file' => 'required|file|mimes:docx|max:10240',
            'kind' => 'required|in:ropa,dpia,gap',
        ]);

        $tpl = DocumentTemplate::where('org_id', $user->org_id)->findOrFail($id);
        $org = Organization::findOrFail($user->org_id);

        $stored = $storage->storeTenantPrivateFile(
            $org, $request->file('file'), 'docx-templates'
        );

        $map = $tpl->docx_templates ?? [];
        // Clean up old file for this kind.
        if (! empty($map[$request->kind]['path'])) {
            try {
                $storage->getDisk($org)->delete($map[$request->kind]['path']);
            } catch (\Throwable $e) { /* best-effort */
            }
        }
        $map[$request->kind] = [
            'path' => $stored['path'],
            'name' => $request->file('file')->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
            'driver' => $stored['driver'],
        ];
        $tpl->docx_templates = $map;
        $tpl->save();

        // Auto-assign this template as the active per-kind binding so the
        // upload takes effect immediately without a separate trip to the
        // assignment matrix. Without this, users uploaded DOCX templates and
        // then wondered why exports still showed the built-in DOCX.
        // Kind on upload is 'ropa'|'dpia'|'gap'; map key uses 'gap_report' for gap.
        $assignmentKind = $request->kind === 'gap' ? 'gap_report' : $request->kind;
        $autoAssigned = false;
        try {
            $theme = TenantTheme::firstOrCreate(['org_id' => $user->org_id]);
            $activeMap = is_array($theme->active_template_map) ? $theme->active_template_map : [];
            if (($activeMap[$assignmentKind] ?? null) !== $tpl->id) {
                $activeMap[$assignmentKind] = $tpl->id;
                $theme->active_template_map = $activeMap;
                $theme->save();
                $autoAssigned = true;
            }
        } catch (\Throwable $e) {
            \Log::warning('Auto-assign template after upload failed: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'Template DOCX tersimpan'.($autoAssigned ? ' & otomatis di-assign sebagai aktif untuk '.strtoupper($request->kind) : '').'.',
            'data' => $tpl,
            'kind' => $request->kind,
            'auto_assigned' => $autoAssigned,
        ]);
    }

    /**
     * Remove a DOCX template for a kind.
     * DELETE /document-templates/{id}/docx/{kind}
     */
    public function deleteDocx(Request $request, string $id, string $kind, TenantStorageService $storage)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }
        if (! in_array($kind, ['ropa', 'dpia', 'gap'], true)) {
            return response()->json(['message' => 'Kind tidak valid.'], 422);
        }

        $tpl = DocumentTemplate::where('org_id', $user->org_id)->findOrFail($id);
        $map = $tpl->docx_templates ?? [];
        $path = $map[$kind]['path'] ?? null;

        if ($path) {
            $org = Organization::findOrFail($user->org_id);
            try {
                $storage->getDisk($org)->delete($path);
            } catch (\Throwable $e) { /* best-effort */
            }
        }

        unset($map[$kind]);
        $tpl->docx_templates = $map ?: null;
        $tpl->save();

        return response()->json(['message' => 'Template DOCX dihapus.', 'data' => $tpl]);
    }

    /**
     * Return the placeholder variable catalog for DOCX templates per kind.
     * GET /document-templates/docx-placeholders
     */
    public function docxPlaceholders()
    {
        return response()->json([
            'data' => DocxTemplateService::placeholderCatalog(),
            'notes' => [
                'Gunakan sintaks ${field_name} di dalam file .docx.',
                'Upload .docx Anda per kind (RoPA / DPIA / GAP) di tab Branding → Document → DOCX Templates.',
                'Lists tampil sebagai teks dipisah koma. Untuk tabel, gunakan TemplateProcessor cloneRow manual.',
            ],
        ]);
    }

    /**
     * Upload a template asset (watermark image, cover background image, logo).
     * Writes via TenantStorageService — honors per-tenant storage config when
     * set, otherwise Laravel's `public` disk.
     *
     * POST /document-templates/upload-asset { file, kind }
     * kind: watermark | cover | logo
     * Returns: { url, path, driver, kind }
     */
    public function uploadAsset(Request $request, TenantStorageService $storage)
    {
        $user = $request->user();
        if (! $this->canEdit($user)) {
            return response()->json(['message' => 'Tidak diizinkan.'], 403);
        }
        if (! $user->org_id) {
            return response()->json(['message' => 'Upload asset memerlukan konteks tenant.'], 422);
        }

        $request->validate([
            'file' => 'required|file|max:4096|mimes:png,jpg,jpeg,webp,svg',
            'kind' => 'required|in:watermark,cover,logo',
        ]);

        $org = Organization::findOrFail($user->org_id);
        $result = $storage->storePublicAsset(
            $org,
            $request->file('file'),
            "document-templates/{$request->kind}"
        );

        return response()->json(array_merge($result, ['kind' => $request->kind]));
    }

    /**
     * Preview a template with sample data — renders a demo PDF so the
     * /branding editor can show live result. Accepts an ad-hoc config
     * so the editor can preview before saving.
     *
     * Memakai Blade generic `reports.templates.preview` (legacy) supaya
     * endpoint tetap responsif terhadap perubahan editor config (warna,
     * watermark, header/footer, dsb). Rendering PDF/DOCX yang lebih kaya
     * untuk export ROPA/DPIA sudah dipindah ke frontend client-side.
     */
    public function preview(Request $request)
    {
        $user = $request->user();
        $config = $request->input('config', []);
        $merged = array_merge(DocumentTemplate::DEFAULT_CONFIG, $config);

        $org = Organization::find($user->org_id);

        // Inline watermark/cover images as data URIs so dompdf renders them
        // regardless of storage driver (local /storage/, S3, etc).
        $merged['watermark_image'] = $this->assetUrlToDataUri($merged['watermark_image'] ?? null, $org);
        $merged['cover_bg_image'] = $this->assetUrlToDataUri($merged['cover_bg_image'] ?? null, $org);

        $orgName = $org?->name ?? 'Sample Organization';

        $payload = [
            'ropa' => $this->sampleRopaData($orgName),
            'config' => $merged,
            'orgName' => $orgName,
            'orgLogoUrl' => $this->assetUrlToDataUri($org?->logo_url ?? null, $org),
            'orgAddress' => $org?->address ?? 'Sample Address',
            'orgWebsite' => $org?->website ?? 'example.com',
            'today' => now()->locale('id')->isoFormat('D MMMM Y'),
            'generatedBy' => $user->name,
            'generatedAt' => now()->locale('id')->isoFormat('D MMMM Y · HH:mm'),
        ];

        $pdf = Pdf::loadView('reports.templates.preview', $payload)
            ->setPaper(strtolower($merged['page_size'] ?? 'a4'), $merged['orientation'] ?? 'portrait')
            ->setOption([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => $merged['font_family'] ?? 'DejaVu Sans',
            ]);

        return response($pdf->output())
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="template-preview.pdf"');
    }

    /**
     * Data contoh ROPA yang dipakai Blade preview template.
     */
    private function sampleRopaData(string $orgName): array
    {
        return [
            'number' => 'ROPA-HR-002',
            'name' => 'Registrasi Nasabah via Aplikasi ABCDE',
            'org' => $orgName,
            'purpose' => 'Registrasi nasabah baru untuk layanan aplikasi ABCDE',
            'legal_basis' => 'Pemenuhan Kewajiban Perjanjian',
            'date' => '27 April 2026',
            'categories' => [
                'Pemerolehan dan pengumpulan data',
                'Penyimpanan data',
                'Perbaikan dan pembaruan data',
                'Pengolahan dan penganalisisan data',
            ],
            'controls' => [
                'Enkripsi (at-rest & in-transit)',
                'Access Control (RBAC)',
                'Backup & Disaster Recovery',
                'Audit Log & Monitoring',
                'Vulnerability Assessment',
            ],
            'retention' => 'Data nasabah disimpan selama 5 tahun setelah penutupan akun, log aktivitas selama 2 tahun.',
        ];
    }

    /**
     * Convert an asset URL/path to a data URI so dompdf can embed it without
     * needing remote HTTP fetch. Handles:
     *   - /storage/... → Laravel public disk
     *   - tenants/{org}/... prefix embedded in URL → tenant cloud disk
     *   - Remote http/https URL → file_get_contents
     * Returns the original value on any failure.
     */
    private function assetUrlToDataUri(?string $urlOrPath, ?Organization $org): ?string
    {
        if (! $urlOrPath) {
            return $urlOrPath;
        }

        // Already a data URI → pass through.
        if (str_starts_with($urlOrPath, 'data:')) {
            return $urlOrPath;
        }

        $encode = function (string $bytes, string $mime): string {
            return 'data:'.$mime.';base64,'.base64_encode($bytes);
        };

        try {
            // Local public disk — matches /storage/... path.
            $parsed = parse_url($urlOrPath);
            $path = $parsed['path'] ?? $urlOrPath;
            if (str_contains($path, '/storage/')) {
                $rel = ltrim(preg_replace('#^.*/storage/#', '', $path), '/');
                $disk = Storage::disk('public');
                if ($disk->exists($rel)) {
                    $mime = $disk->mimeType($rel) ?: 'image/png';

                    return $encode($disk->get($rel), $mime);
                }
            }

            // Tenant cloud disk: extract `tenants/{uuid}/...` from URL.
            if ($org && preg_match('#(tenants/[a-f0-9-]+/[^?#]+)#i', $urlOrPath, $m)) {
                $rel = $m[1];
                $ts = app(TenantStorageService::class);
                $disk = $ts->getPublicDisk($org);
                if ($disk->exists($rel)) {
                    $mime = $disk->mimeType($rel) ?: 'image/png';

                    return $encode($disk->get($rel), $mime);
                }
            }

            // Last resort: fetch remotely.
            if (preg_match('#^https?://#i', $urlOrPath)) {
                $ctx = stream_context_create(['http' => ['timeout' => 4]]);
                $bytes = @file_get_contents($urlOrPath, false, $ctx);
                if ($bytes !== false) {
                    $mime = 'image/'.(strtolower(pathinfo(parse_url($urlOrPath, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION)) ?: 'png');

                    return $encode($bytes, $mime);
                }
            }
        } catch (\Throwable $e) {
            \Log::debug('assetUrlToDataUri failed: '.$e->getMessage());
        }

        return $urlOrPath;
    }
}
