<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Ropa;
use App\Models\RopaDataFlow;
use App\Services\RopaDataFlowBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Peta alur data per RoPA — otomatis dan manual pada satu permukaan.
 *
 * Keduanya bukan dua mode yang saling meniadakan: peta selalu diturunkan
 * otomatis dari isi RoPA, dan penyuntingan manual menumpang di atasnya.
 * Memisahkannya menjadi dua peta terpisah akan melahirkan pertanyaan yang
 * tidak punya jawaban baik — mana yang berlaku ketika keduanya berbeda.
 */
class RopaDataFlowController extends Controller
{
    public function __construct(private readonly RopaDataFlowBuilder $builder) {}

    /** Peta gabungan, siap dirender. */
    public function show(Request $request, string $id): JsonResponse
    {
        $ropa = Ropa::with('informationSystems')->findOrFail($id);

        return response()->json(['data' => $this->builder->build($ropa)]);
    }

    /**
     * Simpan lapisan manual.
     *
     * Seluruh lapisan dikirim utuh, bukan sebagian, karena penyuntingan peta
     * bersifat kanvas: menghapus satu simpul dan memindahkan lainnya terjadi
     * dalam satu tarikan, dan pengiriman parsial akan membuat penghapusan
     * mustahil dibedakan dari field yang kebetulan tidak dikirim.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $ropa = Ropa::findOrFail($id);

        $data = $request->validate([
            'manual_nodes' => 'nullable|array',
            'manual_nodes.*.key' => 'required|string|max:191',
            'manual_nodes.*.type' => 'required|string|max:32',
            'manual_nodes.*.label' => 'required|string|max:255',
            'manual_nodes.*.description' => 'nullable|string',
            'manual_nodes.*.meta' => 'nullable|array',

            'manual_edges' => 'nullable|array',
            'manual_edges.*.key' => 'required|string|max:255',
            'manual_edges.*.from' => 'required|string|max:191',
            'manual_edges.*.to' => 'required|string|max:191',
            'manual_edges.*.label' => 'nullable|string|max:255',
            'manual_edges.*.kind' => 'nullable|string|max:32',

            'overrides' => 'nullable|array',
            'hidden_keys' => 'nullable|array',
            'hidden_keys.*' => 'string|max:255',
            'positions' => 'nullable|array',
            'notes' => 'nullable|string',
        ]);

        $flow = RopaDataFlow::updateOrCreate(
            ['ropa_id' => $ropa->id],
            [
                'org_id' => $ropa->org_id,
                'manual_nodes' => $data['manual_nodes'] ?? [],
                'manual_edges' => $data['manual_edges'] ?? [],
                'overrides' => $data['overrides'] ?? [],
                'hidden_keys' => array_values($data['hidden_keys'] ?? []),
                'positions' => $data['positions'] ?? [],
                'notes' => $data['notes'] ?? null,
                'updated_by' => $request->user()->id,
            ]
        );

        AuditLog::log('ropa', $ropa->id, 'data_flow_updated', [
            'manual_nodes' => count($flow->manual_nodes ?? []),
            'manual_edges' => count($flow->manual_edges ?? []),
            'hidden' => count($flow->hidden_keys ?? []),
        ], 'data_flow');

        return response()->json(['data' => $this->builder->build($ropa->fresh('informationSystems'))]);
    }

    /**
     * Kembalikan ke peta otomatis murni.
     *
     * Yang dihapus hanya lapisan manual; graf otomatisnya tidak pernah
     * disimpan sehingga tidak ada yang hilang secara permanen selain
     * penyuntingan pengguna itu sendiri.
     */
    public function reset(Request $request, string $id): JsonResponse
    {
        $ropa = Ropa::with('informationSystems')->findOrFail($id);

        $deleted = (bool) RopaDataFlow::where('ropa_id', $ropa->id)->delete();
        if ($deleted) {
            AuditLog::log('ropa', $ropa->id, 'data_flow_reset', null, 'data_flow');
        }

        return response()->json([
            'message' => $deleted
                ? 'Peta dikembalikan ke hasil otomatis.'
                : 'Peta sudah dalam keadaan otomatis.',
            'data' => $this->builder->build($ropa),
        ]);
    }
}
