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

        $assessments = $query->orderBy('created_at', 'desc')->get();

        return response()->json(['data' => $assessments]);
    }

    /**
     * Get the question bank
     */
    public function questions()
    {
        $questions = GapAssessment::getQuestionBank();

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
        $lastVersion = GapAssessment::where('org_id', $request->user()->org_id)
            ->withTrashed()
            ->count();

        $assessment = GapAssessment::create([
            'org_id' => $request->user()->org_id,
            'version' => 'GAP_v3.0_#' . ($lastVersion + 1),
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
            'questions' => GapAssessment::getQuestionBank(),
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
        $result = GapAssessment::calculateScore($answers);

        // Calculate progress
        $totalQuestions = count(GapAssessment::getQuestionBank());
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
