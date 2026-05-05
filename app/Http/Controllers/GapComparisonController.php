<?php

namespace App\Http\Controllers;

use App\Models\GapAssessment;
use App\Models\GapComparison;
use App\Services\AiService;
use App\Services\GapBenchmarkService;
use Illuminate\Http\Request;

class GapComparisonController extends Controller
{
    public function index(Request $request)
    {
        $data = GapComparison::where('org_id', $request->user()->org_id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
            'regulation_code' => 'required|string',
            'ids' => 'required|array|min:2',
        ]);

        $regCode = $request->input('regulation_code');
        $ids = $request->input('ids');

        $assessments = GapAssessment::whereIn('id', $ids)->orderBy('created_at', 'asc')->get();
        if ($assessments->count() < 2) {
            return response()->json(['message' => 'Minimum 2 assessments are required'], 400);
        }

        $qBank = GapAssessment::getQuestionBank($regCode);
        $categories = array_values(array_unique(array_column($qBank, 'category')));

        $chartData = [];
        $trends = []; // Store the first and last score per category

        $firstAssessment = $assessments->first();
        $lastAssessment = $assessments->last();
        $calcFirst = GapAssessment::calculateScore($firstAssessment->answers ?: [], $regCode);
        $calcLast = GapAssessment::calculateScore($lastAssessment->answers ?: [], $regCode);

        foreach ($categories as $cat) {
            $row = ['category' => $cat];
            foreach ($assessments as $assessment) {
                $calc = GapAssessment::calculateScore($assessment->answers ?: [], $regCode);
                $row[$assessment->version] = $calc['category_breakdown'][$cat] ?? 0;
            }
            $chartData[] = $row;
            
            // Calculate trend delta (Last - First)
            $startScore = $calcFirst['category_breakdown'][$cat] ?? 0;
            $endScore = $calcLast['category_breakdown'][$cat] ?? 0;
            $trends[$cat] = [
                'start' => $startScore,
                'end' => $endScore,
                'delta' => $endScore - $startScore
            ];
        }

        // Generate System Analysis text
        $highestDeltaCategory = null;
        $highestDeltaValue = -999;
        $lowestDeltaCategory = null;
        $lowestDeltaValue = 999;
        
        foreach ($trends as $cat => $trend) {
            if ($trend['delta'] > $highestDeltaValue) {
                $highestDeltaValue = $trend['delta'];
                $highestDeltaCategory = $cat;
            }
            if ($trend['delta'] < $lowestDeltaValue) {
                $lowestDeltaValue = $trend['delta'];
                $lowestDeltaCategory = $cat;
            }
        }

        $systemAnalysis = "Perbandingan progres dari versi {$firstAssessment->version} ke {$lastAssessment->version}:\n\n";
        $systemAnalysis .= "- **Peningkatan Signifikan:** Topik '{$highestDeltaCategory}' meningkat sebesar {$highestDeltaValue}%.\n";
        if ($lowestDeltaValue < 0) {
            $systemAnalysis .= "- **Penurunan Tren:** Terdapat regresi kepatuhan pada '{$lowestDeltaCategory}' sebesar {$lowestDeltaValue}%.\n";
        } elseif ($lowestDeltaValue == 0) {
            $systemAnalysis .= "- **Stagnan:** Topik '{$lowestDeltaCategory}' tidak mengalami perubahan.\n";
        }

