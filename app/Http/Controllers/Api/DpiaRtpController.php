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
     * POST /api/dpia/{id}/rtp/clean-orphans
     * Hapus semua RTP item yang tidak match dengan wizard DPIA current state
     * (risk event deleted dari wizard, atau penanganan diganti dari mitigate
     * ke accept/transfer/terminate).
     *
     * Matching logic sama dengan smart upsert (category + risk_event).
     */
    public function cleanOrphans(Request $request, string $id)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $existing = $dpia->mitigation_tracking ?? [];
        if (empty($existing)) {
            return response()->json(['message' => 'RTP kosong, tidak ada orphan.', 'data' => []]);
        }

        $candidates = Dpia::buildRtpItemsFromDpia($dpia);

        $kept = [];
        $removed = [];
        foreach ($existing as $item) {
            $hasMatch = false;
            $itemCat = trim((string)($item['category'] ?? ''));
            $itemEvent = trim((string)($item['risk_event'] ?? ''));
            foreach ($candidates as $c) {
                $cCat = trim((string)($c['category'] ?? ''));
                $cEvent = trim((string)($c['risk_event'] ?? ''));
                if ($itemEvent === $cEvent && ($itemCat === $cCat || $itemCat === '' || $cCat === '')) {
                    $hasMatch = true;
                    break;
                }
            }
            if ($hasMatch) {
                $kept[] = $item;
            } else {
                $removed[] = $item;
            }
        }

        $dpia->mitigation_tracking = $kept;
        $dpia->save();

        AuditLog::create([
            'org_id'    => $user->org_id,
            'user_id'   => $user->id,
            'module'    => 'dpia',
            'record_id' => $dpia->id,
            'action'    => 'rtp.clean_orphans',
            'details'   => [
                'removed_count' => count($removed),
                'removed_risk_events' => array_map(fn($r) => $r['risk_event'] ?? null, $removed),
            ],
        ]);

        return response()->json([
            'message' => count($removed) > 0
                ? count($removed) . ' orphan items dihapus.'
                : 'Tidak ada orphan. Semua item sinkron dengan wizard.',
            'removed_count' => count($removed),
            'removed' => $removed,
            'data' => $kept,
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
     * Smart Upsert RTP items dari wizard DPIA.
     *
     * Identity key: (category + risk_event text) — match RTP item dengan wizard event.
     *
     * Behavior per-item:
     *   - Match found → UPDATE wizard-sourced fields (inherent_L/I, priority,
     *     action, rationale, treatment_type). PRESERVE user edits (status,
     *     owner_user_id, due_date, evidence_files, residual_L/I,
     *     verified_at, started_at, completed_at, notes, verified_by).
     *   - No match → INSERT new item.
     *   - RTP item tanpa match di wizard → keep as orphan (user manage manual).
     *
     * Idempotent — bisa dipanggil berkali-kali, tidak akan duplicate.
     */
    public function autoGenerate(Request $request, string $id)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $candidates = Dpia::buildRtpItemsFromDpia($dpia);

        if (empty($candidates)) {
            return response()->json([
                'message' => 'Tidak ada sumber data untuk generate RTP. Isi dulu "Potensi Risiko" (wizard section 3).',
                'hint' => 'Buka wizard DPIA → section Potensi Risiko → pilih kategori → tambah Risk Events dengan penilaian dampak+probabilitas dan strategi penanganan.',
            ], 422);
        }

        $existing = $dpia->mitigation_tracking ?? [];
        $now = now()->toIso8601String();

        // Fields yang di-REFRESH dari wizard
        $refreshFields = ['inherent_likelihood', 'inherent_impact', 'priority', 'action', 'rationale', 'treatment_type', 'category'];

        // Fields yang di-PRESERVE (user edits)
        // status, owner_user_id, due_date, evidence_files, residual_*, verified_at,
        // started_at, completed_at, notes, verified_by — all preserved

        $stats = [
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'orphan' => 0,  // items di RTP tapi tidak ada di wizard
        ];

        $result = [];
        $processedCandidateIdx = [];

        // PASS 1: untuk setiap existing item, cek apakah masih ada di wizard
        foreach ($existing as $exist) {
            $matchIdx = $this->findCandidate($candidates, $exist);
            if ($matchIdx !== -1) {
                // Match → refresh wizard fields, preserve user edits
                $cand = $candidates[$matchIdx];
                $merged = $exist;
                $anyChanged = false;
                foreach ($refreshFields as $f) {
                    $newVal = $cand[$f] ?? null;
                    $oldVal = $exist[$f] ?? null;
                    if ($newVal !== $oldVal) {
                        $merged[$f] = $newVal;
                        $anyChanged = true;
                    }
                }
                if ($anyChanged) {
                    $merged['updated_at'] = $now;
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }
                $result[] = $merged;
                $processedCandidateIdx[] = $matchIdx;
            } else {
                // Orphan: tidak ada di wizard anymore. Keep as-is.
                $orphan = $exist;
                $orphan['_is_orphan'] = true;
                $result[] = $orphan;
                $stats['orphan']++;
            }
        }

        // PASS 2: insert candidates yang belum di-process (risk event baru)
        foreach ($candidates as $idx => $cand) {
            if (in_array($idx, $processedCandidateIdx, true)) continue;
            $cand['created_by'] = $user->id;
            $result[] = $cand;
            $stats['inserted']++;
        }

        $dpia->mitigation_tracking = $result;
        $dpia->save();

        AuditLog::create([
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.auto_generate',
            'details' => $stats,
        ]);

        $totalChanged = $stats['inserted'] + $stats['updated'];
        $message = $totalChanged === 0
            ? 'Semua risk events sudah sinkron dengan wizard. Tidak ada perubahan.'
            : "RTP ter-sync: {$stats['inserted']} baru, {$stats['updated']} di-update, {$stats['unchanged']} unchanged"
                . ($stats['orphan'] > 0 ? ", {$stats['orphan']} orphan (dihapus dari wizard, masih ada di RTP)" : '');

        return response()->json([
            'message' => $message,
            'stats' => $stats,
            'data' => $result,
            // Legacy compat: 'generated' masih diisi untuk frontend yang cek length
            'generated' => array_slice($result, count($existing)),
        ]);
    }

    /**
     * Match existing RTP item dengan candidate dari wizard, by (category + risk_event).
     * Fallback: kalau risk_event persis sama tapi category null → match by risk_event only.
     * Return index di candidates array, atau -1 kalau tidak match.
     */
    private function findCandidate(array $candidates, array $exist): int
    {
        $existCat = trim((string)($exist['category'] ?? ''));
        $existEvent = trim((string)($exist['risk_event'] ?? ''));
        if ($existEvent === '') return -1;

        foreach ($candidates as $idx => $c) {
            $cCat = trim((string)($c['category'] ?? ''));
            $cEvent = trim((string)($c['risk_event'] ?? ''));
            if ($cEvent === '') continue;

            // Primary match: category + risk_event
            if ($existCat !== '' && $cCat !== '' && $existCat === $cCat && $existEvent === $cEvent) {
                return $idx;
            }
            // Fallback: risk_event saja (untuk data legacy tanpa category)
            if (($existCat === '' || $cCat === '') && $existEvent === $cEvent) {
                return $idx;
            }
        }
        return -1;
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
