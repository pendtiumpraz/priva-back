<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\Admin\StoreQuizRequest;
use App\Http\Requests\Lms\Admin\UpdateQuizRequest;
use App\Lms\Concerns\OrgScopedQuery;
use App\Lms\Concerns\QuizOwnerResolution;
use App\Lms\Models\Quiz;
use App\Lms\Models\QuizQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin CRUD for LMS Quizzes.
 *
 * Schema notes (vs spec §3.6):
 *  - Quiz table is keyed by (owner_type, owner_key); spec's `lesson_id` does
 *    not map (no quiz<->lesson FK exists). Quizzes attach to modules,
 *    courses, or feature_doc sections.
 *  - No slug, no description, no published/status, no SoftDeletes.
 *  - DB column is `time_limit_seconds`; API contract uses `time_limit_mins`
 *    (mapped at boundary: minutes * 60 on write, round(seconds/60) on read).
 *  - Quizzes have no direct org_id. Org-scope is enforced via the parent:
 *      owner_type='module' -> Module -> Course (org_id)
 *      owner_type='course' -> Course (org_id)
 *      owner_type='feature_doc' -> global (no org); only root may mutate.
 */
class QuizAdminController extends Controller
{
    use OrgScopedQuery;
    use QuizOwnerResolution;

    /**
     * GET /admin/quizzes
     */
    public function index(Request $request): JsonResponse
    {
        $query = Quiz::query()
            ->withCount('questions')
            ->selectSub(
                DB::table('lms_quiz_attempts')
                    ->whereColumn('lms_quiz_attempts.quiz_id', 'lms_quizzes.id')
                    ->selectRaw('count(*)'),
                'attempt_count'
            );

        $this->scopeQuizzesForAdmin($query);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('owner_key', 'like', "%{$search}%");
            });
        }

        $query->orderBy('id');
        $paginator = $query->paginate(20);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Quiz $q) => $this->toListResource($q))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * POST /admin/quizzes
     */
    public function store(StoreQuizRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Resolve owner_type / owner_key from the convenience inputs.
        [$ownerType, $ownerKey] = $this->resolveOwnerInput($data);

        // Verify the parent is mutable by this user.
        $this->assertOwnerMutable($ownerType, $ownerKey);

        $payload = [
            'owner_type'    => $ownerType,
            'owner_key'     => $ownerKey,
            'title'         => $data['title'] ?? null,
            'passing_score' => $data['passing_score'],
            'max_attempts'  => $data['max_attempts'] ?? null,
        ];
        if (array_key_exists('time_limit_mins', $data) && $data['time_limit_mins'] !== null) {
            $payload['time_limit_seconds'] = ((int) $data['time_limit_mins']) * 60;
        }

        $quiz = Quiz::create($payload);

        return response()->json([
            'data' => $this->toShowResource($quiz->fresh()->load('questions')),
        ], 201);
    }

    /**
     * GET /admin/quizzes/{id}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $quiz = Quiz::with('questions')->find($id);
        if (! $quiz) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        if (! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        return response()->json([
            'data' => $this->toShowResource($quiz),
        ]);
    }

    /**
     * PUT /admin/quizzes/{id}
     */
    public function update(UpdateQuizRequest $request, $id): JsonResponse
    {
        $quiz = Quiz::find($id);
        if (! $quiz) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        if (! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        $this->assertOwnerMutable($quiz->owner_type, $quiz->owner_key);

        $data = $request->validated();

        if (array_key_exists('title', $data)) {
            $quiz->title = $data['title'];
        }
        if (array_key_exists('passing_score', $data)) {
            $quiz->passing_score = $data['passing_score'];
        }
        if (array_key_exists('max_attempts', $data)) {
            $quiz->max_attempts = $data['max_attempts'];
        }
        if (array_key_exists('time_limit_mins', $data)) {
            $quiz->time_limit_seconds = $data['time_limit_mins'] !== null
                ? ((int) $data['time_limit_mins']) * 60
                : null;
        }

        $quiz->save();

        return response()->json([
            'data' => $this->toShowResource($quiz->fresh()->load('questions')),
        ]);
    }

    /**
     * DELETE /admin/quizzes/{id}
     */
    public function destroy(Request $request, $id)
    {
        $quiz = Quiz::find($id);
        if (! $quiz) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        if (! $this->canViewQuiz($quiz)) {
            return response()->json(['message' => 'Quiz not found.'], 404);
        }

        $this->assertOwnerMutable($quiz->owner_type, $quiz->owner_key);

        // No SoftDeletes on Quiz -> hard delete (cascades to questions via FK).
        $quiz->delete();

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve canonical (owner_type, owner_key) from the request payload's
     * convenience fields.
     */
    protected function resolveOwnerInput(array $data): array
    {
        if (! empty($data['module_id'])) {
            return ['module', (string) $data['module_id']];
        }
        if (! empty($data['course_id'])) {
            return ['course', (string) $data['course_id']];
        }
        return [$data['owner_type'], (string) $data['owner_key']];
    }

    /**
     * Index/list resource shape (per spec §3.6 — slug/lesson_id mapped to
     * owner_type/owner_key on this side).
     */
    protected function toListResource(Quiz $quiz): array
    {
        return [
            'id'              => $quiz->id,
            'title'           => $quiz->title,
            'owner_type'      => $quiz->owner_type,
            'owner_key'       => $quiz->owner_key,
            'passing_score'   => (int) $quiz->passing_score,
            'time_limit_mins' => $quiz->time_limit_seconds !== null
                ? (int) round((int) $quiz->time_limit_seconds / 60)
                : null,
            'max_attempts'    => $quiz->max_attempts,
            'questions_count' => (int) ($quiz->questions_count ?? 0),
            'attempt_count'   => (int) ($quiz->attempt_count ?? 0),
        ];
    }

    /**
     * Show/store/update resource shape — adds questions, timestamps.
     */
    protected function toShowResource(Quiz $quiz): array
    {
        $base = [
            'id'                 => $quiz->id,
            'title'              => $quiz->title,
            'owner_type'         => $quiz->owner_type,
            'owner_key'          => $quiz->owner_key,
            'passing_score'      => (int) $quiz->passing_score,
            'time_limit_mins'    => $quiz->time_limit_seconds !== null
                ? (int) round((int) $quiz->time_limit_seconds / 60)
                : null,
            'max_attempts'       => $quiz->max_attempts,
            'created_at'         => $quiz->created_at?->toIso8601String(),
            'updated_at'         => $quiz->updated_at?->toIso8601String(),
        ];

        if ($quiz->relationLoaded('questions')) {
            $base['questions'] = $quiz->questions->map(fn (QuizQuestion $q) => [
                'id'            => $q->id,
                'type'          => $q->type,
                'sort_order'    => (int) ($q->order ?? 0),
                'question_text' => $q->prompt,
            ])->all();
        }

        return $base;
    }
}
