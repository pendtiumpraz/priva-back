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

        $allCustomFields = ModuleCustomField::forOrg($orgId)
            ->forModule($module)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->groupBy('section_key');

        $orgSections = ModuleCustomSection::forOrg($orgId)
            ->forModule($module)
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
            ->active()
            ->orderBy('section_key')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }
}
