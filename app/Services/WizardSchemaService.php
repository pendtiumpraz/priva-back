<?php

namespace App\Services;

use App\Models\ModuleCustomField;
use App\Models\ModuleCustomSection;
use Illuminate\Support\Collection;

/**
 * Wizard schema resolution for RoPA / DPIA — Phase 1 (CUSTOM_WIZARD_PLAN.md §5.2).
 *
 * Combines the built-in section skeleton with the org-global custom sections
 * + fields. Returns an ordered array of section descriptors that the FE can
 * render generically.
 *
 * Each section descriptor exposes a `source` discriminator:
 *   - `built_in`   → header is frozen (label/order locked, FE owns i18n).
 *                    Admins MAY attach org-custom fields by reusing the
 *                    built-in section_key when creating a ModuleCustomField;
 *                    those fields surface in `fields[]` so the FE can render
 *                    them at the end of the matching built-in step.
 *   - `org_custom` → admin-created section with editable header + fields.
 *                    Becomes a full extra wizard step on the FE.
 *
 * Per-record extras (Level 3 in the plan) are NOT handled here — they live
 * inline in `wizard_data.per_record_extras[]` on the record itself.
 */
class WizardSchemaService
{
    public const SUPPORTED_MODULES = ['ropa', 'dpia'];

    /**
     * Built-in wizard step keys per module — MUST match the FE `SECTIONS`
     * constant in `frontend/src/app/(dashboard)/{ropa,dpia}/page.tsx`.
     *
     * Admins can attach org-custom fields to these keys via Master Schema
     * editor; those fields render at the end of the matching built-in step.
     */
    public const BUILTIN_SECTION_KEYS = [
        'ropa' => [
            'detail_pemrosesan',
            'dpo_team',
            'informasi_pemrosesan',
            'pengumpulan_data',
            'penggunaan_penyimpanan',
            'pengiriman_data',
            'retensi_keamanan',
        ],
        'dpia' => [
            'informasi_dpia',
            'koneksi_ropa',
            'potensi_risiko',
        ],
    ];

