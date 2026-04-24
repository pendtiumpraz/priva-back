<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dpia;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * DPIA Risk Treatment Plan (RTP) — Phase 1 Quick-Win
 *
 * CRUD untuk mitigation_tracking array di Dpia. Setiap item punya:
 *   status (planned/in_progress/implemented/verified/overdue/on_hold/cancelled),
 *   owner, due_date, evidence, inherent+residual risk score.
 *
 * Phase 2 roadmap: migrasi ke polymorphic risk_treatments table untuk
 * cross-source (DPIA + Breach + Vendor + GAP).
 */
class DpiaRtpController extends Controller
{
    /**
     * Valid status transition matrix (from → to).
     * Kalau tidak ada di sini, transisi di-reject.
     */
    private const TRANSITIONS = [
        'planned'     => ['in_progress', 'on_hold', 'cancelled'],
        'in_progress' => ['implemented', 'on_hold', 'cancelled', 'overdue'],
        'implemented' => ['verified', 'in_progress'],  // verified or re-work
        'verified'    => ['in_progress'],               // re-open kalau review gagal
        'overdue'     => ['in_progress', 'implemented', 'cancelled'],
        'on_hold'     => ['planned', 'in_progress', 'cancelled'],
        'cancelled'   => [],                            // terminal
    ];

    private const VALID_PRIORITIES = ['critical', 'high', 'medium', 'low'];
    private const VALID_TREATMENTS = ['avoid', 'reduce', 'transfer', 'accept'];
    private const VALID_STATUSES = ['planned', 'in_progress', 'implemented', 'verified', 'overdue', 'on_hold', 'cancelled'];

    /**
     * GET /api/dpia/{id}/rtp
     * List semua treatment items + summary stats.
     */
    public function index(Request $request, string $id)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $items = $this->recalcOverdue($dpia->mitigation_tracking ?? []);

        // Persist kalau ada status di-flip ke overdue
        if ($this->hasOverdueChanges($dpia->mitigation_tracking ?? [], $items)) {
            $dpia->mitigation_tracking = $items;
            $dpia->saveQuietly();
        }

        $stats = $this->summarize($items);

