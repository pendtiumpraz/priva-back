<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ModuleComment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sprint C4: Threaded comments for RoPA / DPIA / Breach / etc records.
 */
class ModuleCommentController extends Controller
{
    private const ALLOWED_MODULES = ['ropa', 'dpia', 'breach', 'dsr', 'consent', 'data-discovery', 'contract-review', 'policy-review', 'vendor-risk', 'cross-border'];

    public function index(Request $request)
    {
        $data = $request->validate([
            'module' => ['required', Rule::in(self::ALLOWED_MODULES)],
            'record_id' => 'required|uuid',
        ]);

        $rootComments = ModuleComment::where('org_id', $request->user()->org_id)
            ->forRecord($data['module'], $data['record_id'])
            ->whereNull('parent_id')
            ->with(['user:id,name,email,role', 'children.user:id,name,email,role'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(['data' => $rootComments]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'module' => ['required', Rule::in(self::ALLOWED_MODULES)],
            'record_id' => 'required|uuid',
            'parent_id' => 'nullable|uuid|exists:module_comments,id',
            'comment' => 'required|string|max:5000',
        ]);

        $comment = ModuleComment::create([
            'org_id' => $request->user()->org_id,
            'module' => $data['module'],
            'record_id' => $data['record_id'],
            'user_id' => $request->user()->id,
            'parent_id' => $data['parent_id'] ?? null,
            'comment' => $data['comment'],
        ]);

        $comment->load('user:id,name,email,role');

        return response()->json(['message' => 'Komentar dipost', 'data' => $comment], 201);
    }

    public function update(Request $request, string $id)
    {
        $comment = ModuleComment::where('org_id', $request->user()->org_id)->findOrFail($id);

        // Only author or admin can edit
        if ($comment->user_id !== $request->user()->id && $request->user()->role !== 'admin' && $request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Tidak boleh edit komentar user lain'], 403);
        }

        $data = $request->validate(['comment' => 'required|string|max:5000']);
        $comment->update($data);

        return response()->json(['message' => 'Komentar diperbarui', 'data' => $comment->fresh(['user'])]);
    }

    public function destroy(Request $request, string $id)
    {
        $comment = ModuleComment::where('org_id', $request->user()->org_id)->findOrFail($id);

        if ($comment->user_id !== $request->user()->id && $request->user()->role !== 'admin' && $request->user()->role !== 'superadmin') {
            return response()->json(['message' => 'Tidak boleh hapus komentar user lain'], 403);
        }

        $comment->delete();

        return response()->json(['message' => 'Komentar dihapus']);
    }
}
