<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PpdpAppointment;
use App\Services\PpdpObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Penunjukan PPDP (PP 33/2026 Pasal 142-143).
 *
 * Menutup gap audit: platform hanya punya role RBAC `dpo`, belum ada registri
 * penunjukan PPDP yang sah + evaluator kewajiban Pasal 142. Controller ini
 * mengelola registri penunjukan dan mengembalikan status kewajiban org.
 */
class PpdpController extends Controller
{
    public function __construct(private PpdpObligationService $obligation) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $request->user()->org_id;

        $items = PpdpAppointment::query()
            ->where('org_id', $orgId)
            ->orderByDesc('status')      // active dulu
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $items,
            'meta' => [
                'obligation' => $this->obligation->evaluate($orgId),
                'triggers' => PpdpAppointment::TRIGGERS,
                'statuses' => PpdpAppointment::STATUSES,
            ],
        ]);
    }

    /** Status kewajiban PPDP untuk org (dipakai banner/dashboard). */
    public function obligation(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->obligation->evaluate($request->user()->org_id),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateData($request);
        $orgId = $request->user()->org_id;

        $item = PpdpAppointment::create(array_merge($data, [
            'org_id' => $orgId,
            'status' => $data['status'] ?? 'active',
            'created_by' => $request->user()->id,
        ]));

        AuditLog::log('ppdp', $item->id, 'ppdp_created', [
            'appointee' => $item->appointee_name,
            'is_mandatory' => $item->is_mandatory,
        ], 'ppdp');

        return response()->json(['data' => $item], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $orgId = $request->user()->org_id;
        $item = PpdpAppointment::where('org_id', $orgId)->findOrFail($id);

        $data = $this->validateData($request, true);
        $before = $item->only(array_keys($data));
        $item->update($data);

        AuditLog::log('ppdp', $item->id, 'ppdp_updated', [
            'before' => $before,
            'after' => $data,
        ], 'ppdp');

        return response()->json(['data' => $item->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $orgId = $request->user()->org_id;
        $item = PpdpAppointment::where('org_id', $orgId)->findOrFail($id);
        $item->delete();

        AuditLog::log('ppdp', $item->id, 'ppdp_deleted', [
            'appointee' => $item->appointee_name,
        ], 'ppdp');

        return response()->json(['message' => 'Penunjukan PPDP dihapus.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'appointee_name' => "{$req}|string|max:200",
            'appointee_email' => 'nullable|email|max:200',
            'appointee_phone' => 'nullable|string|max:60',
            'appointee_position' => 'nullable|string|max:200',
            'is_internal' => 'nullable|boolean',
            'sk_number' => 'nullable|string|max:120',
            'sk_date' => 'nullable|date',
            'appointment_basis' => 'nullable|string',
            'scope' => 'nullable|string',
            'reporting_line' => 'nullable|string|max:200',
            'term_start' => 'nullable|date',
            'term_end' => 'nullable|date',
            'status' => 'nullable|in:'.implode(',', PpdpAppointment::STATUSES),
            'trigger_public_service' => 'nullable|boolean',
            'trigger_large_scale_monitoring' => 'nullable|boolean',
            'trigger_large_scale_specific_criminal' => 'nullable|boolean',
            'competency_professional' => 'nullable|boolean',
            'competency_legal_knowledge' => 'nullable|boolean',
            'competency_pdp_practice' => 'nullable|boolean',
            'certifications' => 'nullable|string',
            'competency_notes' => 'nullable|string',
            'contact_published' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
    }
}
