<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BreachSimulation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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
        if ($request->get('trash')) {
            $query->onlyTrashed();
        }

        return response()->json(['data' => $query->with('creator:id,name')->orderBy('created_at', 'desc')->get()]);
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
        if (! $template) {
            return response()->json(['message' => 'Invalid scenario type'], 422);
        }

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
            'questions' => collect($sim->scenario_data['questions'] ?? [])->map(fn ($q) => [
                'id' => $q['id'],
                'phase' => $q['phase'],
                'time_limit' => $q['time_limit'],
                'question' => $q['question'],
                'type' => $q['type'] ?? 'single',
                'options' => collect($q['options'])->map(fn ($o) => [
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
                if ($opt['id'] === $answerId) {
                    $selectedOption = $opt;
                }
                if (($opt['score'] ?? 0) >= 8) {
                    $correctOption = $opt;
                }
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

    /**
     * Persist a client-side-scored run (LIVE Visual Fire Drill, Tabletop,
     * Walkthrough). Unlike Quiz mode, these modes compute the score in the
     * browser, so they don't go through store()/start()/submit(). This single
     * endpoint records the completed run so it appears in the history table.
     */
    public function complete(Request $request)
    {
        $request->validate([
            'scenario_type' => 'required|string|max:255',
            'scenario_title' => 'required|string|max:255',
            'mode' => 'nullable|string|max:32',
            'overall_score' => 'required|integer|min:0|max:100',
            'score_breakdown' => 'nullable|array',
            'score_breakdown.mode' => 'nullable|string|max:32',
            'score_breakdown.rating' => 'nullable|string|max:64',
            'score_breakdown.score_percent' => 'nullable|numeric|min:0|max:100',
            'score_breakdown.total_score' => 'nullable|numeric',
            'score_breakdown.max_score' => 'nullable|numeric',
            'findings' => 'nullable|array',
            // Tabletop: narrative answer per step. Walkthrough: checklist state
            // per item. Both are client-supplied, so every element is validated
            // and re-shaped below before it is persisted.
            'findings.responses' => 'nullable|array|max:200',
            'findings.checklist' => 'nullable|array|max:1000',
            'findings.steps_answered' => 'nullable|integer|min:0',
            'findings.total_steps' => 'nullable|integer|min:0',
            'findings.items_checked' => 'nullable|integer|min:0',
            'findings.total_items' => 'nullable|integer|min:0',
            'findings.critical_checked' => 'nullable|integer|min:0',
            'findings.total_critical' => 'nullable|integer|min:0',
            'duration_seconds' => 'nullable|integer|min:0',
            'started_at' => 'nullable|date',
        ]);

        $orgId = $request->user()->org_id;
        if ($request->user()->role === 'superadmin' && $request->filled('org_id')) {
            $orgId = $request->org_id;
        }
        if (empty($orgId)) {
            return response()->json(['message' => 'Organization ID is required'], 422);
        }

        $now = now();
        $duration = (int) ($request->input('duration_seconds') ?? 0);
        $startedAt = $request->filled('started_at')
            ? Carbon::parse($request->input('started_at'))
            : ($duration > 0 ? $now->copy()->subSeconds($duration) : $now);

        $findings = $request->input('findings', []);
        if (! is_array($findings)) {
            $findings = [];
        }

        $findings = $this->normalizeTabletopResponses($request, $findings);
        $findings = $this->normalizeWalkthroughChecklist($request, $findings);

        $findings = array_merge([
            'participant_id' => $request->user()->id,
            'participant_name' => $request->user()->name,
            'mode' => $request->input('mode', $request->input('scenario_type')),
            'completed_at' => $now->toISOString(),
            'duration_seconds' => $duration,
        ], $findings);

        $simulation = BreachSimulation::create([
            'org_id' => $orgId,
            'scenario_title' => $request->input('scenario_title'),
            'scenario_type' => $request->input('scenario_type'),
            'scenario_description' => $request->input('mode', ''),
            'status' => 'completed',
            'overall_score' => $request->input('overall_score'),
            'score_breakdown' => $request->input('score_breakdown', []),
            'findings' => $findings,
            'started_at' => $startedAt,
            'ended_at' => $now,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Drill recorded',
            'data' => $simulation->load('creator:id,name'),
        ], 201);
    }

    /**
     * Validate + re-shape tabletop narrative answers into a stable, whitelisted
     * structure: [{ step_id, phase, response, time_spent }, ...].
     * Absent key => findings untouched (LIVE / legacy payloads).
     */
    private function normalizeTabletopResponses(Request $request, array $findings): array
    {
        if (! array_key_exists('responses', $findings)) {
            return $findings;
        }

        $responses = $findings['responses'];
        if (! is_array($responses) || ! array_is_list($responses)) {
            throw ValidationException::withMessages([
                'findings.responses' => 'findings.responses must be a list of step responses.',
            ]);
        }

        $request->validate([
            'findings.responses.*.step_id' => 'required|string|max:120',
            'findings.responses.*.phase' => 'nullable|string|max:120',
            'findings.responses.*.response' => 'nullable|string|max:20000',
            'findings.responses.*.time_spent' => 'nullable|integer|min:0|max:86400',
        ]);

        $findings['responses'] = array_map(fn ($r) => [
            'step_id' => (string) $r['step_id'],
            'phase' => isset($r['phase']) ? (string) $r['phase'] : null,
            'response' => isset($r['response']) ? (string) $r['response'] : '',
            'time_spent' => (int) ($r['time_spent'] ?? 0),
        ], $responses);

        return $findings;
    }

    /**
     * Validate + re-shape walkthrough checklist state into
     * [{ step_id, phase, item, checked, critical }, ...] and recompute the
     * critical aggregates server-side so they can never disagree with the
     * per-item data we store.
     */
    private function normalizeWalkthroughChecklist(Request $request, array $findings): array
    {
        if (! array_key_exists('checklist', $findings)) {
            return $findings;
        }

        $checklist = $findings['checklist'];
        if (! is_array($checklist) || ! array_is_list($checklist)) {
            throw ValidationException::withMessages([
                'findings.checklist' => 'findings.checklist must be a list of checklist items.',
            ]);
        }

        $request->validate([
            'findings.checklist.*.step_id' => 'required|string|max:120',
            'findings.checklist.*.phase' => 'nullable|string|max:120',
            'findings.checklist.*.item' => 'required|string|max:1000',
            'findings.checklist.*.checked' => 'required|boolean',
            'findings.checklist.*.critical' => 'nullable|boolean',
        ]);

        $normalized = array_map(fn ($c) => [
            'step_id' => (string) $c['step_id'],
            'phase' => isset($c['phase']) ? (string) $c['phase'] : null,
            'item' => (string) $c['item'],
            'checked' => filter_var($c['checked'], FILTER_VALIDATE_BOOLEAN),
            'critical' => filter_var($c['critical'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], $checklist);

        $criticals = array_filter($normalized, fn ($c) => $c['critical']);

        $findings['checklist'] = $normalized;
        $findings['total_critical'] = count($criticals);
        $findings['critical_checked'] = count(array_filter($criticals, fn ($c) => $c['checked']));

        return $findings;
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
