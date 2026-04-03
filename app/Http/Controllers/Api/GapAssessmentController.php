<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GapAssessment;
use Illuminate\Http\Request;

class GapAssessmentController extends Controller
{
    /**
     * List all assessments (with soft-deleted in trash)
     */
    public function index(Request $request)
    {
        $query = GapAssessment::where('org_id', $request->user()->org_id);

        if ($request->get('trash')) {
            $query->onlyTrashed();
        }
        if ($request->get('regulation')) {
            $query->where('regulation_code', $request->get('regulation'));
        }

        $assessments = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'data' => $assessments,
            'regulations' => \App\Models\RegulationFramework::where('is_active', true)->get()
        ]);
    }

    /**
     * Get list of active regulations
     */
    public function getRegulations()
    {
        return response()->json([
            'data' => \App\Models\RegulationFramework::where('is_active', true)->get()
        ]);
    }

    /**
     * Compare historical assessments for a specific regulation side-by-side
     */
    public function compare(Request $request)
    {
        $regCode = $request->query('regulation', 'uupdp');
        $ids = $request->query('ids');

        $query = GapAssessment::where('org_id', $request->user()->org_id)
            ->where('regulation_code', $regCode);

        if (!empty($ids) && is_array($ids)) {
            $query->whereIn('id', $ids);
        } else {
            $query->orderBy('created_at', 'desc')->take(3); // fallback to latest 3 if no selection
        }

        $assessments = $query->get();

        // Calculate category breakdowns on the fly for the radar chart
        // Structure expected by frontend Recharts (RadarChart):
        // [
        //   { category: 'Keamanan Data', 'GAP_v3.0_#4': 80, 'GAP_v3.0_#3': 50, ... },
        //   { category: 'Hak Subjek Data', 'GAP_v3.0_#4': 100, 'GAP_v3.0_#3': 60, ... }
        // ]
        
        // 1. Collect all categories from the question bank
        $qBank = GapAssessment::getQuestionBank($regCode);
        $categories = array_values(array_unique(array_column($qBank, 'category')));

        $results = [];
        foreach ($categories as $cat) {
            $row = [
                'category' => $cat
            ];
            foreach ($assessments as $assessment) {
                // Re-calculate to get breakdown
                $calc = GapAssessment::calculateScore($assessment->answers ?: [], $regCode);
                $breakdown = $calc['category_breakdown'];
                $row[$assessment->version] = $breakdown[$cat] ?? 0;
            }
            $results[] = $row;
        }

        return response()->json([
            'versions' => $assessments->pluck('version'),
            'data' => $results
        ]);
    }

    /**
     * Get the question bank
     */
    public function questions(Request $request)
    {
        $code = $request->query('regulation', 'uupdp');
        $questions = GapAssessment::getQuestionBank($code);

        // Group by category
        $grouped = [];
        foreach ($questions as $q) {
            $grouped[$q['category']][] = $q;
        }

        return response()->json([
            'questions' => $questions,
            'grouped' => $grouped,
            'total' => count($questions),
            'categories' => array_keys($grouped),
        ]);
    }

    /**
     * Start new assessment
     */
    public function store(Request $request)
    {
        $code = $request->query('regulation', 'uupdp');
        $lastVersion = GapAssessment::where('org_id', $request->user()->org_id)
            ->where('regulation_code', $code)
            ->withTrashed()
            ->count();

        $assessment = GapAssessment::create([
            'org_id' => $request->user()->org_id,
            'regulation_code' => $code,
            'version' => 'GAP_v3.0_' . strtoupper($code) . '_#' . ($lastVersion + 1),
            'overall_score' => 0,
            'compliance_level' => 'low',
            'progress' => 0,
            'answers' => [],
            'recommendations' => [],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Assessment created',
            'data' => $assessment,
        ], 201);
    }

    /**
     * Get assessment detail
     */
    public function show(string $id)
    {
        $assessment = GapAssessment::withTrashed()->findOrFail($id);

        return response()->json([
            'data' => $assessment,
            'questions' => GapAssessment::getQuestionBank($assessment->regulation_code ?? 'uupdp'),
        ]);
    }

    /**
     * Submit/update answers — auto-calculate score
     */
    public function submitAnswers(Request $request, string $id)
    {
        $request->validate([
            'answers' => 'required|array',
        ]);

        $assessment = GapAssessment::findOrFail($id);
        $answers = $request->input('answers');

        // Calculate score
        $result = GapAssessment::calculateScore($answers, $assessment->regulation_code ?? 'uupdp');

        // Calculate progress
        $totalQuestions = count(GapAssessment::getQuestionBank($assessment->regulation_code ?? 'uupdp'));
        $answeredCount = count(array_filter($answers, fn($a) => $a !== null && $a !== ''));
        $progress = round(($answeredCount / $totalQuestions) * 100);

        $assessment->update([
            'answers' => $answers,
            'overall_score' => $result['overall_score'],
            'compliance_level' => $result['compliance_level'],
            'progress' => $progress,
            'recommendations' => $result['recommendations'],
        ]);

        return response()->json([
            'message' => 'Answers saved and score calculated',
            'data' => $assessment->fresh(),
            'result' => $result,
        ]);
    }

    /**
     * Soft delete
     */
    public function destroy(string $id)
    {
        $assessment = GapAssessment::findOrFail($id);
        $assessment->delete();

        return response()->json(['message' => 'Assessment moved to trash']);
    }

    /**
     * Restore from trash
     */
    public function restore(string $id)
    {
        $assessment = GapAssessment::onlyTrashed()->findOrFail($id);
        $assessment->restore();

        return response()->json(['message' => 'Assessment restored', 'data' => $assessment]);
    }

    /**
     * Permanent delete
     */
    public function forceDelete(string $id)
    {
        $assessment = GapAssessment::onlyTrashed()->findOrFail($id);
        $assessment->forceDelete();

        return response()->json(['message' => 'Assessment permanently deleted']);
    }
}
