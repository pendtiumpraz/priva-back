<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        $departments = Department::where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->with(['parent:id,name', 'head:id,name,email', 'children:id,parent_id,name'])
            ->withCount(['positions', 'users'])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $departments]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'nullable|string|max:50',
            'parent_id' => 'nullable|uuid',
            'head_user_id' => 'nullable|uuid',
            'description' => 'nullable|string',
        ]);

        $department = Department::create([
            'org_id' => $request->user()->org_id,
            'name' => $request->name,
            'code' => $request->code,
            'parent_id' => $request->parent_id,
            'head_user_id' => $request->head_user_id,
            'description' => $request->description,
        ]);

        return response()->json([
            'data' => $department->load(['parent:id,name', 'head:id,name,email']),
            'message' => 'Departemen berhasil ditambahkan',
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $department = Department::where('org_id', $request->user()->org_id)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => 'nullable|string|max:50',
            'parent_id' => 'nullable|uuid',
            'head_user_id' => 'nullable|uuid',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $department->update($request->only(['name', 'code', 'parent_id', 'head_user_id', 'description', 'is_active']));

        return response()->json([
            'data' => $department->load(['parent:id,name', 'head:id,name,email']),
            'message' => 'Departemen berhasil diperbarui',
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $department = Department::where('org_id', $request->user()->org_id)->findOrFail($id);
        $department->delete();

        return response()->json(['message' => 'Departemen berhasil dihapus']);
    }
}
