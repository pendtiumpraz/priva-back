<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Position;
use App\Models\User;
use App\Support\AssignmentScope;
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
     * Daftar orang untuk pemilih di wizard RoPA/DPIA.
     *
     * Namanya menyesatkan dan sengaja dipertahankan demi pemanggil lama: yang
     * dikembalikan adalah SELURUH user aktif organisasi, bukan hanya DPO. Itu
     * memang dibutuhkan, karena satu daftar ini memberi makan TIGA pemilih yang
     * berbeda sifatnya di wizard RoPA:
     *
     *   Pejabat PDP (dpo_list)          → HANYA yang benar-benar DPO
     *   Process Owner / PIC (pic_list)  → siapa pun
     *   PIC penerima internal           → siapa pun
     *
     * Karena itu penyaringannya TIDAK dilakukan di sini — menyaring endpoint
     * akan mengosongkan dua pemilih yang lain. Yang dikirim adalah penandanya,
     * `is_dpo`, dan pemilih Pejabat PDP-lah yang memakainya.
     *
     * Penandanya memakai predikat yang SAMA dengan AssignmentScope: role global
     * `dpo` ATAU nama tenant role `dpo`. Tenant yang menandai DPO lewat role
     * kustom karena itu tidak berakhir dengan pemilih kosong.
     */
    public function dpoUsers(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        $users = User::where('org_id', $orgId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->select('id', 'name', 'email', 'phone', 'position', 'role', 'department_id', 'position_id', 'tenant_role_id')
            ->with(['department:id,name', 'tenantRole:id,name'])
            ->orderByRaw("CASE role WHEN 'dpo' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get()
            ->map(function (User $u) {
                $u->setAttribute('is_dpo', AssignmentScope::berperanDpo($u));

                return $u;
            });

        return response()->json(['data' => $users]);
    }
}
