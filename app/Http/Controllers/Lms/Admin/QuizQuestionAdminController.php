<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\Admin\StoreQuizQuestionRequest;
use App\Http\Requests\Lms\Admin\UpdateQuizQuestionRequest;
use App\Lms\Concerns\OrgScopedQuery;
use App\Lms\Concerns\QuizOwnerResolution;
use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for Quiz Questions.
 *
 * Schema notes (vs spec §3.7):
 *  - DB column is `prompt` (not question_text). Map at boundary.
 *  - DB column is `order` (not sort_order). Map at boundary.
 *  - No `explanation` column — dropped from contract.
 *  - Options stored as jsonb on the question row, NOT a side table.
 *    Internal shape: [{key, label, is_correct}], correct_answer: [keys].
 *    External shape: [{id, text, is_correct}], correct_answer: bool (true_false).
 *
 * Org-scope flows through quiz->owner_type->parent (Module->Course or Course).
 * feature_doc-owned quizzes are global (read for tenant; mutate root-only).
 */
class QuizQuestionAdminController extends Controller
{
    use OrgScopedQuery;
    use QuizOwnerResolution;

    /**
     * GET /admin/quizzes/{quizId}/questions
     */
    public function index(Request $request, $quizId): JsonResponse
    {
        $quiz = Quiz::find($quizId);
        if (! $quiz || ! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        $questions = QuizQuestion::where('quiz_id', $quiz->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $questions->map(fn (QuizQuestion $q) => $this->toShowResource($q))->all(),
        ]);
    }

