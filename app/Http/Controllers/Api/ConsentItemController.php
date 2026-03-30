<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentCollectionPoint;
use App\Models\ConsentItem;
use Illuminate\Http\Request;

class ConsentItemController extends Controller
{
    /**
     * Create a new consent item for a collection point
     */
    public function store(Request $request)
    {
        $request->validate([
            'collection_point_id' => 'required|uuid',
            'title' => 'required|string',
            'description' => 'nullable|string',
            'full_text' => 'nullable|string',
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
            'full_text' => $request->full_text,
            'version' => $request->version ?? '1.0',
            'is_required' => $request->is_required ?? false,
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json(['message' => 'Consent item created successfully', 'data' => $item], 201);
    }

    /**
     * Update an existing consent item
     */
    public function update(Request $request, string $id)
    {
        $item = ConsentItem::with('collectionPoint')->findOrFail($id);

        if ($request->user()->role !== 'superadmin' && $item->collectionPoint->org_id !== $request->user()->org_id) {
            abort(403, 'Unauthorized');
        }

        $request->validate([
            'title' => 'string',
            'description' => 'nullable|string',
            'full_text' => 'nullable|string',
            'version' => 'nullable|string',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $item->update($request->all());

        return response()->json(['message' => 'Consent item updated successfully', 'data' => $item]);
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
