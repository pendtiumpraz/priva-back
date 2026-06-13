<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\Admin\StoreCourseRequest;
use App\Http\Requests\Lms\Admin\UpdateCourseRequest;
use App\Lms\Concerns\OrgScopedQuery;
use App\Lms\Models\Course;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CourseAdminController extends Controller
{
    use OrgScopedQuery;

    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeForAdmin(Course::query());

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            // Map external "status" -> internal "published" boolean.
            if ($status === 'published') {
                $query->where('published', true);
            } elseif ($status === 'draft') {
                $query->where('published', false);
            }
        }

        $query->withCount('modules')
              ->selectSub(
                  DB::table('lms_user_module_progress as ump')
                      ->join('lms_modules as lm', 'lm.id', '=', 'ump.module_id')
                      ->whereColumn('lm.course_id', 'lms_courses.id')
                      ->selectRaw('count(distinct ump.user_id)'),
                  'enrolled_count'
              )
              ->orderBy('order')
              ->orderBy('id');

        $paginator = $query->paginate(20);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Course $c) => $this->toListResource($c))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $user = $request->user();
        $isRoot = $this->isRootUser($user);
        $data = $request->validated();

        // Resolve org_id: root may pass any (or null); tenant admins forced to own org.
        if ($isRoot) {
            $orgId = array_key_exists('org_id', $data)
                ? $data['org_id']
                : ($user->org_id ?? null);
        } else {
            $orgId = $user->org_id ?? null;
        }

        $slug = $data['slug'] ?? Str::slug($data['title']);

        $course = Course::create([
            'org_id'        => $orgId,
            'slug'          => $slug,
            'title'         => $data['title'],
            'description'   => $data['description'] ?? null,
            'thumbnail_url' => $data['thumbnail_url'] ?? null,
            'published'     => ($data['status'] ?? 'draft') === 'published',
            'created_by'    => $user->id ?? null,
        ]);

        return response()->json([
            'data' => $this->toShowResource($course->fresh()),
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $course = $this->scopeForAdmin(Course::query())
            ->with(['modules' => function ($q) {
                $q->withCount('lessons')->orderBy('order')->orderBy('id');
            }])
            ->find($id);

        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        return response()->json([
            'data' => $this->toShowResource($course),
        ]);
    }

    public function update(UpdateCourseRequest $request, $id): JsonResponse
    {
        $course = $this->scopeForAdmin(Course::query())->find($id);
        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $this->assertMutable($course);

        $data = $request->validated();

        if (array_key_exists('title', $data)) {
            $course->title = $data['title'];
        }
        if (array_key_exists('description', $data)) {
            $course->description = $data['description'];
        }
        if (array_key_exists('thumbnail_url', $data)) {
            $course->thumbnail_url = $data['thumbnail_url'];
        }
        if (array_key_exists('status', $data)) {
            $course->published = $data['status'] === 'published';
        }
        if (array_key_exists('slug', $data)) {
            // Manual slug uniqueness check against (org_id, slug), excluding
            // self. Mirrors the UpdateModuleRequest / UpdateLessonRequest
            // pattern; keeps UpdateCourseRequest free of DB queries (BE-2).
            // whereNull('deleted_at') matches the partial-unique index that
            // skips soft-deleted rows.
            $orgIdForCheck = $course->org_id;
            if ($this->isRootUser($request->user()) && array_key_exists('org_id', $data)) {
                $orgIdForCheck = $data['org_id'];
            }
            $exists = Course::query()
                ->where('slug', $data['slug'])
                ->where('id', '!=', $course->id)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($orgIdForCheck) {
                    if ($orgIdForCheck === null) {
                        $q->whereNull('org_id');
                    } else {
                        $q->where('org_id', $orgIdForCheck);
                    }
                })
                ->exists();
            if ($exists) {
                throw ValidationException::withMessages([
                    'slug' => ['The slug has already been taken.'],
                ]);
            }
            $course->slug = $data['slug'];
        }
        // Only root may reassign org_id
        if ($this->isRootUser($request->user()) && array_key_exists('org_id', $data)) {
            $course->org_id = $data['org_id'];
        }

        $course->save();

        return response()->json([
            'data' => $this->toShowResource($course->fresh()),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $course = $this->scopeForAdmin(Course::query())->find($id);
        if (! $course) {
            return response()->json(['message' => 'Course not found.'], 404);
        }

        $this->assertMutable($course);

        $course->delete(); // SoftDeletes trait handles this transparently

        return response()->noContent(); // 204
    }

    /**
     * Resource shape for list endpoint.
     */
    protected function toListResource(Course $course): array
    {
        return [
            'id'             => $course->id,
            'slug'           => $course->slug,
            'title'          => $course->title,
            'description'    => $course->description,
            'thumbnail_url'  => $course->thumbnail_url,
            'status'         => $course->published ? 'published' : 'draft',
            'org_id'         => $course->org_id,
            'modules_count'  => (int) ($course->modules_count ?? 0),
            'enrolled_count' => (int) ($course->enrolled_count ?? 0),
            'created_at'     => $course->created_at?->toIso8601String(),
        ];
    }

    /**
     * Resource shape for show/store/update endpoints.
     */
    protected function toShowResource(Course $course): array
    {
        $base = [
            'id'            => $course->id,
            'slug'          => $course->slug,
            'title'         => $course->title,
            'description'   => $course->description,
            'thumbnail_url' => $course->thumbnail_url,
            'status'        => $course->published ? 'published' : 'draft',
            'org_id'        => $course->org_id,
            'created_at'    => $course->created_at?->toIso8601String(),
            'updated_at'    => $course->updated_at?->toIso8601String(),
        ];

        if ($course->relationLoaded('modules')) {
            $base['modules'] = $course->modules->map(fn ($m) => [
                'id'            => $m->id,
                'slug'          => $m->slug,
                'title'         => $m->title,
                'sort_order'    => (int) ($m->order ?? 0),
                'lessons_count' => (int) ($m->lessons_count ?? 0),
            ])->all();
        }

        return $base;
    }
}
