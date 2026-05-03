<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ModuleCustomField;
use App\Models\ModuleCustomSection;
use App\Models\ModuleTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Sprint C1: Per-tenant custom fields and templates for RoPA / DPIA.
 */
class CustomFieldController extends Controller
{
    private const ALLOWED_MODULES = ['ropa', 'dpia'];

    private const ALLOWED_TYPES = ['text', 'textarea', 'select', 'multiselect', 'date', 'number', 'boolean', 'tags'];

    private const FIELD_NAME_REGEX = '/^[a-z][a-z0-9_]*$/';

    // ---------------------------------------------------------------------
    // Custom Fields
    // ---------------------------------------------------------------------

    public function index(Request $request)
    {
        $module = $request->query('module');
        if (! in_array($module, self::ALLOWED_MODULES, true)) {
            return response()->json(['message' => 'Invalid module'], 422);
        }

        $fields = ModuleCustomField::where('org_id', $request->user()->org_id)
            ->forModule($module)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $fields]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'module' => ['required', Rule::in(self::ALLOWED_MODULES)],
            'section_key' => ['nullable', 'string', 'max:64', 'regex:'.self::FIELD_NAME_REGEX],
            'field_name' => ['required', 'string', 'max:64', 'regex:'.self::FIELD_NAME_REGEX],
            'field_label' => 'required|string|max:191',
            'field_type' => ['required', Rule::in(self::ALLOWED_TYPES)],
            'field_options' => 'nullable|array',
            'help_text' => 'nullable|string',
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:99999',
        ]);

        $orgId = $request->user()->org_id;
        $sectionKey = $data['section_key'] ?? 'custom';

        if ($err = $this->validateOptionsForType($data['field_type'], $data['field_options'] ?? null)) {
            return $err;
        }

        $exists = ModuleCustomField::forOrg($orgId)
            ->forModule($data['module'])
            ->where('field_name', $data['field_name'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Field name sudah dipakai di module ini'], 422);
        }

        $field = ModuleCustomField::create(array_merge($data, [
            'org_id' => $orgId,
            'section_key' => $sectionKey,
            'is_required' => $data['is_required'] ?? false,
            'sort_order' => $data['sort_order'] ?? (
                (int) ModuleCustomField::forOrg($orgId)
                    ->forModule($data['module'])
                    ->where('section_key', $sectionKey)
                    ->max('sort_order') + 1
            ),
        ]));

        $this->auditField($field, 'created', $field->only([
            'module', 'section_key', 'field_name', 'field_label', 'field_type',
        ]));

        return response()->json(['message' => 'Custom field dibuat', 'data' => $field], 201);
    }

    /**
     * Add a field to a specific section by section id.
     * POST /api/custom-sections/{sectionId}/fields
     */
    public function storeForSection(Request $request, string $sectionId): JsonResponse
    {
        $orgId = $request->user()->org_id;
        $section = ModuleCustomSection::forOrg($orgId)->findOrFail($sectionId);

        $data = $request->validate([
            'field_name' => ['required', 'string', 'max:64', 'regex:'.self::FIELD_NAME_REGEX],
            'field_label' => 'required|string|max:191',
            'field_type' => ['required', Rule::in(self::ALLOWED_TYPES)],
            'field_options' => 'nullable|array',
            'help_text' => 'nullable|string',
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:99999',
        ]);

        if ($err = $this->validateOptionsForType($data['field_type'], $data['field_options'] ?? null)) {
            return $err;
        }

        $exists = ModuleCustomField::forOrg($orgId)
            ->forModule($section->module)
            ->where('field_name', $data['field_name'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Field name sudah dipakai di module ini'], 422);
        }

        $field = ModuleCustomField::create(array_merge($data, [
            'org_id' => $orgId,
            'module' => $section->module,
            'section_key' => $section->section_key,
            'is_required' => $data['is_required'] ?? false,
            'sort_order' => $data['sort_order'] ?? (
                (int) ModuleCustomField::forOrg($orgId)
                    ->forModule($section->module)
                    ->where('section_key', $section->section_key)
                    ->max('sort_order') + 1
            ),
        ]));

        $this->auditField($field, 'created', $field->only([
            'module', 'section_key', 'field_name', 'field_label', 'field_type',
        ]));

        return response()->json([
            'message' => 'Custom field dibuat.',
            'data' => $field,
        ], 201);
    }

    public function update(Request $request, string $id)
    {
        $field = ModuleCustomField::forOrg($request->user()->org_id)->findOrFail($id);

        $data = $request->validate([
            'field_label' => 'sometimes|string|max:191',
            'field_type' => ['sometimes', Rule::in(self::ALLOWED_TYPES)],
            'field_options' => 'nullable|array',
            'help_text' => 'nullable|string',
            'is_required' => 'sometimes|boolean',
            'sort_order' => 'sometimes|integer|min:0|max:99999',
            'is_active' => 'sometimes|boolean',
            'section_key' => ['sometimes', 'string', 'max:64', 'regex:'.self::FIELD_NAME_REGEX],
        ]);

        $effectiveType = $data['field_type'] ?? $field->field_type;
        $effectiveOptions = array_key_exists('field_options', $data) ? $data['field_options'] : $field->field_options;
        if ($err = $this->validateOptionsForType($effectiveType, $effectiveOptions)) {
            return $err;
        }

        $before = $field->only(array_keys($data));
        $field->update($data);
        $after = $field->fresh()->only(array_keys($data));

        $this->auditField($field, 'updated', ['before' => $before, 'after' => $after]);

        return response()->json(['message' => 'Custom field diperbarui', 'data' => $field->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $field = ModuleCustomField::forOrg($request->user()->org_id)->findOrFail($id);
        $snapshot = $field->only(['module', 'section_key', 'field_name']);
        $field->delete();

        $this->auditField($field, 'deleted', $snapshot);

        return response()->json(['message' => 'Custom field dihapus']);
    }

    /**
     * Bulk reorder. Body: { items: [{ id, sort_order }, ...] }.
     */
    public function reorder(Request $request): JsonResponse
    {
        $payload = $request->input('items', $request->all());
        $request->merge(['items' => $payload]);

        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|string|max:64',
            'items.*.sort_order' => 'required|integer|min:0|max:99999',
        ]);

        $orgId = $request->user()->org_id;
        $ids = collect($data['items'])->pluck('id')->all();

        $fields = ModuleCustomField::forOrg($orgId)->whereIn('id', $ids)->get()->keyBy('id');
        if ($fields->count() !== count($ids)) {
            return response()->json([
                'message' => 'Salah satu field tidak ditemukan dalam org ini.',
            ], 404);
        }

        DB::transaction(function () use ($data, $fields) {
            foreach ($data['items'] as $item) {
                $f = $fields->get($item['id']);
                if ($f && (int) $f->sort_order !== (int) $item['sort_order']) {
                    $f->update(['sort_order' => (int) $item['sort_order']]);
                }
            }
        });

        try {
            AuditLog::log('wizard_schema', 'bulk', 'field.reorder', ['items' => $data['items']], 'field');
        } catch (\Throwable $e) {
            \Log::warning('wizard_schema field reorder audit failed: '.$e->getMessage());
        }

        return response()->json([
            'message' => 'Urutan field diperbarui.',
            'data' => ModuleCustomField::forOrg($orgId)
                ->whereIn('id', $ids)
                ->orderBy('sort_order')
                ->get(),
        ]);
    }

    /**
     * Validates that select / multiselect fields carry usable options.
     * Returns null when valid, or a 422 JsonResponse otherwise.
     */
    private function validateOptionsForType(string $type, $options): ?JsonResponse
    {
        if (! in_array($type, ['select', 'multiselect'], true)) {
            return null;
        }
        if (! is_array($options) || count($options) === 0) {
            return response()->json([
                'message' => "Field type '{$type}' wajib punya field_options (array of choices).",
            ], 422);
        }

        return null;
    }

    private function auditField(ModuleCustomField $field, string $action, array $changes): void
    {
        try {
            AuditLog::log(
                'wizard_schema',
                $field->id,
                'field.'.$action,
                array_merge($changes, [
                    'module' => $field->module,
                    'section_key' => $field->section_key,
                    'field_name' => $field->field_name,
                ]),
                'field',
            );
        } catch (\Throwable $e) {
            \Log::warning('wizard_schema field audit failed: '.$e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // Templates
    // ---------------------------------------------------------------------

    public function templates(Request $request)
    {
        $module = $request->query('module');
        if (! in_array($module, self::ALLOWED_MODULES, true)) {
            return response()->json(['message' => 'Invalid module'], 422);
        }

        $templates = ModuleTemplate::where('org_id', $request->user()->org_id)
            ->forModule($module)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $templates]);
    }

    public function storeTemplate(Request $request)
    {
        $data = $request->validate([
            'module' => ['required', Rule::in(self::ALLOWED_MODULES)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'template_data' => 'required|array',
            'is_default' => 'nullable|boolean',
        ]);

        $orgId = $request->user()->org_id;

        if (! empty($data['is_default'])) {
            ModuleTemplate::where('org_id', $orgId)
                ->forModule($data['module'])
                ->update(['is_default' => false]);
        }

        $template = ModuleTemplate::create(array_merge($data, [
            'org_id' => $orgId,
            'created_by' => $request->user()->id,
            'is_default' => $data['is_default'] ?? false,
        ]));

        return response()->json(['message' => 'Template tersimpan', 'data' => $template], 201);
    }

    public function updateTemplate(Request $request, string $id)
    {
        $template = ModuleTemplate::where('org_id', $request->user()->org_id)->findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'template_data' => 'sometimes|array',
            'is_default' => 'nullable|boolean',
        ]);

        if (! empty($data['is_default'])) {
            ModuleTemplate::where('org_id', $template->org_id)
                ->forModule($template->module)
                ->where('id', '!=', $template->id)
                ->update(['is_default' => false]);
        }

        $template->update($data);

        return response()->json(['message' => 'Template diperbarui', 'data' => $template->fresh()]);
    }

    public function destroyTemplate(Request $request, string $id)
    {
        $template = ModuleTemplate::where('org_id', $request->user()->org_id)->findOrFail($id);
        $template->delete();

        return response()->json(['message' => 'Template dihapus']);
    }
}
