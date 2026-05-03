<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModuleCustomField;
use App\Models\ModuleTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprint C1: Per-tenant custom fields and templates for RoPA / DPIA.
 */
class CustomFieldController extends Controller
{
    private const ALLOWED_MODULES = ['ropa', 'dpia'];

    private const ALLOWED_TYPES = ['text', 'textarea', 'select', 'multiselect', 'date', 'number', 'boolean'];

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
            'section_key' => 'nullable|string|max:64',
            'field_name' => 'required|string|max:64|regex:/^[a-z][a-z0-9_]*$/',
            'field_label' => 'required|string|max:255',
            'field_type' => ['required', Rule::in(self::ALLOWED_TYPES)],
            'field_options' => 'nullable|array',
            'help_text' => 'nullable|string',
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
        ]);

        $orgId = $request->user()->org_id;
        $exists = ModuleCustomField::where('org_id', $orgId)
            ->forModule($data['module'])
            ->where('field_name', $data['field_name'])
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Field name sudah dipakai di module ini'], 422);
        }

        $field = ModuleCustomField::create(array_merge($data, [
            'org_id' => $orgId,
            'section_key' => $data['section_key'] ?? 'custom',
            'is_required' => $data['is_required'] ?? false,
            'sort_order' => $data['sort_order'] ?? (
                (int) ModuleCustomField::where('org_id', $orgId)->forModule($data['module'])->max('sort_order') + 1
            ),
        ]));

        return response()->json(['message' => 'Custom field dibuat', 'data' => $field], 201);
    }

    public function update(Request $request, string $id)
    {
        $field = ModuleCustomField::where('org_id', $request->user()->org_id)->findOrFail($id);

        $data = $request->validate([
            'field_label' => 'sometimes|string|max:255',
            'field_type' => ['sometimes', Rule::in(self::ALLOWED_TYPES)],
            'field_options' => 'nullable|array',
            'help_text' => 'nullable|string',
            'is_required' => 'nullable|boolean',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $field->update($data);

        return response()->json(['message' => 'Custom field diperbarui', 'data' => $field->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $field = ModuleCustomField::where('org_id', $request->user()->org_id)->findOrFail($id);
        $field->delete();

        return response()->json(['message' => 'Custom field dihapus']);
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
