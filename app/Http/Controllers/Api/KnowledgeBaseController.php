<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeBaseSection;
use Illuminate\Http\Request;

/**
 * Knowledge Base CRUD with strict role separation:
 *  - Superadmin → hanya kelola SHARED RULE prompting (org_id = null).
 *    Tidak bisa buat/edit/hapus section milik tenant manapun.
 *  - Tenant admin → hanya kelola KB milik tenant-nya sendiri.
 *    Shared rules tetap visible (read-only) sebagai konteks referensi.
 */
class KnowledgeBaseController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $q = KnowledgeBaseSection::query()->orderBy('sort_order');

        if (in_array($user->role, ['root','superadmin'], true)) {
            // Superadmin melihat shared rules + bisa preview per-tenant via ?org_id=.
            if ($request->filled('org_id')) {
                $q->where('org_id', $request->org_id);
            } else {
                // default: hanya shared rules (yang bisa mereka edit)
                $q->whereNull('org_id');
            }
        } else {
            // Tenant admin: shared + milik tenant sendiri.
            $q->visibleTo($user->org_id);
        }

        return response()->json(['data' => $q->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'module_key' => 'required|string|max:64|regex:/^[a-z][a-z0-9_-]*$/',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'keywords' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $user = $request->user();

        // Role-locked ownership:
        //   Superadmin → selalu buat shared rule (org_id = null)
        //   Tenant     → selalu org_id = user.org_id
        $orgId = in_array($user->role, ['root','superadmin'], true) ? null : $user->org_id;

        if (!$orgId && ! in_array($user->role, ['root','superadmin'], true)) {
            return response()->json(['message' => 'User tanpa org_id tidak bisa membuat section'], 422);
        }

        $exists = KnowledgeBaseSection::query()
            ->where('module_key', $data['module_key'])
            ->where(function ($q) use ($orgId) {
                if ($orgId) $q->where('org_id', $orgId);
                else $q->whereNull('org_id');
            })
            ->exists();
        if ($exists) {
            return response()->json(['message' => 'Section dengan module_key ini sudah ada'], 422);
        }

        $section = KnowledgeBaseSection::create(array_merge($data, [
            'org_id' => $orgId,
            'is_active' => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
            'keywords' => $data['keywords'] ?? '',
        ]));

        return response()->json(['message' => 'Section dibuat', 'data' => $section], 201);
    }

    public function update(Request $request, string $id)
    {
        $user = $request->user();
        $section = KnowledgeBaseSection::findOrFail($id);

        // Superadmin hanya boleh edit shared rule (org_id = null).
        if (in_array($user->role, ['root','superadmin'], true)) {
            if ($section->org_id !== null) {
                return response()->json(['message' => 'Superadmin hanya boleh mengelola shared rule. Minta tenant admin untuk edit KB tenant-nya sendiri.'], 403);
            }
        } else {
            // Tenant admin hanya boleh edit section milik tenant-nya.
            if ($section->org_id !== $user->org_id) {
                return response()->json(['message' => 'Tidak boleh edit section tenant lain atau shared rule. Shared rule hanya bisa diedit superadmin.'], 403);
            }
        }

        $data = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'keywords' => 'nullable|string',
            'sort_order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $section->update($data);
        return response()->json(['message' => 'Section diperbarui', 'data' => $section->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $section = KnowledgeBaseSection::findOrFail($id);

        if (in_array($user->role, ['root','superadmin'], true)) {
            if ($section->org_id !== null) {
                return response()->json(['message' => 'Superadmin hanya boleh menghapus shared rule.'], 403);
            }
        } else {
            if ($section->org_id !== $user->org_id) {
                return response()->json(['message' => 'Tidak boleh hapus section tenant lain atau shared rule.'], 403);
            }
        }

        $section->delete();
        return response()->json(['message' => 'Section dihapus']);
    }
}