        return response()->json([
            'data' => $items,
            'stats' => $stats,
            'dpia' => [
                'id' => $dpia->id,
                'registration_number' => $dpia->registration_number,
                'risk_level' => $dpia->risk_level,
                'status' => $dpia->status,
            ],
        ]);
    }

    /**
     * POST /api/dpia/{id}/rtp
     * Tambah treatment item baru.
     */
    public function store(Request $request, string $id)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $data = $request->validate([
            'risk_event'            => 'required|string|max:500',
            'category'              => 'nullable|string|max:150',
            'treatment_type'        => 'required|in:' . implode(',', self::VALID_TREATMENTS),
            'action'                => 'required|string|max:2000',
            'rationale'             => 'nullable|string|max:2000',
            'owner_user_id'         => 'nullable|uuid',
            'priority'              => 'required|in:' . implode(',', self::VALID_PRIORITIES),
            'due_date'              => 'nullable|date|after_or_equal:today',
            'inherent_likelihood'   => 'nullable|integer|min:1|max:5',
            'inherent_impact'       => 'nullable|integer|min:1|max:5',
        ]);

        $items = $dpia->mitigation_tracking ?? [];
        $now = now()->toIso8601String();

        $newItem = array_merge($data, [
            'id'                 => (string) Str::uuid(),
            'status'             => 'planned',
            'residual_likelihood'=> null,
            'residual_impact'    => null,
            'evidence_files'     => [],
            'notes'              => '',
            'started_at'         => null,
            'completed_at'       => null,
            'verified_at'        => null,
            'verified_by'        => null,
            'created_at'         => $now,
            'updated_at'         => $now,
            'created_by'         => $user->id,
        ]);

        $items[] = $newItem;
        $dpia->mitigation_tracking = $items;
        $dpia->save();

        AuditLog::create([
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.create',
            'details' => [
                'treatment_id' => $newItem['id'],
                'risk_event' => $newItem['risk_event'],
                'priority' => $newItem['priority'],
            ],
        ]);

        return response()->json([
            'message' => 'Treatment item ditambahkan',
            'data' => $newItem,
        ], 201);
    }

    /**
     * PUT /api/dpia/{id}/rtp/{itemId}
     * Update treatment item (status transition, owner, due_date, residual, evidence, notes).
     */
    public function update(Request $request, string $id, string $itemId)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $items = $dpia->mitigation_tracking ?? [];
        $idx = $this->findItem($items, $itemId);
        if ($idx === -1) {
            return response()->json(['message' => 'Treatment item tidak ditemukan'], 404);
        }

        $item = $items[$idx];

        $data = $request->validate([
            'status'              => 'sometimes|in:' . implode(',', self::VALID_STATUSES),
            'action'              => 'sometimes|string|max:2000',
            'rationale'           => 'sometimes|nullable|string|max:2000',
            'treatment_type'      => 'sometimes|in:' . implode(',', self::VALID_TREATMENTS),
            'owner_user_id'       => 'sometimes|nullable|uuid',
            'priority'            => 'sometimes|in:' . implode(',', self::VALID_PRIORITIES),
            'due_date'            => 'sometimes|nullable|date',
            'inherent_likelihood' => 'sometimes|nullable|integer|min:1|max:5',
            'inherent_impact'     => 'sometimes|nullable|integer|min:1|max:5',
            'residual_likelihood' => 'sometimes|nullable|integer|min:1|max:5',
            'residual_impact'     => 'sometimes|nullable|integer|min:1|max:5',
            'notes'               => 'sometimes|nullable|string|max:5000',
            'evidence_files'      => 'sometimes|array',
            'evidence_files.*'    => 'string',
        ]);

        // Validate status transition
        if (isset($data['status']) && $data['status'] !== $item['status']) {
            $from = $item['status'] ?? 'planned';
            $to = $data['status'];
            $allowed = self::TRANSITIONS[$from] ?? [];
            if (!in_array($to, $allowed, true)) {
                return response()->json([
                    'message' => "Transisi status '{$from}' → '{$to}' tidak diizinkan",
                    'allowed_next' => $allowed,
                ], 422);
            }

            // Auto-timestamp milestone
            if ($to === 'in_progress' && empty($item['started_at'])) {
                $data['started_at'] = now()->toIso8601String();
            }
            if ($to === 'implemented' && empty($item['completed_at'])) {
                $data['completed_at'] = now()->toIso8601String();
            }
            if ($to === 'verified') {
                $data['verified_at'] = now()->toIso8601String();
                $data['verified_by'] = $user->id;
            }
        }

        // Residual score only accepted when status is implemented/verified
        if ((isset($data['residual_likelihood']) || isset($data['residual_impact']))
            && !in_array($item['status'], ['implemented', 'verified'], true)
            && !in_array($data['status'] ?? '', ['implemented', 'verified'], true)) {
            return response()->json([
                'message' => 'Residual risk hanya bisa diisi saat status implemented atau verified',
            ], 422);
        }

        $data['updated_at'] = now()->toIso8601String();
        $items[$idx] = array_merge($item, $data);
        $dpia->mitigation_tracking = $items;
        $dpia->save();

        AuditLog::create([
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.update',
            'details' => [
                'treatment_id' => $itemId,
                'status_from' => $item['status'] ?? null,
                'status_to' => $data['status'] ?? $item['status'] ?? null,
                'fields_changed' => array_keys($data),
            ],
        ]);

        return response()->json([
            'message' => 'Treatment item diperbarui',
            'data' => $items[$idx],
        ]);
    }

    /**
     * DELETE /api/dpia/{id}/rtp/{itemId}
     * Hapus treatment item.
     */
    public function destroy(Request $request, string $id, string $itemId)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $items = $dpia->mitigation_tracking ?? [];
        $idx = $this->findItem($items, $itemId);
        if ($idx === -1) {
            return response()->json(['message' => 'Treatment item tidak ditemukan'], 404);
        }

        $removed = $items[$idx];
        array_splice($items, $idx, 1);
        $dpia->mitigation_tracking = $items;
        $dpia->save();

        AuditLog::create([
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.delete',
            'details' => [
                'treatment_id' => $itemId,
                'risk_event' => $removed['risk_event'] ?? null,
            ],
        ]);

        return response()->json(['message' => 'Treatment item dihapus']);
    }

    /**
     * POST /api/dpia/{id}/rtp/auto-generate
     * Auto-generate RTP items dari wizard data DPIA.
     *
     * Source priority (via Dpia::buildRtpItemsFromDpia):
     *   1. mitigation_measures[] (column top-level)
     *   2. wizard_data.potensi_risiko[category].risk_events[] (fallback untuk
     *      DPIA yang belum explicit-set mitigation_measures tapi sudah isi
     *      risk events di wizard section 3)
     *
     * Idempotent — skip items dengan action text yang sama (dedup).
     */
    public function autoGenerate(Request $request, string $id)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $candidates = Dpia::buildRtpItemsFromDpia($dpia);

        if (empty($candidates)) {
            return response()->json([
                'message' => 'Tidak ada sumber data untuk generate RTP. Isi dulu "Potensi Risiko" (wizard section 3) atau mitigation_measures sebelum generate.',
                'hint' => 'Buka wizard DPIA → section Potensi Risiko → pilih kategori → tambah Risk Events dengan penilaian dampak+probabilitas dan strategi penanganan.',
            ], 422);
        }

        $existing = $dpia->mitigation_tracking ?? [];
        $existingActions = collect($existing)->map(fn($e) => trim((string)($e['action'] ?? '')))->all();

        $generated = array_values(array_filter($candidates, function ($c) use ($existingActions) {
            return !in_array(trim((string)$c['action']), $existingActions, true);
        }));

        // Override created_by dengan user saat ini (explicit manual generate)
        foreach ($generated as &$g) { $g['created_by'] = $user->id; }
        unset($g);

        if (empty($generated)) {
            return response()->json([
                'message' => 'Semua risk events dari DPIA sudah ada di RTP. Tidak ada yang perlu di-generate.',
                'data' => $existing,
            ]);
        }

        $all = array_merge($existing, $generated);
        $dpia->mitigation_tracking = $all;
        $dpia->save();

        AuditLog::create([
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.auto_generate',
            'details' => ['generated_count' => count($generated)],
        ]);

        return response()->json([
            'message' => count($generated) . ' treatment item di-generate dari DPIA',
            'generated' => $generated,
            'data' => $all,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function findItem(array $items, string $itemId): int
    {
        foreach ($items as $i => $it) {
            if (($it['id'] ?? null) === $itemId) return $i;
        }
        return -1;
    }

    /**
     * Auto-flip status ke 'overdue' kalau due_date lewat + status bukan terminal.
     */
    private function recalcOverdue(array $items): array
    {
        $today = now()->startOfDay();
        foreach ($items as &$item) {
            if (empty($item['due_date'])) continue;
            if (in_array($item['status'] ?? 'planned', ['verified', 'cancelled', 'on_hold', 'overdue'], true)) continue;
            try {
                $due = \Carbon\Carbon::parse($item['due_date'])->startOfDay();
                if ($due->lt($today)) {
                    $item['status'] = 'overdue';
                    $item['updated_at'] = now()->toIso8601String();
                }
            } catch (\Throwable $e) { /* invalid date, skip */ }
        }
        return $items;
    }

    private function hasOverdueChanges(array $before, array $after): bool
    {
        if (count($before) !== count($after)) return false;
        for ($i = 0; $i < count($before); $i++) {
            if (($before[$i]['status'] ?? null) !== ($after[$i]['status'] ?? null)) return true;
        }
        return false;
    }

    private function summarize(array $items): array
    {
        $statusCount = ['planned' => 0, 'in_progress' => 0, 'implemented' => 0, 'verified' => 0, 'overdue' => 0, 'on_hold' => 0, 'cancelled' => 0];
        $priorityCount = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $inherentSum = 0;
        $inherentCount = 0;
        $residualSum = 0;
        $residualCount = 0;

        foreach ($items as $it) {
            $status = $it['status'] ?? 'planned';
            if (isset($statusCount[$status])) $statusCount[$status]++;
            $priority = $it['priority'] ?? 'medium';
            if (isset($priorityCount[$priority])) $priorityCount[$priority]++;

            if (!empty($it['inherent_likelihood']) && !empty($it['inherent_impact'])) {
                $inherentSum += (int)$it['inherent_likelihood'] * (int)$it['inherent_impact'];
                $inherentCount++;
            }
            if (!empty($it['residual_likelihood']) && !empty($it['residual_impact'])) {
                $residualSum += (int)$it['residual_likelihood'] * (int)$it['residual_impact'];
                $residualCount++;
            }
        }

        return [
            'total'             => count($items),
            'status'            => $statusCount,
            'priority'          => $priorityCount,
            'avg_inherent_risk' => $inherentCount > 0 ? round($inherentSum / $inherentCount, 1) : null,
            'avg_residual_risk' => $residualCount > 0 ? round($residualSum / $residualCount, 1) : null,
            'completion_rate'   => count($items) > 0
                ? round(($statusCount['verified'] / count($items)) * 100, 1)
                : 0,
        ];
    }
}
