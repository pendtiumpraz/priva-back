<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GapAssessment;
use App\Models\CustomGapQuestion;
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
            $reg = $request->get('regulation');
            if ($reg === 'uupdp') {
                // Include legacy records with NULL regulation_code
                $query->where(function($q) use ($reg) {
                    $q->where('regulation_code', $reg)->orWhereNull('regulation_code');
                });
            } else {
                $query->where('regulation_code', $reg);
            }
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

        if (!$ids) {
            return response()->json(['error' => 'ids required'], 400);
        }

        $idList = explode(',', $ids);
        $assessments = GapAssessment::where('org_id', $request->user()->org_id)
            ->whereIn('id', $idList)
            ->orderBy('created_at')
            ->get();

        $questions = GapAssessment::getQuestionBank($regCode);
        $categories = array_values(array_unique(array_column($questions, 'category')));

        $results = [];
        foreach ($categories as $cat) {
            $row = ['category' => $cat];
            foreach ($assessments as $assessment) {
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

        // Merge custom questions from this org
        $orgId = $request->user()->org_id;
        if ($orgId) {
            $customQuestions = CustomGapQuestion::forOrg($orgId)
                ->forRegulation($code)
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->map(fn($q) => $q->toQuestionFormat())
                ->toArray();
            $questions = array_merge($questions, $customQuestions);
        }

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
        
        // Check cooldown (90 days)
        $lastAssessment = GapAssessment::where('org_id', $request->user()->org_id)
            ->where('regulation_code', $code)
            ->orderBy('created_at', 'desc')
            ->first();
        
        $cooldownWarning = null;
        if ($lastAssessment) {
            $daysSince = (int) $lastAssessment->created_at->diffInDays(now());
            if ($daysSince < 90) {
                $timeString = $daysSince === 0 ? 'hari ini' : "{$daysSince} hari yang lalu";
                $nextDue = $lastAssessment->created_at->addDays(90)->format('d M Y');
                $cooldownWarning = "Assessment terakhir baru dilakukan {$timeString}. Assessment berikutnya disarankan pada {$nextDue}.";
            }
        }

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
            'attachments' => [],
            'recommendations' => [],
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Assessment created',
            'data' => $assessment,
            'cooldown_warning' => $cooldownWarning,
        ], 201);
    }

    /**
     * Get assessment detail
     */
    public function show(Request $request, string $id)
    {
        $assessment = GapAssessment::withTrashed()->findOrFail($id);
        $code = $assessment->regulation_code ?? 'uupdp';
        $questions = GapAssessment::getQuestionBank($code);

        // Merge custom questions
        $orgId = $request->user()->org_id;
        if ($orgId) {
            $custom = CustomGapQuestion::forOrg($orgId)->forRegulation($code)->active()->orderBy('sort_order')->get();
            $questions = array_merge($questions, $custom->map(fn($q) => $q->toQuestionFormat())->toArray());
        }

        return response()->json([
            'data' => $assessment,
            'questions' => $questions,
        ]);
    }

    /**
     * Submit/update answers — auto-calculate score
     */
    public function submitAnswers(Request $request, string $id)
    {
        $request->validate([
            'answers' => 'required|array',
            'attachments' => 'nullable|array',
        ]);

        $assessment = GapAssessment::findOrFail($id);
        $answers = $request->input('answers');
        $attachmentsInput = $request->input('attachments', []);

        // Calculate score
        $result = GapAssessment::calculateScore($answers, $assessment->regulation_code ?? 'uupdp');

        // Calculate progress (include custom questions)
        $customCount = CustomGapQuestion::forOrg($assessment->org_id)->forRegulation($assessment->regulation_code ?? 'uupdp')->active()->count();
        $totalQuestions = count(GapAssessment::getQuestionBank($assessment->regulation_code ?? 'uupdp')) + $customCount;
        $answeredCount = count(array_filter($answers, fn($a) => $a !== null && $a !== ''));
        $progress = round(($answeredCount / $totalQuestions) * 100);

        $assessment->update([
            'answers' => $answers,
            'attachments' => $attachmentsInput,
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
        
        // Cleanup attachments
        if ($assessment->attachments) {
            foreach ($assessment->attachments as $questionPaths) {
                if (is_array($questionPaths)) {
                    foreach ($questionPaths as $path) {
                        if ($path) \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
                    }
                }
            }
        }
        
        $assessment->forceDelete();

        return response()->json(['message' => 'Assessment permanently deleted']);
    }

    // =============================================
    // Evidence Upload (Sprint B3)
    // =============================================

    public function uploadEvidence(Request $request, string $id)
    {
        $request->validate([
            'question_id' => 'required|string',
            'file' => 'required|file|max:10240|mimes:pdf,png,jpg,jpeg,docx',
        ]);

        $assessment = GapAssessment::findOrFail($id);
        
        $file = $request->file('file');
        $path = $file->storeAs(
            "org/{$assessment->org_id}/gap/{$assessment->id}/evidence",
            uniqid() . '_' . preg_replace('/[^A-Za-z0-9.\-]/', '_', $file->getClientOriginalName()),
            'public'
        );

        $attachments = $assessment->attachments ?? [];
        $qId = $request->question_id;
        
        if (!isset($attachments[$qId])) {
            $attachments[$qId] = [];
        }
        
        $attachments[$qId][] = [
            'path' => $path,
            'url' => asset('storage/' . $path),
            'name' => $file->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String()
        ];

        $assessment->update(['attachments' => $attachments]);

        return response()->json([
            'message' => 'Evidence uploaded',
            'data' => end($attachments[$qId]),
            'attachments' => $attachments
        ]);
    }

    // =============================================
    // Custom Questions CRUD (Sprint B2)
    // =============================================

    public function customQuestions(Request $request)
    {
        $code = $request->query('regulation', 'uupdp');
        $questions = CustomGapQuestion::forOrg($request->user()->org_id)
            ->forRegulation($code)
            ->orderBy('sort_order')
            ->get();

        return response()->json(['data' => $questions]);
    }

    public function storeCustomQuestion(Request $request)
    {
        $request->validate([
            'regulation_code' => 'required|string|max:20',
            'category' => 'required|string|max:255',
            'question' => 'required|string',
            'recommendation' => 'required|string',
            'weight' => 'nullable|numeric|min:0.1|max:10',
            'article' => 'nullable|string|max:100',
            'explanation' => 'nullable|string',
        ]);

        $question = CustomGapQuestion::create([
            'org_id' => $request->user()->org_id,
            'regulation_code' => $request->regulation_code,
            'category' => $request->category,
            'subcategory' => $request->subcategory,
            'question' => $request->question,
            'explanation' => $request->explanation,
            'recommendation' => $request->recommendation,
            'weight' => $request->weight ?? 1.0,
            'article' => $request->article,
            'sort_order' => CustomGapQuestion::forOrg($request->user()->org_id)->max('sort_order') + 1,
        ]);

        return response()->json(['message' => 'Custom question created', 'data' => $question], 201);
    }

    public function updateCustomQuestion(Request $request, string $id)
    {
        $question = CustomGapQuestion::forOrg($request->user()->org_id)->findOrFail($id);

        $request->validate([
            'category' => 'sometimes|string|max:255',
            'question' => 'sometimes|string',
            'recommendation' => 'sometimes|string',
            'weight' => 'nullable|numeric|min:0.1|max:10',
        ]);

        $question->update($request->only([
            'category', 'subcategory', 'question', 'explanation',
            'recommendation', 'weight', 'article', 'sort_order', 'is_active',
        ]));

        return response()->json(['message' => 'Custom question updated', 'data' => $question->fresh()]);
    }

    public function destroyCustomQuestion(Request $request, string $id)
    {
        $question = CustomGapQuestion::forOrg($request->user()->org_id)->findOrFail($id);
        $question->delete();

        return response()->json(['message' => 'Custom question deleted']);
    }
}
