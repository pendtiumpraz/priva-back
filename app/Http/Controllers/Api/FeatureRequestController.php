<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeatureRequest;
use Illuminate\Http\Request;

class FeatureRequestController extends Controller
{
    /**
     * List feature requests (user sees own org, admin sees all)
     */
    public function index(Request $request)
    {
        $query = FeatureRequest::with('user:id,name,email,role');

        // Admin sees all, regular users see their org's requests
        if ($request->user()->role !== 'admin') {
            $query->where('org_id', $request->user()->org_id);
        }

        if ($request->get('trash')) {
            $query->onlyTrashed();
        }

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $requests = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $requests]);
    }

    /**
     * Submit a feature request
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'required|in:module,ui,integration,security,performance,other',
            'priority' => 'sometimes|in:low,medium,high,critical',
        ]);

        $fr = FeatureRequest::create([
            'org_id' => $request->user()->org_id,
            'user_id' => $request->user()->id,
            'title' => $request->title,
            'description' => $request->description,
            'category' => $request->category,
            'priority' => $request->priority ?? 'medium',
            'status' => 'submitted',
        ]);

        return response()->json([
            'message' => 'Feature request submitted!',
            'data' => $fr->load('user:id,name,email,role'),
        ], 201);
    }

    /**
     * Show detail
     */
    public function show(string $id)
    {
        $fr = FeatureRequest::with('user:id,name,email,role')->findOrFail($id);
        return response()->json(['data' => $fr]);
    }

    /**
     * Admin update status & notes
     */
    public function update(Request $request, string $id)
    {
        $fr = FeatureRequest::findOrFail($id);

        $request->validate([
            'status' => 'sometimes|in:submitted,reviewing,planned,in_progress,completed,rejected',
            'admin_notes' => 'sometimes|nullable|string',
            'priority' => 'sometimes|in:low,medium,high,critical',
        ]);

        $fr->update($request->only(['status', 'admin_notes', 'priority']));

        return response()->json([
            'message' => 'Feature request updated',
            'data' => $fr->fresh()->load('user:id,name,email,role'),
        ]);
    }

    /**
     * Upvote
     */
    public function upvote(string $id)
    {
        $fr = FeatureRequest::findOrFail($id);
        $fr->increment('votes');

        return response()->json([
            'message' => 'Voted!',
            'data' => $fr->fresh(),
        ]);
    }

    /**
     * Soft delete
     */
    public function destroy(string $id)
    {
        FeatureRequest::findOrFail($id)->delete();
        return response()->json(['message' => 'Moved to trash']);
    }

    /**
     * Restore
     */
    public function restore(string $id)
    {
        $fr = FeatureRequest::onlyTrashed()->findOrFail($id);
        $fr->restore();
        return response()->json(['message' => 'Restored', 'data' => $fr]);
    }

    /**
     * Force delete
     */
    public function forceDelete(string $id)
    {
        FeatureRequest::onlyTrashed()->findOrFail($id)->forceDelete();
        return response()->json(['message' => 'Permanently deleted']);
    }
}
