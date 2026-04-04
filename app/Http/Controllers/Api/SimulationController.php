<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreachSimulation;
use Illuminate\Http\Request;

class SimulationController extends Controller
{
    private function getQuery(Request $request)
    {
        $query = BreachSimulation::query();
        if ($request->user()->role !== 'superadmin') {
            $query->where('org_id', $request->user()->org_id);
        } elseif ($request->filled('org_id')) {
            $query->where('org_id', $request->org_id);
        }
        return $query;
    }

    public function index(Request $request)
    {
        $query = $this->getQuery($request);
        if ($request->get('trash'))
            $query->onlyTrashed();
        return response()->json(['data' => $query->orderBy('created_at', 'desc')->get()]);
    }

    /**
     * Get available scenario templates
     */
    public function scenarios()
    {
        $templates = BreachSimulation::getScenarioTemplates();
        $summaries = [];
        foreach ($templates as $key => $t) {
            $summaries[] = [
                'type' => $key,
                'title' => $t['title'],
                'emoji' => $t['emoji'],
                'description' => $t['description'],
                'total_questions' => count($t['questions']),
            ];
        }
        return response()->json(['scenarios' => $summaries]);
    }

    /**
     * Create a new drill
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'scenario_type' => 'required|string',
        ]);

        $templates = BreachSimulation::getScenarioTemplates();
        $template = $templates[$request->scenario_type] ?? null;
        if (!$template)
            return response()->json(['message' => 'Invalid scenario type'], 422);

        $orgId = $request->user()->org_id;
        if ($request->user()->role === 'superadmin' && $request->filled('org_id')) {
            $orgId = $request->org_id;
        }

        if (empty($orgId)) {
            return response()->json(['message' => 'Organization ID is required'], 422);
        }

        $simulation = BreachSimulation::create([
            'org_id' => $orgId,
            'scenario_title' => $request->title,
            'scenario_type' => $request->scenario_type,
            'scenario_description' => $template['description'] ?? '',
            'status' => 'scheduled',
            'scenario_data' => $template,
            'participants' => [],
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Simulation created', 'data' => $simulation], 201);
    }

    /**
     * Get drill detail with full scenario
     */
    public function show(Request $request, string $id)
    {
        $sim = $this->getQuery($request)->withTrashed()->findOrFail($id);
        return response()->json(['data' => $sim]);
    }

    /**
     * Start the drill
     */
    public function start(Request $request, string $id)
    {
        $sim = $this->getQuery($request)->findOrFail($id);
        if ($sim->status !== 'draft' && $sim->status !== 'scheduled') {
            return response()->json(['message' => 'Drill already started or completed'], 422);
        }

        $sim->update([
            'status' => 'running',
            'started_at' => now(),
        ]);

        return response()->json([
            'message' => 'Drill started!',
            'data' => $sim,
            'briefing' => $sim->scenario_data['briefing'] ?? '',
            'questions' => collect($sim->scenario_data['questions'] ?? [])->map(fn($q) => [
        'id' => $q['id'],
        'phase' => $q['phase'],
        'time_limit' => $q['time_limit'],
        'question' => $q['question'],
        'type' => $q['type'] ?? 'single',
        'options' => collect($q['options'])->map(fn($o) => [
        'id' => $o['id'],
        'text' => $o['text'],
        // Don't expose score/feedback until answer submitted
        ]),
        ]),
        ]);
    }

    /**
     * Submit drill responses and get scored results
     */
    public function submitResponses(Request $request, string $id)
    {
        $request->validate([
            'responses' => 'required|array',
        ]);

        $sim = $this->getQuery($request)->findOrFail($id);
        if ($sim->status !== 'in_progress' && $sim->status !== 'running') {
            return response()->json(['message' => 'Drill not in progress'], 422);
        }

        $scenario = $sim->scenario_data;
        $responses = $request->input('responses');

        // Calculate scores
        $result = BreachSimulation::calculateDrillScore($scenario, $responses);

        // Save results
        $sim->update([
            'status' => 'completed',
            'ended_at' => now(),
            'overall_score' => $result['score_percent'],
            'score_breakdown' => $result,
            'findings' => [
                'participant_id' => $request->user()->id,
                'participant_name' => $request->user()->name,
                'responses' => $responses,
                'completed_at' => now()->toISOString(),
                'duration_seconds' => $sim->started_at ? now()->diffInSeconds($sim->started_at) : 0,
            ],
        ]);

        // Return full results with feedback
        $detailedResults = [];
        foreach ($scenario['questions'] as $q) {
            $qResult = $result['question_results'][$q['id']] ?? null;
            $response = $responses[$q['id']] ?? null;
            $answerId = $response['answer'] ?? null;

            $selectedOption = null;
            $correctOption = null;
            foreach ($q['options'] as $opt) {
                if ($opt['id'] === $answerId)
                    $selectedOption = $opt;
                if (($opt['score'] ?? 0) >= 8)
                    $correctOption = $opt;
            }

            $detailedResults[] = [
                'question_id' => $q['id'],
                'phase' => $q['phase'],
                'question' => $q['question'],
                'your_answer' => $selectedOption ? $selectedOption['text'] : 'No answer',
                'feedback' => $qResult['feedback'] ?? '',
                'score_earned' => $qResult['earned'] ?? 0,
                'score_max' => $qResult['max'] ?? 0,
                'correct_answer' => $correctOption ? $correctOption['text'] : null,
            ];
        }

        return response()->json([
            'message' => 'Drill completed!',
            'score' => $result,
            'detailed_results' => $detailedResults,
            'data' => $sim->fresh(),
        ]);
    }

    public function destroy(string $id)
    {
        BreachSimulation::findOrFail($id)->delete();
        return response()->json(['message' => 'Moved to trash']);
    }

    public function restore(string $id)
    {
        $sim = BreachSimulation::onlyTrashed()->findOrFail($id);
        $sim->restore();
        return response()->json(['message' => 'Restored', 'data' => $sim]);
    }

    public function forceDelete(string $id)
    {
        BreachSimulation::onlyTrashed()->findOrFail($id)->forceDelete();
        return response()->json(['message' => 'Permanently deleted']);
    }
}
