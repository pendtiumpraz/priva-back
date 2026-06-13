<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\Admin\StoreLessonRequest;
use App\Http\Requests\Lms\Admin\UpdateLessonRequest;
use App\Lms\Concerns\OrgScopedQuery;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for LMS Lessons.
 *
 * Schema notes (vs spec):
 *  - Lesson table has no SoftDeletes -> destroy is a hard delete.
 *  - DB column is `order`; API contract uses `sort_order` (mapped at boundary).
 *  - DB column is `duration_seconds`; API contract uses `estimated_minutes`
 *    (mapped at boundary: minutes * 60 on write, round(seconds/60) on read).
 *  - Lesson has no direct org_id; org-scope enforced via the two-level
 *    relation `lesson -> module -> course -> org_id` (see scopeForAdmin).
 */
class LessonAdminController extends Controller
{
    use OrgScopedQuery;

    /**
     * GET /admin/modules/{module}/lessons
     */
    public function index(Request $request, $moduleId): JsonResponse
    {
        $module = Module::query()
            ->whereHas('course', fn ($q) => $this->scopeForAdmin($q))
            ->find($moduleId);

        if (! $module) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        $lessons = Lesson::where('module_id', $module->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $lessons->map(fn (Lesson $l) => $this->toListResource($l))->all(),
        ]);
    }

    /**
     * POST /admin/modules/{module}/lessons
     */
    public function store(StoreLessonRequest $request, $moduleId): JsonResponse
    {
        $module = Module::query()
            ->whereHas('course', fn ($q) => $this->scopeForAdmin($q))
            ->with('course')
            ->find($moduleId);

        if (! $module) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        $this->assertMutable($module->course);

        $data = $request->validated();

        $slug = $data['slug'] ?? Str::slug($data['title']);

        // Manual uniqueness check scoped to (module_id, slug). Mirrors BE-2
        // pattern: rules() stays DB-free; DB UNIQUE index is the hard guard.
        if (Lesson::where('module_id', $module->id)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['The slug has already been taken in this module.'],
            ]);
        }

        $sortOrder = $data['sort_order']
            ?? ((int) Lesson::where('module_id', $module->id)->max('order') + 1);

        $published = ($data['status'] ?? 'draft') === 'published';

        $payload = [
            'module_id' => $module->id,
            'slug'      => $slug,
            'title'     => $data['title'],
            'body'      => $data['body'] ?? null,
            'order'     => $sortOrder,
            'video_id'  => $data['video_id'] ?? null,
            'published' => $published,
        ];

        if (array_key_exists('estimated_minutes', $data) && $data['estimated_minutes'] !== null) {
            $payload['duration_seconds'] = ((int) $data['estimated_minutes']) * 60;
        }

        $lesson = Lesson::create($payload);

        return response()->json([
            'data' => $this->toShowResource($lesson->fresh()->load('video')),
        ], 201);
    }

    /**
     * GET /admin/lessons/{lesson}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $lesson = Lesson::query()
            ->whereHas('module.course', fn ($q) => $this->scopeForAdmin($q))
            ->with('video')
            ->find($id);

        if (! $lesson) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        return response()->json([
            'data' => $this->toShowResource($lesson),
        ]);
    }

    /**
     * PUT /admin/lessons/{lesson}
     */
    public function update(UpdateLessonRequest $request, $id): JsonResponse
    {
        $lesson = Lesson::query()
            ->whereHas('module.course', fn ($q) => $this->scopeForAdmin($q))
            ->with(['module.course', 'video'])
            ->find($id);

        if (! $lesson) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        $this->assertMutable($lesson->module->course);

        $data = $request->validated();

        if (array_key_exists('title', $data)) {
            $lesson->title = $data['title'];
        }
        if (array_key_exists('body', $data)) {
            $lesson->body = $data['body'];
        }
        if (array_key_exists('sort_order', $data)) {
            $lesson->order = $data['sort_order'];
        }
        if (array_key_exists('status', $data)) {
            $lesson->published = ($data['status'] === 'published');
        }
        if (array_key_exists('video_id', $data)) {
            $lesson->video_id = $data['video_id'];
        }
        if (array_key_exists('estimated_minutes', $data)) {
            $lesson->duration_seconds = $data['estimated_minutes'] !== null
                ? ((int) $data['estimated_minutes']) * 60
                : null;
        }
        if (array_key_exists('slug', $data)) {
            // Manual slug uniqueness check against (module_id, slug), excluding
            // self. Mirrors BE-2 pattern.
            $exists = Lesson::where('module_id', $lesson->module_id)
                ->where('slug', $data['slug'])
                ->where('id', '!=', $lesson->id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'slug' => ['The slug has already been taken in this module.'],
                ]);
            }
            $lesson->slug = $data['slug'];
        }

        $lesson->save();

        return response()->json([
            'data' => $this->toShowResource($lesson->fresh()->load('video')),
        ]);
    }

    /**
     * DELETE /admin/lessons/{lesson}
     */
    public function destroy(Request $request, $id)
    {
        $lesson = Lesson::query()
            ->whereHas('module.course', fn ($q) => $this->scopeForAdmin($q))
            ->with('module.course')
            ->find($id);

        if (! $lesson) {
            return response()->json(['message' => 'Lesson not found.'], 404);
        }

        $this->assertMutable($lesson->module->course);

        // Lesson model has no SoftDeletes -> hard delete.
        $lesson->delete();

        return response()->noContent(); // 204
    }

    /**
     * Index/list resource shape (per spec §3.5).
     */
    protected function toListResource(Lesson $lesson): array
    {
        return [
            'id'                => $lesson->id,
            'module_id'         => $lesson->module_id,
            'slug'              => $lesson->slug,
            'title'             => $lesson->title,
            'sort_order'        => (int) ($lesson->order ?? 0),
            'status'            => $lesson->published ? 'published' : 'draft',
            'estimated_minutes' => (int) round((int) ($lesson->duration_seconds ?? 0) / 60),
        ];
    }

    /**
     * Show/store/update resource shape — adds body, video, timestamps.
     */
    protected function toShowResource(Lesson $lesson): array
    {
        $base = [
            'id'                => $lesson->id,
            'module_id'         => $lesson->module_id,
            'slug'              => $lesson->slug,
            'title'             => $lesson->title,
            'body'              => $lesson->body,
            'sort_order'        => (int) ($lesson->order ?? 0),
            'status'            => $lesson->published ? 'published' : 'draft',
            'estimated_minutes' => (int) round((int) ($lesson->duration_seconds ?? 0) / 60),
            'video'             => $this->toVideoResource($lesson->video),
            'created_at'        => $lesson->created_at?->toIso8601String(),
            'updated_at'        => $lesson->updated_at?->toIso8601String(),
        ];

        return $base;
    }

    protected function toVideoResource(?Video $video): ?array
    {
        if (! $video) {
            return null;
        }

        return [
            'id'               => $video->id,
            'source'           => $video->source,
            'external_id'      => $video->external_id,
            'duration_seconds' => $video->duration_seconds,
        ];
    }
}
