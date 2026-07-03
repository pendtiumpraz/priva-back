<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsentItemController extends Controller
{
    /**
     * Create a new consent item for a collection point.
     *
     * Kind purity: the parent collection is single-kind. A cookie_banner
     * collection may only hold COOKIE_CATEGORIES; an app_consent collection
     * may only hold the non-cookie categories. The `category` was previously
     * dropped on create (defaulting the DB to 'essential' — a cookie category
     * on every item, incl. consent ones). It is now validated + persisted.
     */
    public function store(Request $request)
    {
        $request->validate([
            'collection_point_id' => 'required|uuid',
            'title' => 'required|string',
            'description' => 'nullable|string',
            'specific_purpose' => 'nullable|string',
            'full_text' => 'nullable|string',
            'category' => ['nullable', 'string', Rule::in(ConsentItem::CATEGORIES)],
            'cookie_keys' => 'nullable|array',
            'version' => 'nullable|string',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $cp = ConsentCollectionPoint::findOrFail($request->collection_point_id);

        if ($request->user()->role !== 'superadmin' && $cp->org_id !== $request->user()->org_id) {
            abort(403, 'Unauthorized');
        }

        $item = ConsentItem::create([
            'collection_point_id' => $cp->id,
            'title' => $request->title,
            'description' => $request->description,
            'specific_purpose' => $request->input('specific_purpose'),
            'full_text' => $request->full_text,
            'category' => $this->resolveCategory($cp, $request->input('category')),
            'cookie_keys' => $request->input('cookie_keys', []),
            'version' => $request->version ?? '1.0',
            'is_required' => $request->is_required ?? false,
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json(['message' => 'Consent item created successfully', 'data' => $item], 201);
    }

    /**
     * Update an existing consent item. Uses a whitelisted, kind-checked payload
     * (never a blind $request->all() — that let a mismatched `category` slip in).
     */
    public function update(Request $request, string $id)
    {
        $item = ConsentItem::with('collectionPoint')->findOrFail($id);

        if ($request->user()->role !== 'superadmin' && $item->collectionPoint->org_id !== $request->user()->org_id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'title' => 'sometimes|string',
            'description' => 'nullable|string',
            'specific_purpose' => 'nullable|string',
            'full_text' => 'nullable|string',
            'category' => ['nullable', 'string', Rule::in(ConsentItem::CATEGORIES)],
            'cookie_keys' => 'nullable|array',
            'version' => 'nullable|string',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ]);

        // Whitelist the fillable keys the caller actually sent.
        $payload = array_intersect_key($validated, array_flip([
            'title', 'description', 'specific_purpose', 'full_text',
            'cookie_keys', 'version', 'is_required', 'is_active',
        ]));

        // Only re-resolve category when the caller explicitly sent one; this
        // enforces the kind↔category guard and rejects a mismatch with 422.
        if ($request->has('category')) {
            $payload['category'] = $this->resolveCategory($item->collectionPoint, $request->input('category'));
        }

        $item->update($payload);

        return response()->json(['message' => 'Consent item updated successfully', 'data' => $item]);
    }

    /**
     * Resolve + validate an item category against the parent collection's kind.
     * Absent category → sensible per-kind default (cookie: 'essential',
     * app: 'other'). A category that doesn't belong to the collection's kind
     * throws a 422 with a clear message.
     */
    private function resolveCategory(ConsentCollectionPoint $cp, ?string $category): string
    {
        $allowed = ConsentItem::categoriesForKind($cp->kind);

        if ($category === null || $category === '') {
            return $cp->kind === ConsentCollectionPoint::KIND_COOKIE ? 'essential' : 'other';
        }

        if (! in_array($category, $allowed, true)) {
            $message = $cp->kind === ConsentCollectionPoint::KIND_COOKIE
                ? "Kategori \"{$category}\" tidak berlaku untuk cookie banner. Pilih salah satu: ".implode(', ', $allowed).'.'
                : "Kategori \"{$category}\" adalah kategori cookie dan tidak boleh dipakai pada consent (app). Pilih salah satu: ".implode(', ', $allowed).'.';

            throw ValidationException::withMessages(['category' => $message]);
        }

        return $category;
    }

    /**
     * Delete a consent item
     */
    public function destroy(Request $request, string $id)
    {
        $item = ConsentItem::with('collectionPoint')->findOrFail($id);

        if ($request->user()->role !== 'superadmin' && $item->collectionPoint->org_id !== $request->user()->org_id) {
            abort(403, 'Unauthorized');
        }

        $item->delete();
        return response()->json(['message' => 'Consent item deleted successfully']);
    }
}
