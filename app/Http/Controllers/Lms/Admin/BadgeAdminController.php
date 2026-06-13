<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\Admin\StoreBadgeRequest;
use App\Http\Requests\Lms\Admin\UpdateBadgeRequest;
use App\Lms\Concerns\OrgScopedQuery;
use App\Lms\Models\Badge;
use App\Lms\Models\UserBadge;
use App\Lms\Rules\BadgeCriteriaJsonRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for LMS Badges (Task 5.3-BE).
 *
 * Schema notes (vs spec §3.9) — see migration 2026_05_08_230000_extend_lms_badges_for_admin:
 *  - Original lms_badges table had: slug (globally unique), name, description (nullable),
 *    icon (nullable), criteria_type (ENUM completion/quiz_score/xp_total/custom),
 *    criteria_json (jsonb nullable). No org_id, no SoftDeletes.
 *  - Admin migration adds: nullable uuid `org_id` FK, `deleted_at` softDeletes,
 *    relaxed `criteria_type` to VARCHAR (validation enforces allowed values),
 *    replaced global slug-unique with partial unique on (org_id, slug).
 *  - Spec §3.9 criteria types: lesson_complete, quiz_pass, quiz_perfect,
 *    course_complete, streak, xp_threshold, custom. Legacy seeded types
 *    (completion, quiz_score, xp_total) remain accepted so seeded rows can
 *    still be edited; structural validation is a no-op for those.
 *
 * Auth model decisions:
 *  - Index/show use `scopeForAdmin`: tenant admin sees own-org rows + null-org
 *    seeded badges; root sees all.
 *  - Create: tenant admin's `org_id` defaults to their own; root may pass any.
 *  - Update: per spec §3.9 ("Seeded badges can be edited but not deleted"),
 *    tenant admins MAY mutate null-org (seeded) badges. This deliberately
 *    diverges from D5's blanket "null-org content read-only" rule —
 *    OrgScopedQuery::assertMutable is *skipped* on edit for null-org badges.
 *  - Destroy: only root may delete null-org (seeded) badges. Tenant admins
 *    may delete only their own org's badges.
 */
class BadgeAdminController extends Controller
{
    use OrgScopedQuery;