    /**
     * POST /admin/quizzes/{quizId}/questions/reorder
     *
     * Body: { order: [questionId, ...] } — must list every question of the
     * quiz exactly once. Persists 1-based `order` matching the array sequence.
     * Mirrors ModuleAdminController::reorder; also covers course-exam questions
     * (exams are quizzes with owner_type='course').
     */
    public function reorder(Request $request, $quizId): JsonResponse
    {
        $quiz = Quiz::find($quizId);
        if (! $quiz || ! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        $this->assertOwnerMutable($quiz->owner_type, $quiz->owner_key);

        $validated = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'integer', 'exists:lms_quiz_questions,id'],
        ]);
        $ids = array_map('intval', $validated['order']);

        $quizQuestionIds = QuizQuestion::where('quiz_id', $quiz->id)->pluck('id')->all();

        // Exhaustive + unique + all-belonging: input must be exactly this
        // quiz's question set, each once. Partial reorders would leave gaps.
        if (
            count(array_unique($ids)) !== count($ids)
            || count($ids) !== count($quizQuestionIds)
            || array_diff($ids, $quizQuestionIds)
            || array_diff($quizQuestionIds, $ids)
        ) {
            throw ValidationException::withMessages([
                'order' => ['order must include every question in this quiz exactly once.'],
            ]);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $questionId) {
                QuizQuestion::where('id', $questionId)->update(['order' => $index + 1]);
            }
        });

        $questions = QuizQuestion::where('quiz_id', $quiz->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $questions->map(fn (QuizQuestion $q) => $this->toShowResource($q))->all(),
        ]);
    }

    /**
     * POST /admin/quizzes/{quizId}/questions
     */
    public function store(StoreQuizQuestionRequest $request, $quizId): JsonResponse
    {
        $quiz = Quiz::find($quizId);
        if (! $quiz || ! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        $this->assertOwnerMutable($quiz->owner_type, $quiz->owner_key);

        $data = $request->validated();

        $sortOrder = $data['sort_order']
            ?? ((int) QuizQuestion::where('quiz_id', $quiz->id)->max('order') + 1);

        $payload = [
            'quiz_id' => $quiz->id,
            'type'    => $data['type'],
            'prompt'  => $data['question_text'],
            'points'  => $data['points'] ?? 1,
            'order'   => $sortOrder,
        ];

        if ($data['type'] === 'mcq') {
            $payload['options'] = $this->mapOptionsToDb($data['options']);
            $payload['correct_answer'] = collect($data['options'])
                ->filter(fn ($o) => ! empty($o['is_correct']))
                ->pluck('id')
                ->values()
                ->all();
        } else { // true_false
            $payload['options'] = null;
            $payload['correct_answer'] = [(bool) $data['correct_answer']];
        }

        $question = QuizQuestion::create($payload);

        return response()->json([
            'data' => $this->toShowResource($question->fresh()),
        ], 201);
    }

    /**
     * GET /admin/questions/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $question = QuizQuestion::find($id);
        if (! $question) {
            return response()->json(['message' => 'Question not found.'], 404);
        }
        $quiz = Quiz::find($question->quiz_id);
        if (! $quiz || ! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        return response()->json([
            'data' => $this->toShowResource($question),
        ]);
    }

    /**
     * PUT /admin/questions/{id}
     */
    public function update(UpdateQuizQuestionRequest $request, $id): JsonResponse
    {
        $question = QuizQuestion::find($id);
        if (! $question) {
            return response()->json(['message' => 'Question not found.'], 404);
        }
        $quiz = Quiz::find($question->quiz_id);
        if (! $quiz || ! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        $this->assertOwnerMutable($quiz->owner_type, $quiz->owner_key);

        $data = $request->validated();

        if (array_key_exists('question_text', $data)) {
            $question->prompt = $data['question_text'];
        }
        if (array_key_exists('sort_order', $data) && $data['sort_order'] !== null) {
            $question->order = $data['sort_order'];
        }
        if (array_key_exists('points', $data) && $data['points'] !== null) {
            $question->points = $data['points'];
        }

        if ($question->type === 'mcq' && array_key_exists('options', $data)) {
            $question->options = $this->mapOptionsToDb($data['options']);
            $question->correct_answer = collect($data['options'])
                ->filter(fn ($o) => ! empty($o['is_correct']))
                ->pluck('id')
                ->values()
                ->all();
        }

        if ($question->type === 'true_false' && array_key_exists('correct_answer', $data)) {
            $question->correct_answer = [(bool) $data['correct_answer']];
        }

        $question->save();

        return response()->json([
            'data' => $this->toShowResource($question->fresh()),
        ]);
    }

    /**
     * DELETE /admin/questions/{id}
     */
    public function destroy(Request $request, $id)
    {
        $question = QuizQuestion::find($id);
        if (! $question) {
            return response()->json(['message' => 'Question not found.'], 404);
        }
        $quiz = Quiz::find($question->quiz_id);
        if (! $quiz || ! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Question not found.'], 404);
        }

        $this->assertOwnerMutable($quiz->owner_type, $quiz->owner_key);

        $question->delete();

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // (canViewQuiz / assertOwnerMutable live in QuizOwnerResolution trait)
    // -------------------------------------------------------------------------

    /**
     * External {id,text,is_correct} -> internal {key,label,is_correct}.
     * is_correct is preserved on the option row for round-tripping; the DB
     * also stores correct_answer separately for the grader.
     */
    protected function mapOptionsToDb(array $options): array
    {
        return array_map(fn ($o) => [
            'key'        => (string) $o['id'],
            'label'      => (string) $o['text'],
            'is_correct' => (bool) ($o['is_correct'] ?? false),
        ], $options);
    }

    /**
     * Build the show/list resource per spec §3.7:
     *   - mcq: { options: [{id,text,is_correct}, ...] }
     *   - true_false: { correct_answer: bool }
     */
    protected function toShowResource(QuizQuestion $q): array
    {
        $base = [
            'id'            => $q->id,
            'quiz_id'       => $q->quiz_id,
            'type'          => $q->type,
            'sort_order'    => (int) ($q->order ?? 0),
            'points'        => (int) ($q->points ?? 1),
            'question_text' => $q->prompt,
        ];

        if ($q->type === 'mcq') {
            $opts = is_array($q->options) ? $q->options : [];
            $base['options'] = array_map(fn ($o) => [
                'id'         => (string) ($o['key'] ?? $o['id'] ?? ''),
                'text'       => (string) ($o['label'] ?? $o['text'] ?? ''),
                'is_correct' => (bool) ($o['is_correct'] ?? false),
            ], $opts);
        } else { // true_false
            $ca = is_array($q->correct_answer) ? ($q->correct_answer[0] ?? false) : false;
            $base['correct_answer'] = (bool) $ca;
        }

        return $base;
    }
}
