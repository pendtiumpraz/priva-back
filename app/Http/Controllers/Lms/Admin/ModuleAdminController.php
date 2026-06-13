<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\Admin\StoreModuleRequest;
use App\Http\Requests\Lms\Admin\UpdateModuleRequest;
use App\Lms\Concerns\OrgScopedQuery;
use App\Lms\Models\Course;
use App\Lms\Models\Module;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for LMS Modules.
 *
 * Schema notes (vs spec):
 *  - Module table has no SoftDeletes -> destroy is a hard delete.
 *  - DB column is `order`; API contract uses `sort_order`. Mapped at the
 *    resource boundary in both directions.
 *  - Modules don't have an independent org_id; permission is gated through
 *    the parent Course via assertMutable($module->course).
 */
class ModuleAdminController extends Controller
{
    use OrgScopedQuery;

    /**
     * GET /admin/courses/{course}/modules
     */
    public function index(Request $request, $courseId): JsonResponse
    {
        $course = $this->scopeForAdmin(Course::query())->find($courseId);
        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $modules = $course->modules()
            ->withCount('lessons')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $modules->map(fn (Module $m) => $this->toListResource($m, $course))->all(),
        ]);
    }

    /**
     * POST /admin/courses/{course}/modules
     */
    public function store(StoreModuleRequest $request, $courseId): JsonResponse
    {
        $course = $this->scopeForAdmin(Course::query())->find($courseId);
        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $this->assertMutable($course);

        $data = $request->validated();

        $slug = $data['slug'] ?? Str::slug($data['title']);

        // Defensive: even if validator allowed it (e.g. via auto-slug clash),
        // re-check uniqueness within course since auto-slug bypasses the rule.
        if (Module::where('course_id', $course->id)->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['The slug has already been taken in this course.'],
            ]);
        }

        $sortOrder = $data['sort_order']
            ?? ((int) Module::where('course_id', $course->id)->max('order') + 1);

        $published = array_key_exists('status', $data)
            ? ($data['status'] === 'published')
            : true;

        $module = Module::create([
            'course_id'   => $course->id,
            'slug'        => $slug,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'order'       => $sortOrder,
            'published'   => $published,
        ]);

        return response()->json([
            'data' => $this->toShowResource($module->fresh()->load('lessons'), $course),
        ], 201);
    }

    /**
     * GET /admin/modules/{module}
     */
    public function show(Request $request, $id): JsonResponse
    {
        $module = Module::query()
            ->whereHas('course', fn ($q) => $this->scopeForAdmin($q))
            ->with(['lessons' => function ($q) {
                $q->orderBy('order')->orderBy('id');
            }, 'course'])
            ->find($id);

        if (! $module) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        return response()->json([
            'data' => $this->toShowResource($module, $module->course),
        ]);
    }

    /**
     * PUT /admin/modules/{module}
     */
    public function update(UpdateModuleRequest $request, $id): JsonResponse
    {
        $module = Module::query()
            ->whereHas('course', fn ($q) => $this->scopeForAdmin($q))
            ->with('course')
            ->find($id);

        if (! $module) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        $this->assertMutable($module->course);

        $data = $request->validated();

        if (array_key_exists('title', $data)) {
            $module->title = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $module->description = $data['description'];
        }
        if (array_key_exists('slug', $data)) {
            // Manual slug uniqueness check against (course_id, slug), excluding
            // self. Mirrors the store() pattern; keeps UpdateModuleRequest free
            // of an extra Module::find() that would duplicate the load above.
            $exists = Module::where('course_id', $module->course_id)
                ->where('slug', $data['slug'])
                ->where('id', '!=', $module->id)
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'slug' => ['The slug has already been taken in this course.'],
                ]);
            }
            $module->slug = $data['slug'];
        }
        if (array_key_exists('sort_order', $data)) {
            $module->order = $data['sort_order'];
        }
        if (array_key_exists('status', $data)) {
            $module->published = ($data['status'] === 'published');
        }

        $module->save();

        return response()->json([
            'data' => $this->toShowResource($module->fresh()->load('lessons'), $module->course),
        ]);
    }

    /**
     * DELETE /admin/modules/{module}
     */
    public function destroy(Request $request, $id)
    {
        $module = Module::query()
            ->whereHas('course', fn ($q) => $this->scopeForAdmin($q))
            ->with('course')
            ->find($id);

        if (! $module) {
            return response()->json(['message' => 'Module not found.'], 404);
        }

        $this->assertMutable($module->course);

        // Module model has no SoftDeletes -> hard delete.
        $module->delete();

        return response()->noContent(); // 204
    }

    /**
     * POST /admin/courses/{course}/modules/reorder
     *
     * Body: { "order": [10, 12, 11] } — module IDs in desired order.
     * Returns 200 with updated module list (same shape as index).
     */
    public function reorder(Request $request, $courseId): JsonResponse
    {
        $course = $this->scopeForAdmin(Course::query())->find($courseId);
        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $this->assertMutable($course);

        $validated = $request->validate([
            'order'   => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'integer', 'exists:lms_modules,id'],
        ]);

        $ids = $validated['order'];

        // Ensure every supplied id belongs to this course; reject otherwise.
        $belonging = Module::where('course_id', $course->id)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($belonging) !== count($ids) || count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages([
                'order' => ['All module ids must belong to this course and be unique.'],
            ]);
        }

        // Require exhaustive set: input must contain exactly every module under
        // this course. Partial reorders leave unmentioned modules holding old
        // `order` values, producing duplicate/gap orderings.
        $courseModuleIds = Module::where('course_id', $course->id)->pluck('id')->all();
        $missing = array_diff($courseModuleIds, $ids);
        if (count($courseModuleIds) !== count($ids) || ! empty($missing)) {
            throw ValidationException::withMessages([
                'order' => ['order must include all modules in this course.'],
            ]);
        }

        DB::transaction(function () use ($ids) {
            foreach ($ids as $index => $moduleId) {
                // 1-based to match seeded data convention
                Module::where('id', $moduleId)->update(['order' => $index + 1]);
            }
        });

        $modules = $course->modules()
            ->withCount('lessons')
            ->orderBy('order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $modules->map(fn (Module $m) => $this->toListResource($m, $course))->all(),
        ]);
    }

    /**
     * Index/list resource shape (per spec §3.4).
     */
    protected function toListResource(Module $module, ?Course $course = null): array
    {
        $course = $course ?: $module->course;

        return [
            'id'            => $module->id,
            'course_id'     => $module->course_id,
            'slug'          => $module->slug,
            'title'         => $module->title,
            'sort_order'    => (int) ($module->order ?? 0),
            'status'        => $module->published ? 'published' : 'draft',
            'lessons_count' => (int) ($module->lessons_count ?? 0),
        ];
    }

    /**
     * Show/store/update resource shape — adds description, lessons, timestamps.
     */
    protected function toShowResource(Module $module, ?Course $course = null): array
    {
        $course = $course ?: $module->course;

        $base = [
            'id'          => $module->id,
            'course_id'   => $module->course_id,
            'slug'        => $module->slug,
            'title'       => $module->title,
            'description' => $module->description,
            'sort_order'  => (int) ($module->order ?? 0),
            'status'      => $module->published ? 'published' : 'draft',
            'created_at'  => $module->created_at?->toIso8601String(),
            'updated_at'  => $module->updated_at?->toIso8601String(),
        ];

        if ($module->relationLoaded('lessons')) {
            $base['lessons'] = $module->lessons->map(fn ($l) => [
                'id'                => $l->id,
                'slug'               => $l->slug,
                'title'              => $l->title,
                'sort_order'         => (int) ($l->order ?? 0),
                'estimated_minutes'  => (int) round((int) ($l->duration_seconds ?? 0) / 60),
                'status'             => $l->published ? 'published' : 'draft',
            ])->all();
        }

        return $base;
    }
}
