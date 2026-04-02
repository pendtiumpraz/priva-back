<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CrossBorderTransfer;
use Illuminate\Http\Request;
use OpenAI\Laravel\Facades\OpenAI;

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

        if ($hasAi) {
            try {
                $prompt = "Lakukan Transfer Impact Assessment (TIA) atas transfer data ke negara {$transfer->destination_country} berdasarkan jawaban berikut: " . json_encode($answers) . "\nBerikan skor risiko komprehensif (0-100), level risiko (low, medium, high, critical), dan ringkasan eksekutif (TIA Summary) dalam format JSON: {\"score\": int, \"risk_level\": \"string\", \"tia_summary\": \"string\"}";
                
                $response = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a legal privacy expert handling cross-border data transfer assessments. Output purely JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.2,
                ]);

                $result = json_decode($response->choices[0]->message->content, true);
                $score = $result['score'] ?? 50;
                $riskLevel = $result['risk_level'] ?? 'medium';
                $summary = $result['tia_summary'] ?? 'Analisis selesai.';

                $user->organization->decrement('ai_credits_remaining', 1);
            } catch (\Exception $e) {
                \Log::error('AI TIA Assessment failed: ' . $e->getMessage());
            }
        }

        $transfer->update([
            'tia_answers' => $answers,
            'risk_score' => $score,
            'risk_level' => $riskLevel,
            'tia_summary' => $summary,
            'status' => $riskLevel === 'high' || $riskLevel === 'critical' ? 'pending' : 'approved',
            'approved_at' => $riskLevel === 'low' || $riskLevel === 'medium' ? now() : null,
            'review_due_at' => now()->addYear(),
        ]);

        return response()->json([
            'message' => 'Transfer Impact Assessment berhasil diselesaikan.',
            'data' => $transfer
        ]);
    }
}
