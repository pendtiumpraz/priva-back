<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Dpia;
use App\Models\Organization;
use App\Services\AiDocumentAnalyzer;
use App\Services\CreditService;
use App\Services\FileUploadValidator;
use App\Services\TenantStorageService;
use Carbon\Carbon;
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
        'planned' => ['in_progress', 'on_hold', 'cancelled'],
        'in_progress' => ['implemented', 'on_hold', 'cancelled', 'overdue'],
        'implemented' => ['verified', 'in_progress'],  // verified or re-work
        'verified' => ['in_progress'],               // re-open kalau review gagal
        'overdue' => ['in_progress', 'implemented', 'cancelled'],
        'on_hold' => ['planned', 'in_progress', 'cancelled'],
        'cancelled' => [],                            // terminal
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
            'risk_event' => 'required|string|max:500',
            'category' => 'nullable|string|max:150',
            'treatment_type' => 'required|in:'.implode(',', self::VALID_TREATMENTS),
            'action' => 'required|string|max:2000',
            'rationale' => 'nullable|string|max:2000',
            'owner_user_id' => 'nullable|uuid',
            'priority' => 'required|in:'.implode(',', self::VALID_PRIORITIES),
            'due_date' => 'nullable|date|after_or_equal:today',
            'inherent_likelihood' => 'nullable|integer|min:1|max:5',
            'inherent_impact' => 'nullable|integer|min:1|max:5',
        ]);

        $items = $dpia->mitigation_tracking ?? [];
        $now = now()->toIso8601String();

        $newItem = array_merge($data, [
            'id' => (string) Str::uuid(),
            'status' => 'planned',
            'residual_likelihood' => null,
            'residual_impact' => null,
            'evidence_files' => [],
            'notes' => '',
            'started_at' => null,
            'completed_at' => null,
            'verified_at' => null,
            'verified_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $user->id,
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
            'status' => 'sometimes|in:'.implode(',', self::VALID_STATUSES),
            'action' => 'sometimes|string|max:2000',
            'rationale' => 'sometimes|nullable|string|max:2000',
            'treatment_type' => 'sometimes|in:'.implode(',', self::VALID_TREATMENTS),
            'owner_user_id' => 'sometimes|nullable|uuid',
            'priority' => 'sometimes|in:'.implode(',', self::VALID_PRIORITIES),
            'due_date' => 'sometimes|nullable|date',
            'inherent_likelihood' => 'sometimes|nullable|integer|min:1|max:5',
            'inherent_impact' => 'sometimes|nullable|integer|min:1|max:5',
            'residual_likelihood' => 'sometimes|nullable|integer|min:1|max:5',
            'residual_impact' => 'sometimes|nullable|integer|min:1|max:5',
            'notes' => 'sometimes|nullable|string|max:5000',
            'evidence_files' => 'sometimes|array',
            'evidence_files.*' => 'string',
        ]);

        // Validate status transition
        if (isset($data['status']) && $data['status'] !== $item['status']) {
            $from = $item['status'] ?? 'planned';
            $to = $data['status'];
            $allowed = self::TRANSITIONS[$from] ?? [];
            if (! in_array($to, $allowed, true)) {
                return response()->json([
                    'message' => "Transisi status '{$from}' → '{$to}' tidak diizinkan",
                    'allowed_next' => $allowed,
                ], 422);
            }

            // Bukti mitigasi WAJIB sebelum verifikasi — inilah penanda risiko
            // benar-benar sudah dimitigasi (bukan klaim sepihak).
            if ($to === 'verified' && empty($item['evidence_files'])) {
                return response()->json([
                    'message' => 'Lampirkan minimal satu bukti mitigasi sebelum menandai risiko ini "verified".',
                    'code' => 'EVIDENCE_REQUIRED',
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
            && ! in_array($item['status'], ['implemented', 'verified'], true)
            && ! in_array($data['status'] ?? '', ['implemented', 'verified'], true)) {
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
     *
     * SAFETY: orphan yang sudah digarap user (lihat detectManualWork()) TIDAK
     * dihapus — hanya dilewati dan dilaporkan di response 'skipped'. Penghapusan
     * hanya menyentuh item yang masih persis seperti hasil auto-generate.
     */
    public function cleanOrphans(Request $request, string $id)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $existing = $dpia->mitigation_tracking ?? [];
        if (empty($existing)) {
            return response()->json([
                'message' => 'RTP kosong, tidak ada orphan.',
                'removed_count' => 0,
                'skipped_count' => 0,
                'removed' => [],
                'skipped' => [],
                'data' => [],
            ]);
        }

        $candidates = Dpia::buildRtpItemsFromDpia($dpia);

        // Owner default hasil auto-generate (resolved dari PIC wizard). Owner yang
        // sama dengan ini BUKAN tanda kerja manual — mesin yang mengisinya.
        $autoOwnerId = $candidates[0]['owner_user_id'] ?? null;

        $kept = [];
        $removed = [];
        $skipped = [];
        foreach ($existing as $item) {
            $hasMatch = false;
            $itemCat = trim((string) ($item['category'] ?? ''));
            $itemEvent = trim((string) ($item['risk_event'] ?? ''));
            foreach ($candidates as $c) {
                $cCat = trim((string) ($c['category'] ?? ''));
                $cEvent = trim((string) ($c['risk_event'] ?? ''));
                if ($itemEvent === $cEvent && ($itemCat === $cCat || $itemCat === '' || $cCat === '')) {
                    $hasMatch = true;
                    break;
                }
            }
            if ($hasMatch) {
                $kept[] = $item;

                continue;
            }

            // Orphan — tapi jangan buang pekerjaan user.
            $reasons = $this->detectManualWork($item, $autoOwnerId);
            if (! empty($reasons)) {
                $kept[] = $item;
                $skipped[] = [
                    'id' => $item['id'] ?? null,
                    'risk_event' => $item['risk_event'] ?? null,
                    'category' => $item['category'] ?? null,
                    'status' => $item['status'] ?? null,
                    'reasons' => $reasons,
                ];

                continue;
            }

            $removed[] = $item;
        }

        $dpia->mitigation_tracking = $kept;
        $dpia->save();

        AuditLog::create([
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.clean_orphans',
            'details' => [
                'removed_count' => count($removed),
                'removed_risk_events' => array_map(fn ($r) => $r['risk_event'] ?? null, $removed),
                'skipped_count' => count($skipped),
                'skipped' => $skipped,
            ],
        ]);

        $parts = [];
        if (count($removed) > 0) {
            $parts[] = count($removed).' orphan items dihapus.';
        }
        if (count($skipped) > 0) {
            $parts[] = count($skipped).' item dilewati karena sudah dikerjakan manual (tidak dihapus).';
        }
        if (empty($parts)) {
            $parts[] = 'Tidak ada orphan. Semua item sinkron dengan wizard.';
        }

        return response()->json([
            'message' => implode(' ', $parts),
            'removed_count' => count($removed),
            'skipped_count' => count($skipped),
            'removed' => $removed,
            'skipped' => $skipped,
            'data' => $kept,
        ]);
    }

    /**
     * Deteksi "tanda kerja manual" pada satu RTP item.
     *
     * Baseline pembanding = bentuk item hasil Dpia::buildRtpItem() (auto-generate):
     *   treatment_type='reduce', status='planned', due_date=null, notes='',
     *   evidence_files=[], residual_*=null, started_at/completed_at/verified_at=null,
     *   owner_user_id=<default PIC resolver>.
     *
     * Setiap penyimpangan dari baseline itu = user (atau alur kerja user) sudah
     * menyentuh item ini → JANGAN dihapus. Status 'overdue' dikecualikan karena
     * di-set otomatis oleh recalcOverdue(), bukan oleh user.
     *
     * @return array<int,string> daftar alasan (kosong = item masih polos)
     */
    private function detectManualWork(array $item, ?string $autoOwnerId): array
    {
        $reasons = [];

        $treatment = (string) ($item['treatment_type'] ?? 'reduce');
        if ($treatment !== '' && $treatment !== 'reduce') {
            $reasons[] = "treatment_type diubah manual menjadi '{$treatment}'";
        }

        $status = (string) ($item['status'] ?? 'planned');
        if ($status !== '' && ! in_array($status, ['planned', 'overdue'], true)) {
            $reasons[] = "status sudah maju ke '{$status}'";
        }

        if (trim((string) ($item['notes'] ?? '')) !== '') {
            $reasons[] = 'ada catatan (notes)';
        }

        $evidence = $item['evidence_files'] ?? [];
        if (is_array($evidence) && count($evidence) > 0) {
            $reasons[] = 'ada bukti mitigasi terlampir';
        }

        $owner = $item['owner_user_id'] ?? null;
        if (! empty($owner) && $owner !== $autoOwnerId) {
            $reasons[] = 'penanggung jawab ditetapkan manual';
        }

        if (! empty($item['due_date'])) {
            $reasons[] = 'tenggat waktu (due_date) sudah diisi';
        }

        if (! empty($item['residual_likelihood']) || ! empty($item['residual_impact'])) {
            $reasons[] = 'residual risk sudah dinilai';
        }

        foreach (['started_at' => 'pekerjaan sudah dimulai', 'completed_at' => 'sudah ditandai selesai', 'verified_at' => 'sudah diverifikasi'] as $field => $label) {
            if (! empty($item[$field])) {
                $reasons[] = $label;
            }
        }

        if (! empty($item['verified_by'])) {
            $reasons[] = 'ada verifikator tercatat';
        }

        return $reasons;
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
            if (in_array($idx, $processedCandidateIdx, true)) {
                continue;
            }
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
                .($stats['orphan'] > 0 ? ", {$stats['orphan']} orphan (dihapus dari wizard, masih ada di RTP)" : '');

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
        $existCat = trim((string) ($exist['category'] ?? ''));
        $existEvent = trim((string) ($exist['risk_event'] ?? ''));
        if ($existEvent === '') {
            return -1;
        }

        foreach ($candidates as $idx => $c) {
            $cCat = trim((string) ($c['category'] ?? ''));
            $cEvent = trim((string) ($c['risk_event'] ?? ''));
            if ($cEvent === '') {
                continue;
            }

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

    /**
     * POST /api/dpia/{id}/rtp/{itemId}/upload-evidence
     * Unggah bukti mitigasi (multi — bisa dipanggil berkali-kali). Disimpan ke
     * evidence_files[] sebagai objek {id,path,original_name,size,mime,uploaded_at}.
     * Saat bukti pertama diunggah di status in_progress → auto-maju ke implemented.
     */
    public function uploadEvidence(
        Request $request,
        string $id,
        string $itemId,
        TenantStorageService $storage,
        FileUploadValidator $validator,
    ) {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $request->validate(['file' => 'required|file|max:10240']); // 10MB

        $items = $dpia->mitigation_tracking ?? [];
        $idx = $this->findItem($items, $itemId);
        if ($idx === -1) {
            return response()->json(['message' => 'Treatment item tidak ditemukan'], 404);
        }

        $file = $request->file('file');
        $ext = strtolower($file->getClientOriginalExtension());
        $allowed = ['pdf', 'docx', 'xlsx', 'xls', 'jpg', 'jpeg', 'png'];
        if (! in_array($ext, $allowed, true)) {
            return response()->json(['message' => 'Format tidak diizinkan. Hanya PDF, DOCX, XLSX, JPG, PNG.'], 422);
        }
        try {
            $preset = in_array($ext, ['jpg', 'jpeg', 'png'], true)
                ? FileUploadValidator::PRESET_IMAGE
                : FileUploadValidator::PRESET_DOCUMENT;
            $validator->validate($file, $preset);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $org = Organization::findOrFail($user->org_id);
        try {
            $stored = $storage->storeTenantPrivateFile($org, $file, "rtp/{$dpia->id}/{$itemId}/evidence");
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => 'Gagal menyimpan file ke storage.'], 500);
        }

        $entry = [
            'id' => (string) Str::uuid(),
            'path' => $stored['path'] ?? null,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime' => $file->getMimeType(),
            'uploaded_at' => now()->toIso8601String(),
            'uploaded_by' => $user->id,
        ];

        $item = $items[$idx];
        $evidence = is_array($item['evidence_files'] ?? null) ? $item['evidence_files'] : [];
        $evidence[] = $entry;
        $item['evidence_files'] = $evidence;
        $item['updated_at'] = now()->toIso8601String();

        // Bukti = tanda implementasi. Dari in_progress → implemented otomatis.
        $autoAdvanced = false;
        if (($item['status'] ?? 'planned') === 'in_progress') {
            $item['status'] = 'implemented';
            if (empty($item['completed_at'])) {
                $item['completed_at'] = now()->toIso8601String();
            }
            $autoAdvanced = true;
        }

        $items[$idx] = $item;
        $dpia->mitigation_tracking = $items;
        $dpia->save();

        AuditLog::create([
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.evidence_upload',
            'details' => ['treatment_id' => $itemId, 'file' => $entry['original_name'], 'auto_advanced' => $autoAdvanced],
        ]);

        return response()->json([
            'message' => 'Bukti mitigasi diunggah.'.($autoAdvanced ? ' Status → implemented.' : ''),
            'data' => $entry,
            'item' => $item,
        ], 201);
    }

    /**
     * DELETE /api/dpia/{id}/rtp/{itemId}/evidence/{evidenceId}
     */
    public function deleteEvidence(Request $request, string $id, string $itemId, string $evidenceId)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();

        $items = $dpia->mitigation_tracking ?? [];
        $idx = $this->findItem($items, $itemId);
        if ($idx === -1) {
            return response()->json(['message' => 'Treatment item tidak ditemukan'], 404);
        }

        $item = $items[$idx];
        $evidence = is_array($item['evidence_files'] ?? null) ? $item['evidence_files'] : [];
        $before = count($evidence);
        $evidence = array_values(array_filter($evidence, fn ($e) => is_array($e) && ($e['id'] ?? null) !== $evidenceId));
        if (count($evidence) === $before) {
            return response()->json(['message' => 'Bukti tidak ditemukan'], 404);
        }

        $item['evidence_files'] = $evidence;
        $item['updated_at'] = now()->toIso8601String();
        $items[$idx] = $item;
        $dpia->mitigation_tracking = $items;
        $dpia->save();

        AuditLog::create([
            'org_id' => $user->org_id,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.evidence_delete',
            'details' => ['treatment_id' => $itemId, 'evidence_id' => $evidenceId],
        ]);

        return response()->json(['message' => 'Bukti dihapus.', 'item' => $item]);
    }

    /**
     * POST /api/dpia/{id}/rtp/{itemId}/analyze-evidence
     * Analisis AI atas SATU bukti mitigasi: apakah dokumen membuktikan tindakan
     * mitigasi sudah dilakukan. Body: { evidence_id }. 1 kredit/analisis (di-cache
     * per dokumen+pertanyaan di AiDocumentAnalyzer). Gambar (JPG/PNG) di-skip oleh
     * analyzer (OCR belum didukung) → status 'unsure', tanpa charge.
     */
    public function analyzeEvidence(Request $request, string $id, string $itemId, AiDocumentAnalyzer $analyzer)
    {
        $user = $request->user();
        $dpia = Dpia::where('id', $id)->where('org_id', $user->org_id)->firstOrFail();
        $request->validate(['evidence_id' => 'required|string']);

        $items = $dpia->mitigation_tracking ?? [];
        $idx = $this->findItem($items, $itemId);
        if ($idx === -1) {
            return response()->json(['message' => 'Treatment item tidak ditemukan'], 404);
        }
        $item = $items[$idx];
        $evidence = is_array($item['evidence_files'] ?? null) ? $item['evidence_files'] : [];

        $evIdx = -1;
        foreach ($evidence as $i => $e) {
            if (is_array($e) && ($e['id'] ?? null) === $request->input('evidence_id')) {
                $evIdx = $i;
                break;
            }
        }
        if ($evIdx === -1) {
            return response()->json(['message' => 'Bukti tidak ditemukan'], 404);
        }

        $path = $evidence[$evIdx]['path'] ?? null;
        if (! $path) {
            return response()->json(['message' => 'Path bukti tidak tersedia'], 404);
        }

        $orgId = $user->org_id;
        if ($orgId) {
            CreditService::resetIfNeeded($orgId);
            if (! CreditService::hasCredit($orgId, 'ai_doc_analyze')) {
                $cost = CreditService::getCost('ai_doc_analyze');

                return response()->json([
                    'message' => "Kredit AI Anda habis. Dibutuhkan {$cost} kredit untuk analisis ini.",
                    'credits_exhausted' => true,
                ], 402);
            }
        }

        $localPath = $this->resolveAttachmentPath($orgId, $path);
        if (! $localPath || ! is_file($localPath)) {
            return response()->json(['message' => 'File bukti tidak ditemukan pada penyimpanan.'], 404);
        }

        $question = trim('Apakah dokumen ini membuktikan bahwa tindakan mitigasi telah dilaksanakan untuk risiko berikut?'
            ."\nRisiko: ".(string) ($item['risk_event'] ?? '')
            ."\nTindakan mitigasi: ".(string) ($item['action'] ?? ''));

        $result = $analyzer->analyze(
            documentPath: $localPath,
            question: $question,
            regulationRef: (string) ($item['category'] ?? ''),
            orgId: $orgId,
        );

        $entry = array_merge($result->toArray(), ['analyzed_at' => now()->toIso8601String()]);
        $evidence[$evIdx]['ai_analysis'] = $entry;
        $item['evidence_files'] = $evidence;
        $item['updated_at'] = now()->toIso8601String();
        $items[$idx] = $item;
        $dpia->mitigation_tracking = $items;
        $dpia->save();

        AuditLog::create([
            'org_id' => $orgId,
            'user_id' => $user->id,
            'module' => 'dpia',
            'record_id' => $dpia->id,
            'action' => 'rtp.evidence_analyzed',
            'details' => ['treatment_id' => $itemId, 'evidence_id' => $request->input('evidence_id'), 'status' => $entry['status'] ?? null],
        ]);

        return response()->json(['data' => $entry, 'item' => $item]);
    }

    /**
     * Resolve path relatif → absolute filesystem path (local disk → tenant disk
     * via temp file). Mirror pola TPRM/Holding.
     */
    private function resolveAttachmentPath(?string $orgId, string $relativePath): ?string
    {
        $rel = ltrim($relativePath, '/');
        foreach ([storage_path('app/public/'.$rel), storage_path('app/private/'.$rel), storage_path('app/'.$rel)] as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        try {
            $org = $orgId ? Organization::find($orgId) : null;
            if (! $org) {
                return null;
            }
            $disk = app(TenantStorageService::class)->getDisk($org);
            if (! $disk->exists($rel)) {
                return null;
            }
            $contents = $disk->get($rel);
            if ($contents === null || $contents === '') {
                return null;
            }
            $ext = pathinfo($rel, PATHINFO_EXTENSION) ?: 'bin';
            $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rtp_evidence_'.substr(hash('sha256', $rel), 0, 16).'.'.$ext;
            if (file_put_contents($tmp, $contents) === false) {
                return null;
            }

            return $tmp;
        } catch (\Throwable $e) {
            \Log::warning('[RTP resolveAttachmentPath] gagal: '.$e->getMessage());

            return null;
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function findItem(array $items, string $itemId): int
    {
        foreach ($items as $i => $it) {
            if (($it['id'] ?? null) === $itemId) {
                return $i;
            }
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
            if (empty($item['due_date'])) {
                continue;
            }
            if (in_array($item['status'] ?? 'planned', ['verified', 'cancelled', 'on_hold', 'overdue'], true)) {
                continue;
            }
            try {
                $due = Carbon::parse($item['due_date'])->startOfDay();
                if ($due->lt($today)) {
                    $item['status'] = 'overdue';
                    $item['updated_at'] = now()->toIso8601String();
                }
            } catch (\Throwable $e) { /* invalid date, skip */
            }
        }

        return $items;
    }

    private function hasOverdueChanges(array $before, array $after): bool
    {
        if (count($before) !== count($after)) {
            return false;
        }
        for ($i = 0; $i < count($before); $i++) {
            if (($before[$i]['status'] ?? null) !== ($after[$i]['status'] ?? null)) {
                return true;
            }
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
            if (isset($statusCount[$status])) {
                $statusCount[$status]++;
            }
            $priority = $it['priority'] ?? 'medium';
            if (isset($priorityCount[$priority])) {
                $priorityCount[$priority]++;
            }

            if (! empty($it['inherent_likelihood']) && ! empty($it['inherent_impact'])) {
                $inherentSum += (int) $it['inherent_likelihood'] * (int) $it['inherent_impact'];
                $inherentCount++;
            }
            if (! empty($it['residual_likelihood']) && ! empty($it['residual_impact'])) {
                $residualSum += (int) $it['residual_likelihood'] * (int) $it['residual_impact'];
                $residualCount++;
            }
        }

        return [
            'total' => count($items),
            'status' => $statusCount,
            'priority' => $priorityCount,
            'avg_inherent_risk' => $inherentCount > 0 ? round($inherentSum / $inherentCount, 1) : null,
            'avg_residual_risk' => $residualCount > 0 ? round($residualSum / $residualCount, 1) : null,
            'completion_rate' => count($items) > 0
                ? round(($statusCount['verified'] / count($items)) * 100, 1)
                : 0,
        ];
    }
}