    /**
     * Get the merged wizard schema for an org+module.
     *
     * Built-in sections are emitted with `source='built_in'` and `id=null`.
     * Their `fields[]` carry org-custom fields whose `section_key` matches the
     * built-in key — admins append fields there via Master Schema editor and
     * the FE renders them at the end of the matching built-in step.
     *
     * Org-custom sections (admin-created) are emitted with `source='org_custom'`
     * and become full extra wizard steps on the FE.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSchema(string $orgId, string $module): array
    {
        if (! in_array($module, self::SUPPORTED_MODULES, true)) {
            return [];
        }

        $builtinKeys = self::BUILTIN_SECTION_KEYS[$module] ?? [];

        // PENTING: render wizard saat ini HANYA menampilkan field CUSTOM
        // (origin != built_in). Field built_in yang sudah di-materialize (untuk
        // editor/reset) sengaja DIKECUALIKAN di sini supaya tidak menduplikasi
        // field hardcoded yang masih dirender FE. Saat fase render generik aktif,
        // filter ini dilonggarkan + field hardcoded FE dihapus.
        $allCustomFields = ModuleCustomField::forOrg($orgId)
            ->forModule($module)
            ->where('origin', '!=', 'built_in')
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->groupBy('section_key');

        $orgSections = ModuleCustomSection::forOrg($orgId)
            ->forModule($module)
            ->where('origin', '!=', 'built_in')
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        $merged = [];

        // 1. Built-in section descriptors with matching org-custom fields.
        //    `label` is intentionally null — the FE owns the i18n label.
        //    `id` is null because there's no backing module_custom_sections row.
        foreach ($builtinKeys as $idx => $key) {
            $extraFields = $this->mapFields($allCustomFields[$key] ?? collect());
            $merged[] = [
                'source' => 'built_in',
                'id' => null,
                'section_key' => $key,
                'label' => null,
                'description' => null,
                'sort_order' => $idx, // 0..99 reserved for built-in steps
                'fields' => $extraFields,
            ];
        }

        // 2. Org-custom sections (admin-created). Skip any whose key collides
        //    with a built-in key — those fields already surfaced in step 1.
        foreach ($orgSections as $sec) {
            if (in_array($sec->section_key, $builtinKeys, true)) {
                continue;
            }
            $sectionFields = $this->mapFields($allCustomFields[$sec->section_key] ?? collect());

            $merged[] = [
                'source' => 'org_custom',
                'id' => $sec->id,
                'section_key' => $sec->section_key,
                'label' => $sec->section_label,
                'description' => $sec->description,
                'sort_order' => (int) $sec->sort_order,
                'fields' => $sectionFields,
            ];
        }

        // Stable ascending sort. Built-in (0–99) naturally precedes
        // org-custom (100+); ties preserve original insertion order
        // because PHP's usort is not stable, so we use a tie-breaker on the
        // pre-sort index.
        $indexed = [];
        foreach ($merged as $i => $row) {
            $indexed[] = ['_i' => $i, 'row' => $row];
        }
        usort($indexed, function ($a, $b) {
            $sa = (int) ($a['row']['sort_order'] ?? 0);
            $sb = (int) ($b['row']['sort_order'] ?? 0);
            if ($sa === $sb) {
                return $a['_i'] <=> $b['_i'];
            }

            return $sa <=> $sb;
        });

        return array_map(fn ($wrap) => $wrap['row'], $indexed);
    }

    /**
     * Map ModuleCustomField rows → API field descriptors.
     *
     * @param  Collection<int, ModuleCustomField>  $fields
     * @return array<int, array<string, mixed>>
     */
    private function mapFields(Collection $fields): array
    {
        return $fields
            ->map(fn (ModuleCustomField $f) => [
                'source' => 'org_custom',
                'id' => $f->id,
                'name' => $f->field_name,
                'label' => $f->field_label,
                'type' => $f->field_type,
                'options' => $f->field_options,
                'help_text' => $f->help_text,
                'is_required' => (bool) $f->is_required,
                'sort_order' => (int) $f->sort_order,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Helper for export / AI-mapping consumers — flat list of all active
     * org-custom fields for an org+module, ordered by section then sort.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCustomFieldsFlat(string $orgId, string $module): array
    {
        return ModuleCustomField::forOrg($orgId)
            ->forModule($module)
            ->where('origin', '!=', 'built_in')
            ->active()
            ->orderBy('section_key')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }

    // ===================================================================
    // Full schema-driven — seed & reset (Phase 1 fondasi)
    //
    // CATATAN: method ini MATERIALIZE default schema kanonik ke tabel
    // module_custom_sections/fields dengan origin='built_in'. BELUM di-wire
    // ke getSchema()/FE (itu fase render berikutnya) supaya tidak menduplikasi
    // field hardcoded yang masih dirender FE. Aman dipanggil; efek nyata baru
    // muncul saat fase render generik aktif.
    // ===================================================================

    /**
     * Schema lengkap untuk EDITOR (Master Schema): SEMUA section + field
     * (built_in & custom, aktif & nonaktif) dengan metadata penuh
     * (origin, widget, is_active, options). Auto-seed default kalau org belum
     * punya built-in rows. Terpisah dari getSchema() (render wizard) supaya
     * editor bisa kelola built-in tanpa mengubah render wizard saat ini.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getEditorSchema(string $orgId, string $module): array
    {
        if ($module === \App\Support\Schema\RopaDefaultSchema::MODULE && ! $this->isSeeded($orgId, $module)) {
            $this->seedDefaults($orgId, $module);
        }

        $sections = ModuleCustomSection::forOrg($orgId)->forModule($module)
            ->orderBy('sort_order')->orderBy('created_at')->get();
        $fields = ModuleCustomField::forOrg($orgId)->forModule($module)
            ->orderBy('sort_order')->orderBy('created_at')->get()
            ->groupBy('section_key');

        return $sections->map(fn (ModuleCustomSection $s) => [
            'id' => $s->id,
            'section_key' => $s->section_key,
            'section_label' => $s->section_label,
            'origin' => $s->origin,
            'is_active' => (bool) $s->is_active,
            'sort_order' => (int) $s->sort_order,
            'fields' => ($fields->get($s->section_key) ?? collect())->map(fn (ModuleCustomField $f) => [
                'id' => $f->id,
                'field_name' => $f->field_name,
                'field_label' => $f->field_label,
                'field_type' => $f->field_type,
                'widget' => $f->widget,
                'origin' => $f->origin,
                'field_options' => $f->field_options,
                'help_text' => $f->help_text,
                'is_required' => (bool) $f->is_required,
                'is_active' => (bool) $f->is_active,
                'sort_order' => (int) $f->sort_order,
                // Field built-in dgn widget != null: tipe & nama dikunci
                // (perilaku di-hardcode di komponen React).
                'locked' => $f->origin === 'built_in' && $f->widget !== null,
            ])->values()->toArray(),
        ])->values()->toArray();
    }

    /** Apakah org sudah punya schema built-in ter-materialize untuk module ini? */
    public function isSeeded(string $orgId, string $module): bool
    {
        return ModuleCustomField::forOrg($orgId)
            ->forModule($module)
            ->where('origin', 'built_in')
            ->exists();
    }

    /**
     * Materialize default schema kanonik ke DB (idempotent). Hanya men-seed
     * field/section built-in yang BELUM ada (tidak menyentuh custom milik org).
     */
    public function seedDefaults(string $orgId, string $module): void
    {
        if ($module !== \App\Support\Schema\RopaDefaultSchema::MODULE) {
            return; // Phase 1: hanya RoPA
        }

        $sectionSort = 0;
        foreach (\App\Support\Schema\RopaDefaultSchema::sections() as $section) {
            ModuleCustomSection::firstOrCreate(
                ['org_id' => $orgId, 'module' => $module, 'section_key' => $section['section_key']],
                [
                    'origin' => 'built_in',
                    'section_label' => $section['section_label'],
                    'sort_order' => $sectionSort,
                    'is_active' => true,
                ],
            );

            $fieldSort = 0;
            foreach ($section['fields'] as $field) {
                ModuleCustomField::firstOrCreate(
                    ['org_id' => $orgId, 'module' => $module, 'field_name' => $field['field_name']],
                    [
                        'origin' => 'built_in',
                        'section_key' => $section['section_key'],
                        'field_label' => $field['field_label'],
                        'field_type' => $field['field_type'],
                        'widget' => $field['widget'],
                        'field_options' => $field['field_options'],
                        'is_required' => $field['is_required'],
                        'sort_order' => $fieldSort,
                        'is_active' => true,
                    ],
                );
                $fieldSort += 10;
            }
            $sectionSort += 10;
        }
    }

    /**
     * Reset schema RoPA org ke default: hapus SEMUA section + field (built_in
     * maupun custom) untuk (org, module), lalu seed ulang dari default kanonik.
     * Force-delete supaya bersih (bukan soft-delete) agar unique constraint
     * (org, module, field_name) tidak bentrok saat seed ulang.
     */
    public function resetToDefault(string $orgId, string $module): void
    {
        ModuleCustomField::forOrg($orgId)->forModule($module)->forceDelete();
        ModuleCustomSection::forOrg($orgId)->forModule($module)->forceDelete();
        $this->seedDefaults($orgId, $module);
    }
}
