<?php

namespace App\Services;

use App\Models\ModuleCustomField;
use App\Models\ModuleCustomSection;

/**
 * Wizard schema resolution for RoPA / DPIA — Phase 1 (CUSTOM_WIZARD_PLAN.md §5.2).
 *
 * Combines the (currently hardcoded) built-in section skeleton with the
 * org-global custom sections + fields. Returns an ordered array of section
 * descriptors that the FE can render generically.
 *
 * Each section descriptor exposes a `source` discriminator:
 *   - `built_in`   → frozen, FE renders from its own constants. Backend
 *                    only emits a stub here so consumers know the order.
 *   - `org_custom` → editable, fully described including its fields.
 *
 * Per-record extras (Level 3 in the plan) are NOT handled here — they live
 * inline in `wizard_data.per_record_extras[]` on the record itself.
 */
class WizardSchemaService
{
    public const SUPPORTED_MODULES = ['ropa', 'dpia'];

    /**
     * Get the merged wizard schema for an org+module.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSchema(string $orgId, string $module): array
    {
        if (! in_array($module, self::SUPPORTED_MODULES, true)) {
            return [];
        }

        $builtIn = $this->loadBuiltinSchema($module);

        $orgSections = ModuleCustomSection::forOrg($orgId)
            ->forModule($module)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        $orgFields = ModuleCustomField::forOrg($orgId)
            ->forModule($module)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get()
            ->groupBy('section_key');

        $merged = [];

        foreach ($builtIn as $sec) {
            $merged[] = array_merge(
                ['source' => 'built_in', 'sort_order' => $sec['sort_order'] ?? 0],
                $sec,
            );
        }

        foreach ($orgSections as $sec) {
            $sectionFields = ($orgFields[$sec->section_key] ?? collect())
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
        // org-custom (100+); ties (rare) preserve original insertion order
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
     * Hardcoded built-in section skeleton. Intentionally returns []
     * for now — the FE wizards still render their built-in sections
     * from their own TypeScript constants (no backend dependency yet).
     *
     * When export / AI-mapping work needs the built-in shape, populate
     * this with `{ section_key, label, sort_order, fields[] }` rows.
     * See CUSTOM_WIZARD_PLAN.md §5.2 + Ropa::WIZARD_SECTIONS for the
     * canonical ordering.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadBuiltinSchema(string $module): array
    {
        return [];
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
