<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DsrRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tinjauan keberatan atas keputusan berbasis pemrosesan otomatis / pemrofilan
 * (PP 33/2026 Pasal 93-95).
 *
 * Pasal 94(3): keberatan yang terverifikasi & DITERIMA → Pengendali wajib
 * memberikan alternatif pemrosesan dengan CAMPUR TANGAN MANUSIA dan/atau tidak
 * berdasarkan hasil pemrosesan otomatis.
 * Pasal 95(1): keberatan dapat DITOLAK hanya jika (a) tidak ada akibat
 * hukum/dampak signifikan DAN (b) sistem akurat + ada mitigasi. Penolakan wajib
 * diberitahukan ke Subjek Data (Pasal 95(3)).
 */
class DsrAutomatedDecisionController extends Controller
{
    public function review(Request $request, string $id): JsonResponse
    {
        $orgId = $request->user()->org_id;
        $dsr = DsrRequest::where('org_id', $orgId)->findOrFail($id);

        if ($dsr->request_type !== DsrRequest::TYPE_AUTOMATED_DECISION) {
            return response()->json(['message' => 'Tinjauan ini hanya untuk permintaan keberatan keputusan otomatis.'], 422);
        }

        $data = $request->validate([
            'outcome' => 'required|in:human_intervention,rejected',
            'intervention_notes' => 'required_if:outcome,human_intervention|nullable|string',
            'alternative_processing' => 'nullable|string',
            'reason' => 'required_if:outcome,rejected|nullable|string',
            'no_legal_or_significant_effect' => 'nullable|boolean',
            'accurate_system_and_mitigation' => 'nullable|boolean',
        ]);

        $user = $request->user();
        $now = now();

        if ($data['outcome'] === 'rejected') {
            // Pasal 95(1): penolakan hanya sah bila KEDUA syarat terpenuhi.
            $groundA = (bool) ($data['no_legal_or_significant_effect'] ?? false);
            $groundB = (bool) ($data['accurate_system_and_mitigation'] ?? false);
            if (! ($groundA && $groundB)) {
                return response()->json([
                    'message' => 'Penolakan keberatan hanya sah bila (a) tidak ada akibat hukum/dampak signifikan DAN '
                        .'(b) sistem akurat serta ada mitigasi (PP 33/2026 Pasal 95 ayat (1)).',
                ], 422);
            }
        }

        $review = [
            'outcome' => $data['outcome'],
            'reviewer_id' => $user->id,
            'reviewer_name' => $user->name,
            'reviewed_at' => $now->toIso8601String(),
            'intervention_notes' => $data['intervention_notes'] ?? null,
            'alternative_processing' => $data['alternative_processing'] ?? null,
            'rejection_reason' => $data['reason'] ?? null,
            'grounds' => $data['outcome'] === 'rejected' ? [
                'no_legal_or_significant_effect' => (bool) ($data['no_legal_or_significant_effect'] ?? false),
                'accurate_system_and_mitigation' => (bool) ($data['accurate_system_and_mitigation'] ?? false),
            ] : null,
        ];

        $subjectData = is_array($dsr->subject_data) ? $dsr->subject_data : [];
        $subjectData['automated_decision_review'] = $review;
        $dsr->subject_data = $subjectData;
        $dsr->responded_at = $now;

        if ($data['outcome'] === 'human_intervention') {
            // Pasal 94(3): berikan alternatif pemrosesan dgn campur tangan manusia.
            $dsr->status = 'completed';
            $dsr->response = trim('Keberatan diterima. Diberikan alternatif pemrosesan dengan campur tangan manusia. '
                .($data['intervention_notes'] ?? '').' '.($data['alternative_processing'] ?? ''));
            $dsr->closed_at = $now;
        } else {
            // Pasal 95: penolakan + wajib diberitahukan ke subjek (via notifikasi DSR).
            $dsr->status = 'rejected';
            $dsr->rejection_reason = $data['reason'];
        }

        $dsr->save();

        AuditLog::log('dsr', $dsr->id, 'automated_decision_review', [
            'outcome' => $data['outcome'],
            'reviewer' => $user->name,
        ], 'dsr');

        return response()->json([
            'message' => $data['outcome'] === 'human_intervention'
                ? 'Keberatan diterima — campur tangan manusia dicatat (Pasal 94 ayat (3)).'
                : 'Keberatan ditolak dengan justifikasi Pasal 95 — beritahukan ke Subjek Data.',
            'data' => $dsr->fresh(),
        ]);
    }
}
