<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dpia;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Pihak ketiga yang berada di dalam lingkup sebuah DPIA (pivot `dpia_vendor`).
 *
 * Keadaan awalnya diwarisi dari RoPA yang dinilai (lihat migrasi
 * 2026_09_13_000005), tetapi sesudah itu berdiri sendiri: sebuah penilaian bisa
 * sengaja mempersempit lingkupnya ke satu prosesor saja, atau memperluasnya ke
 * subprosesor yang belum tercatat di RoPA.
 */
class DpiaThirdPartyController extends Controller
{
    public function index(Request $request, string $id)
    {
        $dpia = $this->cari($request, $id);

        return response()->json([
            'data' => $dpia->vendors()->get(['vendors.id', 'vendors.name', 'vendors.type'])
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'role' => $v->pivot->role,
                    'notes' => $v->pivot->notes,
                ])->values(),
        ]);
    }

    /**
     * Mengganti SELURUH daftar pihak ketiga DPIA ini.
     *
     * Tiap id diperiksa kepemilikannya lebih dulu. Menggantungkan penjagaan pada
     * scope global tidak cukup di sini: pivotnya ditulis lewat query builder,
     * yang memang tidak melewati scope Eloquent mana pun.
     */
    public function sync(Request $request, string $id)
    {
        $dpia = $this->cari($request, $id);

        $request->validate([
            'third_parties' => 'present|array|max:200',
            'third_parties.*.id' => 'required|uuid',
            'third_parties.*.role' => ['nullable', Rule::in(Vendor::ROLES)],
            'third_parties.*.notes' => 'nullable|string|max:1000',
        ]);

        /** @var array<int,array<string,mixed>> $diminta */
        $diminta = $request->input('third_parties', []);
        $ids = array_values(array_unique(array_map(fn ($t) => (string) $t['id'], $diminta)));

        $sah = Vendor::where('org_id', $dpia->org_id)->whereIn('id', $ids)->pluck('id')->all();
        if (count($sah) !== count($ids)) {
            // Pesannya sengaja tidak menyebut mana yang tidak sah: membedakan
            // "tidak ada" dari "milik tenant lain" memberi tahu penanya bahwa
            // id itu ada di suatu tempat.
            throw ValidationException::withMessages([
                'third_parties' => 'Ada pihak ketiga yang tidak ditemukan.',
            ]);
        }

        DB::transaction(function () use ($dpia, $diminta) {
            DB::table('dpia_vendor')->where('dpia_id', $dpia->id)->delete();

            $baris = [];
            foreach ($diminta as $t) {
                $baris[$t['id']] = [
                    'dpia_id' => $dpia->id,
                    'vendor_id' => $t['id'],
                    'org_id' => $dpia->org_id,
                    'role' => $t['role'] ?? Vendor::ROLE_PROCESSOR,
                    'notes' => $t['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            if ($baris !== []) {
                DB::table('dpia_vendor')->insert(array_values($baris));
            }
        });

        AuditLog::log('dpia', $dpia->id, 'third_parties.synced', [
            'count' => count($ids),
        ]);

        return response()->json([
            'message' => 'Pihak ketiga dalam lingkup DPIA diperbarui.',
            'data' => ['count' => count($ids)],
        ]);
    }

    private function cari(Request $request, string $id): Dpia
    {
        $dpia = Dpia::where('org_id', $request->user()->org_id)->find($id);

        if (! $dpia) {
            abort(404, 'DPIA tidak ditemukan.');
        }

        return $dpia;
    }
}
