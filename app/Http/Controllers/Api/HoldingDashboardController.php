<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HoldingDashboardController extends Controller
{
    /**
     * Get org tree structure (holding → sub_holding → subsidiary)
     */
    public function orgTree(Request $request): JsonResponse
    {
        $user = $request->user();

        // Superadmin can see entire tree; holding-admin sees their branch
        if (in_array($user->role, ['root','superadmin'], true)) {
            $roots = Organization::whereNull('parent_id')
                ->orWhere('org_level', 'holding')
                ->with(['descendants' => fn($q) => $q->withCount('users')])
                ->withCount('users')
                ->get();
        } else {
            $org = Organization::find($user->org_id);
            if (!$org || !$org->isHolding()) {
                return response()->json(['message' => 'Anda bukan holding admin'], 403);
            }
            $roots = collect([$org->load(['descendants' => fn($q) => $q->withCount('users')])->loadCount('users')]);
        }

        return response()->json(['data' => $roots]);
    }

    /**
     * Aggregated holding dashboard stats — rollup dari semua anak perusahaan.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgIds = $this->resolveOrgIds($user);

        if (!$orgIds) {
            return response()->json(['message' => 'Unauthorized — bukan holding admin'], 403);
        }

        $stats = [
            'total_subsidiaries' => count($orgIds),
            'total_users'   => DB::table('users')->whereIn('org_id', $orgIds)->whereNull('deleted_at')->count(),
            'total_ropa'    => DB::table('ropas')->whereIn('org_id', $orgIds)->whereNull('deleted_at')->count(),
            'total_dpia'    => DB::table('dpias')->whereIn('org_id', $orgIds)->whereNull('deleted_at')->count(),
            'total_dsr'     => DB::table('dsr_requests')->whereIn('org_id', $orgIds)->whereNull('deleted_at')->count(),
            'dsr_pending'   => DB::table('dsr_requests')->whereIn('org_id', $orgIds)->whereNull('deleted_at')
                                ->whereIn('status', ['new', 'new_reply'])->count(),
            'dsr_overdue'   => DB::table('dsr_requests')->whereIn('org_id', $orgIds)->whereNull('deleted_at')
                                ->whereIn('status', ['new', 'new_reply'])->where('deadline_at', '<', now())->count(),
            'total_breaches'    => DB::table('breach_incidents')->whereIn('org_id', $orgIds)->where('is_simulation', false)->whereNull('deleted_at')->count(),
            'active_breaches'   => DB::table('breach_incidents')->whereIn('org_id', $orgIds)->where('is_simulation', false)->whereNotIn('status', ['closed'])->whereNull('deleted_at')->count(),
            'consent_points'    => DB::table('consent_collection_points')->whereIn('org_id', $orgIds)->whereNull('deleted_at')->count(),
            'data_sources'      => DB::table('information_systems')->whereIn('org_id', $orgIds)->whereNull('deleted_at')->count(),
        ];

        return response()->json(['stats' => $stats]);
    }

    /**
     * Compliance matrix — score per anak perusahaan per modul.
     */
    public function complianceMatrix(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgIds = $this->resolveOrgIds($user);

        if (!$orgIds) {
            return response()->json(['message' => 'Unauthorized — bukan holding admin'], 403);
        }

        $matrix = [];
        $orgs = Organization::whereIn('id', $orgIds)->get(['id', 'name', 'slug', 'org_level', 'industry', 'parent_id']);

        foreach ($orgs as $org) {
            $id = $org->id;

            // Latest GAP score
            $gap = DB::table('gap_assessments')
                ->where('org_id', $id)->whereNull('deleted_at')
                ->latest('created_at')->first();

            $matrix[] = [
                'id'            => $id,
                'name'          => $org->name,
                'slug'          => $org->slug,
                'org_level'     => $org->org_level,
                'industry'      => $org->industry,
                'parent_id'     => $org->parent_id,
                'gap_score'     => $gap->overall_score ?? 0,
                'compliance_level' => $gap->compliance_level ?? 'belum_dinilai',
                'ropa_count'    => DB::table('ropas')->where('org_id', $id)->whereNull('deleted_at')->count(),
                'dpia_count'    => DB::table('dpias')->where('org_id', $id)->whereNull('deleted_at')->count(),
                'dsr_count'     => DB::table('dsr_requests')->where('org_id', $id)->whereNull('deleted_at')->count(),
                'breach_count'  => DB::table('breach_incidents')->where('org_id', $id)->where('is_simulation', false)->whereNull('deleted_at')->count(),
                'user_count'    => DB::table('users')->where('org_id', $id)->whereNull('deleted_at')->count(),
                'data_sources'  => DB::table('information_systems')->where('org_id', $id)->whereNull('deleted_at')->count(),
            ];
        }

        // Sort by gap_score descending so worst performers are visible
        usort($matrix, fn($a, $b) => $a['gap_score'] <=> $b['gap_score']);

        return response()->json(['data' => $matrix]);
    }

    /**
     * Resolve org IDs for current user's holding scope.
     */
    private function resolveOrgIds($user): ?array
    {
        if (in_array($user->role, ['root','superadmin'], true)) {
            return Organization::pluck('id')->toArray();
        }

        $org = Organization::find($user->org_id);
        if (!$org || !$org->isHolding()) {
            return null;
        }

        $ids = $org->getDescendantIds();
        $ids[] = $org->id;
        return $ids;
    }

    /**
     * Per-sub-holding breakdown — each sub_holding with aggregated stats of its children.
     */
    public function subHoldingBreakdown(Request $request): JsonResponse
    {
        $user = $request->user();
        $subHoldingQuery = Organization::where('org_level', 'sub_holding');
        
        // Scope: superadmin sees all, holding admin sees their sub_holdings only
        if (! in_array($user->role, ['root','superadmin'], true)) {
            $org = Organization::find($user->org_id);
            if (!$org) return response()->json(['data' => []]);
            
            if ($org->org_level === 'sub_holding') {
                // Sub-holding admin: only see themselves
                $subHoldingQuery->where('id', $org->id);
            } else if ($org->org_level === 'holding') {
                // Holding admin: see sub_holdings under them
                $subHoldingQuery->where('parent_id', $org->id);
            } else {
                return response()->json(['data' => []]);
            }
        }
        
        $subHoldings = $subHoldingQuery->get();
        $breakdown = [];

        foreach ($subHoldings as $sh) {
            $childIds = $sh->getDescendantIds();
            $allIds = array_merge([$sh->id], $childIds);

            $gap = DB::table('gap_assessments')
                ->where('org_id', $sh->id)->whereNull('deleted_at')
                ->latest('created_at')->first();

            $breakdown[] = [
                'id'          => $sh->id,
                'name'        => $sh->name,
                'slug'        => $sh->slug,
                'industry'    => $sh->industry,
                'parent_id'   => $sh->parent_id,
                'gap_score'   => $gap->overall_score ?? 0,
                'subsidiaries'=> count($childIds),
                'total_users' => DB::table('users')->whereIn('org_id', $allIds)->whereNull('deleted_at')->count(),
                'total_ropa'  => DB::table('ropas')->whereIn('org_id', $allIds)->whereNull('deleted_at')->count(),
                'total_dpia'  => DB::table('dpias')->whereIn('org_id', $allIds)->whereNull('deleted_at')->count(),
                'total_dsr'   => DB::table('dsr_requests')->whereIn('org_id', $allIds)->whereNull('deleted_at')->count(),
                'total_breaches' => DB::table('breach_incidents')->whereIn('org_id', $allIds)->where('is_simulation', false)->whereNull('deleted_at')->count(),
                'active_breaches' => DB::table('breach_incidents')->whereIn('org_id', $allIds)->where('is_simulation', false)->whereNotIn('status', ['closed'])->whereNull('deleted_at')->count(),
                'data_sources' => DB::table('information_systems')->whereIn('org_id', $allIds)->whereNull('deleted_at')->count(),
                'children' => Organization::where('parent_id', $sh->id)->get(['id', 'name', 'slug', 'industry'])->toArray(),
            ];
        }

        return response()->json(['data' => $breakdown]);
    }
}
