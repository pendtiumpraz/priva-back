<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PostureFinding;
use App\Services\PostureFindingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostureFindingController extends Controller
{
    public function __construct(protected PostureFindingService $service) {}

    /**
     * List findings with filters.
     * GET /security/findings?status=open&severity=critical&pillar=sensitive_protection
     */
    public function index(Request $request)
    {
        $orgId = $request->user()->org_id;

        $query = PostureFinding::query()
            ->where('org_id', $orgId)
            ->with(['assignee:id,name,email', 'resolver:id,name']);

        if ($s = $request->get('status')) $query->where('status', $s);
        if ($sev = $request->get('severity')) $query->where('severity', $sev);
        if ($p = $request->get('pillar')) $query->where('source_pillar', $p);
        if ($a = $request->get('assigned_to')) $query->where('assigned_to', $a);
        if ($request->boolean('overdue_only')) {
            $query->whereIn('status', [PostureFinding::STATUS_OPEN, PostureFinding::STATUS_IN_PROGRESS])
                ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now());
        }
        if ($q = $request->get('q')) {
            $query->where(function ($w) use ($q) {
                $w->where('title', 'like', "%{$q}%")
                    ->orWhere('source_detail', 'like', "%{$q}%");
            });
        }

        $sortKey = $request->get('sort', 'severity_then_sla');
        match ($sortKey) {
            'newest' => $query->orderByDesc('first_seen_at'),
            'oldest' => $query->orderBy('first_seen_at'),
            'sla_due' => $query->orderBy('sla_due_at'),
            default => $query
                ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
                ->orderBy('sla_due_at'),
        };

        $records = $query->paginate($request->integer('per_page', 25));

        return response()->json([
            'data' => $records,
            'severity_labels' => [
                'critical' => 'Critical', 'high' => 'High',
                'medium' => 'Medium', 'low' => 'Low',
            ],
            'status_labels' => PostureFinding::STATUS_LABELS,
        ]);
    }

    /**
     * Stats for the findings tab header — count per status / severity.
     */
    public function stats(Request $request)
    {
        $orgId = $request->user()->org_id;

        $base = fn () => PostureFinding::query()->where('org_id', $orgId);

        $byStatus = $base()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $bySeverity = $base()->whereIn('status', ['open', 'in_progress'])
            ->selectRaw('severity, count(*) as c')->groupBy('severity')->pluck('c', 'severity');
        $overdue = $base()->whereIn('status', ['open', 'in_progress'])
            ->whereNotNull('sla_due_at')->where('sla_due_at', '<', now())->count();
        $byPillar = $base()->whereIn('status', ['open', 'in_progress'])
            ->selectRaw('source_pillar, count(*) as c')->groupBy('source_pillar')->pluck('c', 'source_pillar');

        return response()->json([
            'by_status' => $byStatus,
            'by_severity_open' => $bySeverity,
            'by_pillar_open' => $byPillar,
            'overdue_count' => $overdue,
        ]);
    }

    public function show(Request $request, string $id)
    {
        $f = PostureFinding::query()
            ->where('org_id', $request->user()->org_id)
            ->with(['assignee:id,name,email', 'resolver:id,name'])
            ->findOrFail($id);
        return response()->json(['data' => $f]);
    }

    /**
     * Force a re-materialization. Useful for the FE "Refresh Findings"
     * button + after big upstream changes.
     */
    public function rematerialize(Request $request)
    {
        $orgId = $request->user()->org_id;
        $result = $this->service->materialize($orgId);

        AuditLog::log('posture', $orgId, 'findings_rematerialized', $result, 'manual');

        return response()->json([
            'message' => "Materialization selesai: {$result['created']} baru, {$result['bumped']} diperbarui, {$result['auto_resolved']} auto-resolved.",
            'data' => $result,
        ]);
    }

    public function assign(Request $request, string $id)
    {
        $data = $request->validate(['assigned_to' => 'nullable|uuid|exists:users,id']);
        $f = PostureFinding::query()->where('org_id', $request->user()->org_id)->findOrFail($id);
        $f->assigned_to = $data['assigned_to'] ?? null;
        $f->save();

        AuditLog::log('posture_finding', $f->id, 'assigned', ['assigned_to' => $f->assigned_to], 'manual');
        return response()->json(['message' => 'Owner di-update.', 'data' => $f->fresh(['assignee:id,name,email'])]);
    }

    /**
     * Status workflow transitions.
     * POST /security/findings/{id}/status
     *   { status: 'in_progress' | 'resolved' | 'accepted_risk' | 'dismissed', notes? }
     */
    public function changeStatus(Request $request, string $id)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(PostureFinding::ALL_STATUSES)],
            'notes' => 'nullable|string|max:2000',
        ]);

        $f = PostureFinding::query()->where('org_id', $request->user()->org_id)->findOrFail($id);

        $old = $f->status;
        $f->status = $data['status'];

        if (in_array($data['status'], [
            PostureFinding::STATUS_RESOLVED,
            PostureFinding::STATUS_ACCEPTED_RISK,
            PostureFinding::STATUS_DISMISSED,
        ], true)) {
            $f->resolved_at = now();
            $f->resolved_by = $request->user()->id;
        }
        if (!empty($data['notes'])) {
            $f->resolution_notes = $data['notes'];
        }
        $f->save();

        AuditLog::log('posture_finding', $f->id, 'status_changed', [
            'from' => $old, 'to' => $data['status'], 'notes' => $data['notes'] ?? null,
        ], 'manual');

        return response()->json([
            'message' => 'Status di-update.',
            'data' => $f->fresh(['assignee:id,name,email', 'resolver:id,name']),
        ]);
    }
}
