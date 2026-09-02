<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PpdpAppointment;
use App\Models\User;
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
        $data = $this->applyLinkedUser($orgId, $data);

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
        $data = $this->applyLinkedUser($orgId, $data);
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
     * Bila `user_id` menunjuk user platform di org yang sama, salin
     * nama/email/telepon/jabatan dari user tsb (server-authoritative) dan
     * tandai internal. Cegah duplikasi manual dengan role `dpo` / dpo_list RoPA.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyLinkedUser(string $orgId, array $data): array
    {
        if (empty($data['user_id'])) {
            return $data;
        }

        $user = User::where('org_id', $orgId)->find($data['user_id']);
        if (! $user) {
            // user_id tidak valid untuk org ini → abaikan tautan, jangan bocorkan lintas-org.
            $data['user_id'] = null;

            return $data;
        }

        $data['appointee_name'] = $user->name;
        $data['appointee_email'] = $user->email;
        $data['appointee_phone'] = $user->phone;
        $data['appointee_position'] = $user->position ?: ($data['appointee_position'] ?? null);
        $data['is_internal'] = true;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'user_id' => 'nullable|uuid',
            // Nama boleh kosong bila user_id di-set (disalin dari user pada applyLinkedUser).
            'appointee_name' => $request->filled('user_id') ? 'nullable|string|max:200' : "{$req}|string|max:200",
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
