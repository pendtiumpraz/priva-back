<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrossBorderTransfer;
use Illuminate\Http\Request;
use App\Services\AiService;

class CrossBorderController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $transfers = CrossBorderTransfer::where('org_id', $orgId)
            ->orderBy('created_at', 'desc')
            ->paginate(15);
            
        return response()->json($transfers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'destination_country' => 'required|string|max:100',
            'destination_entity' => 'required|string|max:255',
            'transfer_purpose' => 'required|string',
            'legal_basis' => 'required|string',
        ]);

        $transfer = CrossBorderTransfer::create(array_merge($request->all(), [
            'org_id' => $request->user()->org_id,
        ]));

        return response()->json(['message' => 'Data transfer berhasil didaftarkan', 'data' => $transfer], 201);
    }

    public function show(Request $request, $id)
    {
        $transfer = CrossBorderTransfer::where('org_id', $request->user()->org_id)->findOrFail($id);
        return response()->json(['data' => $transfer]);
    }

    public function update(Request $request, $id)
    {
        $transfer = CrossBorderTransfer::where('org_id', $request->user()->org_id)->findOrFail($id);
        $transfer->update($request->all());
        return response()->json(['message' => 'Data transfer berhasil diperbarui', 'data' => $transfer]);
    }

    public function destroy(Request $request, $id)
    {
        $transfer = CrossBorderTransfer::where('org_id', $request->user()->org_id)->findOrFail($id);
        $transfer->delete();
        return response()->json(['message' => 'Data transfer berhasil dihapus']);
    }

    /**
     * Conduct Transfer Impact Assessment (TIA) via AI
     */
    public function assessTIA(Request $request, $id)
    {
        $user = $request->user();
        $transfer = CrossBorderTransfer::where('org_id', $user->org_id)->findOrFail($id);

        $request->validate([
            'tia_answers' => 'required|array',
        ]);

        $answers = $request->tia_answers;
        $hasAi = $user->organization->ai_credits_remaining > 0;
        
        $score = 50;
        $riskLevel = 'medium';
        $summary = 'TIA dilakukan secara manual atau tanpa asisten AI.';
        $safeguards = [];
        $legalBasisRecommended = null;

        if ($hasAi) {
            try {
                $vendorData = ['tia_answers' => $answers, 'transfer_purpose' => $transfer->transfer_purpose];
                $aiService = new AiService($user->org_id);
                $result = $aiService->vendorTia($vendorData, $transfer->destination_country);

                if ($result) {
                    $score = isset($result['tia_score']) ? (int)$result['tia_score'] : 50;
                    
                    if ($score >= 85) $riskLevel = 'low';
                    elseif ($score >= 65) $riskLevel = 'medium';
                    elseif ($score >= 40) $riskLevel = 'high';
                    else $riskLevel = 'critical';

                    $legalBasisRecommended = $result['legal_basis_recommended'] ?? null;
                    $safeguards = $result['safeguard_recommendations'] ?? [];
                    $summary = json_encode(['legal_basis' => $legalBasisRecommended, 'safeguards' => $safeguards]);

                    $user->organization->decrement('ai_credits_remaining', 1);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('AI TIA Assessment failed: ' . $e->getMessage());
            }
        }

        $transfer->update([
            'tia_answers' => $answers,
            'risk_score' => $score,
            'risk_level' => $riskLevel,
            'tia_summary' => $summary,
            'safeguards' => !empty($safeguards) ? $safeguards : $transfer->safeguards,
            'legal_basis' => $legalBasisRecommended ?: $transfer->legal_basis,
            'status' => ($riskLevel === 'high' || $riskLevel === 'critical') ? 'pending' : 'approved',
            'approved_at' => ($riskLevel === 'low' || $riskLevel === 'medium') ? now() : null,
            'review_due_at' => now()->addYear(),
        ]);

        return response()->json([
            'message' => 'Transfer Impact Assessment berhasil diselesaikan.',
            'data' => $transfer
        ]);
    }
}