        $comparison = GapComparison::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'org_id' => $request->user()->org_id,
            'title' => $request->title,
            'regulation_code' => $regCode,
            'assessment_ids' => $ids,
            'chart_data' => $chartData,
            'system_analysis' => $systemAnalysis,
            'created_by' => $request->user()->id,
        ]);

        // Kick off async AI Analysis queue
        // In this implementation, we will perform it synchronously for demo purposes
        try {
            $aiService = app(AiService::class);
            $prompt = "Berikan analisa mendalam sebagai DPO (Data Protection Officer) berdasarkan komparasi trend gap assessment secara historis. Berikut datanya: " . json_encode($trends);
            // using the same provider configured in AiService or default 'genai'
            // We need a helper from AiService. We can use processQuery or just plain execute
            $aiRes = \App\Models\AiResult::create([
                'org_id' => $request->user()->org_id,
                'user_id' => $request->user()->id,
                'module' => 'gap_comparison',
                'record_id' => $comparison->id,
                'prompt' => $prompt,
                'status' => 'pending'
            ]);
            
            // To run without blocking, we could use a Job, but let's call generate directly if it's available:
            // Since we don't have a direct execute here, we'll let the user generate AI insight via frontend later OR generate now
        } catch (\Exception $e) {}

        return response()->json(['data' => $comparison], 201);
    }

    /**
     * GET /api/gap/benchmark — return per-category targets for the caller's
     * organization industry (or `?industry=` override). Used by the FE
     * "Bandingkan ke Standar Industri" flow before saving the comparison.
     */
    public function benchmark(Request $request)
    {
        $regCode = $request->query('regulation', 'uupdp');
        $industry = $request->query('industry') ?? optional($request->user()->organization)->industry;
        $series = GapBenchmarkService::buildSeriesFor($industry, $regCode);

        return response()->json([
            'regulation_code' => $regCode,
            'industry' => $industry,
            'industry_label' => $series['industry_label'],
            'industry_scores' => $series['industry'],
            'minimum_scores' => $series['minimum'],
            'available_industries' => GapBenchmarkService::listIndustries(),
        ]);
    }

    /**
     * POST /api/gap/comparisons/benchmark — create a saved comparison
     * between one assessment and the industry standard + UU PDP minimum.
     * Stored as a regular GapComparison row (chart_data carries 3 series:
     * "Anda", "Standar Industri", "Minimum UU PDP").
     */
    public function storeBenchmark(Request $request)
    {
        $request->validate([
            'assessment_id' => 'required|string|exists:gap_assessments,id',
            'title' => 'nullable|string',
            'industry' => 'nullable|string',
        ]);

        $assessment = GapAssessment::where('org_id', $request->user()->org_id)
            ->where('id', $request->input('assessment_id'))
            ->firstOrFail();

        $regCode = $assessment->regulation_code ?? 'uupdp';
        $industry = $request->input('industry') ?? optional($request->user()->organization)->industry;

        $qBank = GapAssessment::getQuestionBank($regCode);
        $categories = array_values(array_unique(array_column($qBank, 'category')));

        $calc = GapAssessment::calculateScore($assessment->answers ?: [], $regCode);
        $series = GapBenchmarkService::buildSeriesFor($industry, $regCode);

        $youLabel = $assessment->version ?: 'Anda';
        $industryLabel = "Standar {$series['industry_label']}";
        $minimumLabel = 'Minimum UU PDP';

        $chartData = [];
        $gaps = [];
        foreach ($categories as $cat) {
            $you = (float) ($calc['category_breakdown'][$cat] ?? 0);
            $ind = (float) ($series['industry'][$cat] ?? 0);
            $min = (float) ($series['minimum'][$cat] ?? 0);
            $chartData[] = [
                'category' => $cat,
                $youLabel => $you,
                $industryLabel => $ind,
                $minimumLabel => $min,
            ];
            $gaps[$cat] = [
                'you' => $you,
                'industry' => $ind,
                'minimum' => $min,
                'gap_to_industry' => round($you - $ind, 1),
                'gap_to_minimum' => round($you - $min, 1),
            ];
        }

        // Build a quick narrative
        $belowMinimum = array_filter($gaps, fn ($g) => $g['gap_to_minimum'] < 0);
        $belowIndustry = array_filter($gaps, fn ($g) => $g['gap_to_industry'] < 0);

        $analysis = "Perbandingan terhadap **{$series['industry_label']}** & Minimum UU PDP:\n\n";
        if (! empty($belowMinimum)) {
            $analysis .= "- ⚠️ Kategori di bawah ambang Minimum UU PDP: ".implode(', ', array_map(fn ($k) => "**{$k}** (gap ".$gaps[$k]['gap_to_minimum'].'%)', array_keys($belowMinimum)))."\n";
        } else {
            $analysis .= "- ✅ Semua kategori memenuhi Minimum UU PDP.\n";
        }
        if (! empty($belowIndustry)) {
            $analysis .= '- 📉 Di bawah standar industri pada: '.implode(', ', array_map(fn ($k) => "**{$k}** (gap ".$gaps[$k]['gap_to_industry'].'%)', array_keys($belowIndustry)))."\n";
        } else {
            $analysis .= "- 🏆 Di atas/setara standar industri di seluruh kategori.\n";
        }

        $title = $request->input('title') ?: "Bandingkan ke {$series['industry_label']} — {$assessment->version}";

        $comparison = GapComparison::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'org_id' => $request->user()->org_id,
            'title' => $title,
            'regulation_code' => $regCode,
            'assessment_ids' => [$assessment->id],
            'chart_data' => $chartData,
            'system_analysis' => $analysis,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $comparison, 'meta' => [
            'industry' => $industry,
            'industry_label' => $series['industry_label'],
            'gaps' => $gaps,
        ]], 201);
    }
}
