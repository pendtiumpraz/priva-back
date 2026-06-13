<?php

namespace App\Http\Controllers\Lms\Admin;

use App\Http\Controllers\Controller;
use App\Lms\Concerns\OrgScopedQuery;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin viewer for LMS users (Task 5.4-BE).
 *
 * READ-ONLY listing per spec §3.10 — account management lives in
 * Privasimu Nexus, so there is intentionally no create/update/delete here.
 *
 * Permission: lms.user_admin (separate from lms.content_admin per D2).
 *
 * Org scoping (single non-recursive query, no N+1):
 *   - Tenant admin: rows where users.org_id === auth user's org_id.
 *   - Root/superadmin: all rows; response also exposes org_id / org_name.
 *
 * Aggregations (subqueries on lms_*):
 *   - enrolled_courses : COUNT(DISTINCT course_id) via lms_user_module_progress
 *                        joined to lms_modules.
 *   - total_xp         : SUM(xp_amount) over lms_xp_log.
 *   - badges_count     : COUNT(DISTINCT badge_id) over lms_user_badges.
 *   - last_activity_at : MAX(created_at) over lms_xp_log.
 *
 * Filters:
 *   - ?search=<q>   — case-insensitive LIKE on name OR email.
 *                     (NB: users.name is encrypted-at-rest via EncryptedString
 *                     cast, so SQL LIKE on `name` will only match plaintext
 *                     fallback rows. This mirrors the existing
 *                     Api\UserController search behaviour and is acceptable
 *                     because email also matches.)
 *   - ?role=<role>  — exact match on users.role.
 *
 * Pagination: 20 per page, consistent with other admin endpoints.
 */
class UserAdminController extends Controller
{
    use OrgScopedQuery;

    public function index(Request $request): JsonResponse
    {
        $auth   = $request->user();
        $isRoot = $this->isRootUser($auth);

        $query = User::query();

        // Org scoping. Cannot reuse scopeForAdmin() because users have no
        // null-org (global) concept — every account belongs to exactly one org.
        if (! $isRoot) {
            $query->where('org_id', $auth->org_id);
        }

        // Search: name OR email, case-insensitive LIKE.
        if (($search = trim((string) $request->query('search', ''))) !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhere('email', 'like', $like);
            });
        }

        // Role filter: exact match.
        if (($role = trim((string) $request->query('role', ''))) !== '') {
            $query->where('role', $role);
        }

        // Aggregation subqueries — keep one query per row via selectSub.
        $query
            ->selectSub(
                DB::table('lms_user_module_progress as ump')
                    ->join('lms_modules as lm', 'lm.id', '=', 'ump.module_id')
                    ->whereColumn('ump.user_id', 'users.id')
                    ->selectRaw('count(distinct lm.course_id)'),
                'enrolled_courses'
            )
            ->selectSub(
                DB::table('lms_xp_log')
                    ->whereColumn('lms_xp_log.user_id', 'users.id')
                    ->selectRaw('coalesce(sum(xp_amount), 0)'),
                'total_xp'
            )
            ->selectSub(
                DB::table('lms_user_badges')
                    ->whereColumn('lms_user_badges.user_id', 'users.id')
                    ->selectRaw('count(distinct badge_id)'),
                'badges_count'
            )
            ->selectSub(
                DB::table('lms_xp_log')
                    ->whereColumn('lms_xp_log.user_id', 'users.id')
                    ->selectRaw('max(created_at)'),
                'last_activity_at'
            );

        // For root, hydrate org_name via subquery (no relation eager-load needed).
        if ($isRoot) {
            $query->selectSub(
                DB::table('organizations as o')
                    ->whereColumn('o.id', 'users.org_id')
                    ->selectRaw('name'),
                'org_name'
            );
        }

        // Always include the canonical user columns alongside the aggregations.
        $query->addSelect([
            'users.id',
            'users.name',
            'users.email',
            'users.role',
            'users.org_id',
        ]);

        $query->orderBy('users.email');

        $paginator = $query->paginate(20);

        return response()->json([
            'data' => $paginator->getCollection()->map(
                fn (User $u) => $this->toListResource($u, $isRoot),
            )->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
            ],
        ]);
    }

    /**
     * Normalised list-row resource. Root sees org_id + org_name; tenants do not.
     */
    protected function toListResource(User $u, bool $isRoot): array
    {
        $row = [
            'id'               => $u->id,
            'name'             => $u->name,           // decrypted by cast
            'email'            => $u->email,
            'role'             => $u->role,
            'enrolled_courses' => (int) ($u->enrolled_courses ?? 0),
            'total_xp'         => (int) ($u->total_xp ?? 0),
            'badges_count'     => (int) ($u->badges_count ?? 0),
            'last_activity_at' => $this->normaliseTimestamp($u->last_activity_at ?? null),
        ];

        if ($isRoot) {
            $row['org_id']   = $u->org_id;
            $row['org_name'] = $u->getAttribute('org_name');
        }

        return $row;
    }

    /**
     * SQLite returns timestamps as strings; MySQL/PG return Carbon-castable.
     * Coerce to ISO 8601 (or null) so the FE never has to disambiguate.
     */
    protected function normaliseTimestamp($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
