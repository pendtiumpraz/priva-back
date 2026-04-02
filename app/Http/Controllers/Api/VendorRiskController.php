<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vendor;
use App\Models\VendorAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class VendorRiskController extends Controller
{
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;
        $vendors = Vendor::where('org_id', $orgId)
            ->with(['assessments' => function($q) {
                $q->orderBy('created_at', 'desc')->take(1);
            }])
            ->orderBy('created_at', 'desc')
            ->paginate(15);
            
        return response()->json($vendors);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'nullable|string',
            'country' => 'nullable|string',
        ]);

        $vendor = Vendor::create(array_merge($request->all(), [
            'org_id' => $request->user()->org_id,
        ]));

        return response()->json(['message' => 'Vendor berhasil ditambahkan', 'data' => $vendor], 201);
    }

    public function show(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->with('assessments')->findOrFail($id);
        return response()->json(['data' => $vendor]);
    }

    public function update(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $vendor->update($request->all());
        return response()->json(['message' => 'Vendor berhasil diperbarui', 'data' => $vendor]);
    }

    public function destroy(Request $request, $id)
    {
        $vendor = Vendor::where('org_id', $request->user()->org_id)->findOrFail($id);
        $vendor->delete();
        return response()->json(['message' => 'Vendor berhasil dihapus']);
    }

    /**
     * Submit assessment questionnaire and generate risk score via AI
     */
    public function assess(Request $request, $id)
    {
        $user = $request->user();
        $vendor = Vendor::where('org_id', $user->org_id)->findOrFail($id);

        $request->validate([
            'answers' => 'required|array',
        ]);

        $answers = $request->answers;

        // Generate Risk Score via deterministic logic or AI
        // If they have AI enabled and features allowed:
        $hasAi = $user->organization->ai_credits_remaining > 0;
        
        $score = 50;
        $riskLevel = 'medium';
        $recommendations = [];

        if ($hasAi) {
            try {
                $prompt = "Tolong analisis jawaban assessment vendor ini terkait privasi data dan berikan skor risiko (0-100, dimana 100 sangat berisiko) serta level (low, medium, high, critical) dan daftar rekomendasi perbaikan. \nJawaban: " . json_encode($answers) . "\nFormat JSON: {\"score\": int, \"risk_level\": \"string\", \"recommendations\": []}";
                
                $response = OpenAI::chat()->create([
                    'model' => 'gpt-4o-mini',
                    'messages' => [
                        ['role' => 'system', 'content' => 'You are a Data Privacy Officer expert assistant. Output purely JSON.'],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.2,
                ]);

                $result = json_decode($response->choices[0]->message->content, true);
                $score = $result['score'] ?? 50;
                $riskLevel = $result['risk_level'] ?? 'medium';
                $recommendations = $result['recommendations'] ?? ['Lakukan review berkala.'];

                // deduct credit
                $user->organization->decrement('ai_credits_remaining', 1);
            } catch (\Exception $e) {
                \Log::error('AI Vendor Assessment failed: ' . $e->getMessage());
                // Fallback deterministic
                $score = $this->calculateDeterministicScore($answers);
                $riskLevel = $this->getRiskLevel($score);
            }
        } else {
             $score = $this->calculateDeterministicScore($answers);
             $riskLevel = $this->getRiskLevel($score);
        }

        $assessment = VendorAssessment::create([
            'vendor_id' => $vendor->id,
            'org_id' => $user->org_id,
            'assessed_by' => $user->id,
            'answers' => $answers,
            'score' => $score,
            'risk_level' => $riskLevel,
            'recommendations' => $recommendations,
        ]);

        // Update vendor main stats
        $vendor->update([
            'risk_score' => $score,
            'risk_level' => $riskLevel,
            'last_assessed_at' => now(),
        ]);

        return response()->json([
            'message' => 'Assessment berhasil disimpan dan dianalisis.',
            'data' => $assessment,
            'vendor' => $vendor
        ]);
    }

    private function calculateDeterministicScore($answers)
    {
        // Simple logic base
        // Assume default medium risk
        return 45; 
    }

    private function getRiskLevel($score)
    {
        if ($score >= 80) return 'critical';
        if ($score >= 60) return 'high';
        if ($score >= 30) return 'medium';
        return 'low';
    }
}
