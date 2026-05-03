<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PositionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        $positions = Position::where('org_id', $orgId)
            ->whereNull('deleted_at')
            ->with(['department:id,name'])
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $positions]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'department_id' => 'nullable|uuid',
            'level' => 'nullable|string|max:100',
            'description' => 'nullable|string',
        ]);

        $position = Position::create([
            'org_id' => $request->user()->org_id,
            'name' => $request->name,
            'department_id' => $request->department_id,
            'level' => $request->level,
            'description' => $request->description,
        ]);

        return response()->json([
            'data' => $position->load('department:id,name'),
            'message' => 'Jabatan berhasil ditambahkan',
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $position = Position::where('org_id', $request->user()->org_id)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'department_id' => 'nullable|uuid',
            'level' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $position->update($request->only(['name', 'department_id', 'level', 'description', 'is_active']));

        return response()->json([
            'data' => $position->load('department:id,name'),
            'message' => 'Jabatan berhasil diperbarui',
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $position = Position::where('org_id', $request->user()->org_id)->findOrFail($id);
        $position->delete();

        return response()->json(['message' => 'Jabatan berhasil dihapus']);
    }

    /**
     * Get DPO users for auto-fill in RoPA/DPIA wizards.
     */
    public function dpoUsers(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        $users = User::where('org_id', $orgId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->select('id', 'name', 'email', 'phone', 'position', 'role', 'department_id', 'position_id')
            ->orderByRaw("CASE role WHEN 'dpo' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $users]);
    }
}