    public function index(Request $request): JsonResponse
    {
        $query = $this->scopeForAdmin(Badge::query());

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $query->withCount('userBadges')
              ->orderBy('id');

        $paginator = $query->paginate(20);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (Badge $b) => $this->toListResource($b))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreBadgeRequest $request): JsonResponse
    {
        $user   = $request->user();
        $isRoot = $this->isRootUser($user);
        $data   = $request->validated();

        // Resolve org_id — root may pass any (or null); tenant admins forced.
        if ($isRoot) {
            $orgId = array_key_exists('org_id', $data) ? $data['org_id'] : ($user->org_id ?? null);
        } else {
            $orgId = $user->org_id ?? null;
        }

        // Slug uniqueness within (org_id, slug), ignoring soft-deleted rows.
        $this->assertSlugUniqueForOrg($data['slug'], $orgId, null);

        $badge = Badge::create([
            'org_id'        => $orgId,
            'slug'          => $data['slug'],
            'name'          => $data['name'],
            'description'   => $data['description'],
            'icon'          => $data['icon'],
            'criteria_type' => $data['criteria_type'],
            'criteria_json' => $data['criteria_json'],
        ]);

        return response()->json([
            'data' => $this->toShowResource($badge->fresh()),
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $badge = $this->scopeForAdmin(Badge::query())->find($id);
        if (! $badge) {
            return response()->json(['message' => 'Badge not found.'], 404);
        }

        // Hydrate awarded_count for show response too.
        $badge->loadCount('userBadges');

        return response()->json([
            'data' => $this->toShowResource($badge),
        ]);
    }

    /**
     * GET /admin/badges/{id}/awards
     *
     * Recipients of this badge. Org-scoped explicitly: LMS routes don't run
     * tenant.context, so BelongsToOrg auto-scoping is a no-op here — a tenant
     * admin must only see their own org's recipients; root/superadmin sees all.
     */
    public function awards(Request $request, $id): JsonResponse
    {
        $badge = $this->scopeForAdmin(Badge::query())->find($id);
        if (! $badge) {
            return response()->json(['message' => 'Badge not found.'], 404);
        }

        $user = auth()->user();
        $query = UserBadge::query()
            ->with('user:id,name,email')
            ->where('badge_id', $badge->id);

        if (! $this->isRootUser($user)) {
            $query->where('org_id', $user->org_id);
        }

        $awards = $query->orderByDesc('awarded_at')->limit(500)->get();

        return response()->json([
            'data' => $awards->map(fn (UserBadge $ub) => [
                'id'         => $ub->id,
                'user_id'    => $ub->user_id,
                'user_name'  => $ub->user?->name,
                'user_email' => $ub->user?->email,
                'awarded_at' => $ub->awarded_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function update(UpdateBadgeRequest $request, $id): JsonResponse
    {
        $badge = $this->scopeForAdmin(Badge::query())->find($id);
        if (! $badge) {
            return response()->json(['message' => 'Badge not found.'], 404);
        }

        // Per spec §3.9: tenant admins may EDIT null-org (seeded) badges.
        // We intentionally skip assertMutable for null-org rows (which would
        // otherwise 403). Cross-org rows are still blocked.
        $user = $request->user();
        if ($badge->org_id !== null && ! $this->isRootUser($user) && $badge->org_id !== $user->org_id) {
            abort(403, 'Cannot modify content owned by another organization.');
        }

        $data = $request->validated();

        // Validate criteria_json structure once we know the effective type.
        if (array_key_exists('criteria_json', $data) || array_key_exists('criteria_type', $data)) {
            $effectiveType = $data['criteria_type'] ?? $badge->criteria_type;
            $effectiveJson = array_key_exists('criteria_json', $data)
                ? $data['criteria_json']
                : $badge->criteria_json;

            $structureValidator = Validator::make(
                ['criteria_json' => $effectiveJson],
                ['criteria_json' => ['required', 'array', new BadgeCriteriaJsonRule((string) $effectiveType)]],
            );
            if ($structureValidator->fails()) {
                throw new ValidationException($structureValidator);
            }
        }

        if (array_key_exists('name', $data)) {
            $badge->name = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $badge->description = $data['description'];
        }
        if (array_key_exists('icon', $data)) {
            $badge->icon = $data['icon'];
        }
        if (array_key_exists('criteria_type', $data)) {
            $badge->criteria_type = $data['criteria_type'];
        }
        if (array_key_exists('criteria_json', $data)) {
            $badge->criteria_json = $data['criteria_json'];
        }

        if (array_key_exists('slug', $data)) {
            $orgIdForCheck = $badge->org_id;
            if ($this->isRootUser($user) && array_key_exists('org_id', $data)) {
                $orgIdForCheck = $data['org_id'];
            }
            $this->assertSlugUniqueForOrg($data['slug'], $orgIdForCheck, $badge->id);
            $badge->slug = $data['slug'];
        }

        if ($this->isRootUser($user) && array_key_exists('org_id', $data)) {
            $badge->org_id = $data['org_id'];
        }

        $badge->save();

        return response()->json([
            'data' => $this->toShowResource($badge->fresh()->loadCount('userBadges')),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $badge = $this->scopeForAdmin(Badge::query())->find($id);
        if (! $badge) {
            return response()->json(['message' => 'Badge not found.'], 404);
        }

        $user = $request->user();
        $isRoot = $this->isRootUser($user);

        // Spec §3.9: seeded badges (org_id IS NULL) cannot be deleted by
        // tenant admins. Only root may delete them.
        if ($badge->org_id === null && ! $isRoot) {
            abort(403, 'Seeded badges are read-only for tenant admins.');
        }

        // Cross-org guard: tenant admins cannot delete another org's badge.
        if (! $isRoot && $badge->org_id !== $user->org_id) {
            abort(403, 'Cannot modify content owned by another organization.');
        }

        $badge->delete(); // soft delete

        return response()->noContent();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Throw 422 if (slug, org_id) collides with another non-soft-deleted badge.
     */
    protected function assertSlugUniqueForOrg(string $slug, $orgId, $exceptId): void
    {
        $exists = Badge::query()
            ->where('slug', $slug)
            ->whereNull('deleted_at')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(function ($q) use ($orgId) {
                if ($orgId === null) {
                    $q->whereNull('org_id');
                } else {
                    $q->where('org_id', $orgId);
                }
            })
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'slug' => ['The slug has already been taken.'],
            ]);
        }
    }

    protected function toListResource(Badge $badge): array
    {
        return [
            'id'             => $badge->id,
            'slug'           => $badge->slug,
            'name'           => $badge->name,
            'description'    => $badge->description,
            'icon'           => $badge->icon,
            'criteria_type'  => $badge->criteria_type,
            'criteria_json'  => $badge->criteria_json,
            'is_seeded'      => $badge->org_id === null,
            'awarded_count'  => (int) ($badge->user_badges_count ?? 0),
            'org_id'         => $badge->org_id,
        ];
    }

    protected function toShowResource(Badge $badge): array
    {
        return [
            'id'             => $badge->id,
            'slug'           => $badge->slug,
            'name'           => $badge->name,
            'description'    => $badge->description,
            'icon'           => $badge->icon,
            'criteria_type'  => $badge->criteria_type,
            'criteria_json'  => $badge->criteria_json,
            'is_seeded'      => $badge->org_id === null,
            'awarded_count'  => (int) ($badge->user_badges_count ?? 0),
            'org_id'         => $badge->org_id,
            'created_at'     => $badge->created_at?->toIso8601String(),
            'updated_at'     => $badge->updated_at?->toIso8601String(),
        ];
    }
}
