<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProcessingCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Org-scoped CRUD for naming categories (HR, FIN, IT, ...).
 *
 * - GET /processing-categories — paginated + searchable, for LazySearchSelect.
 * - POST /processing-categories — create from label (code auto-derived
 *   if missing). Used by the inline "Tambah …" affordance.
 */
class ProcessingCategoryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = ProcessingCategory::query()
            ->where('org_id', $user->org_id)
            ->orderBy('label');

        if ($request->filled('q')) {
            $search = $request->get('q');
            $query->where(function ($q) use ($search) {
                $q->where('label', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->get('per_page', 20), 100);
        $cursor = $request->get('cursor');

        // Simple cursor pagination on label asc
        if ($cursor) {
            $query->where('label', '>', $cursor);
        }

        $items = $query->limit($perPage + 1)->get();
        $hasMore = $items->count() > $perPage;
        $items = $items->take($perPage);
        $nextCursor = $hasMore ? $items->last()->label : null;

        return response()->json([
            'data' => $items,
            'next_cursor' => $nextCursor,
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'label' => 'required|string|max:100',
            'code' => 'nullable|string|max:16',
            'description' => 'nullable|string|max:255',
        ]);

        // Auto-derive code from label if not provided (first letters, uppercase)
        if (empty($data['code'])) {
            $data['code'] = $this->deriveCode($data['label']);
        } else {
            $data['code'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $data['code']));
        }

        // Ensure code uniqueness within tenant — append suffix if collision
        $baseCode = $data['code'];
        $suffix = 1;
        while (ProcessingCategory::where('org_id', $user->org_id)->where('code', $data['code'])->exists()) {
            $suffix++;
            $data['code'] = $baseCode . $suffix;
        }

        $data['org_id'] = $user->org_id;
        $data['created_by'] = $user->id;
        $data['counter_year'] = (int) date('Y');

        $category = ProcessingCategory::create($data);

        return response()->json(['data' => $category], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $category = ProcessingCategory::where('org_id', $user->org_id)->findOrFail($id);

        $data = $request->validate([
            'label' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:255',
        ]);
        // Code is immutable after creation — existing registration numbers depend on it.

        $category->update($data);
        return response()->json(['data' => $category]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $category = ProcessingCategory::where('org_id', $user->org_id)->findOrFail($id);
        $category->delete();
        return response()->json(['message' => 'Category deleted']);
    }

    /**
     * Derive a short uppercase code from a label, e.g. "Human Resources" → "HR".
     */
    private function deriveCode(string $label): string
    {
        $words = preg_split('/\s+/', trim($label));
        if (count($words) >= 2) {
            $code = '';
            foreach ($words as $word) {
                if ($word !== '') $code .= strtoupper(substr($word, 0, 1));
            }
            $code = preg_replace('/[^A-Z0-9]/', '', $code);
            if (strlen($code) >= 2) return substr($code, 0, 6);
        }
        // Single word — take first 3-4 chars
        $slug = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $label));
        return substr($slug ?: 'CAT', 0, 4);
    }
}
