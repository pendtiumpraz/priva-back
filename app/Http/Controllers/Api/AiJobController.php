<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAiJobRequest;
use App\Jobs\ProcessAiJob;
use App\Models\AiJob;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI background job dispatcher (INFRASTRUCTURE_PLAN.md §5.3).
 *
 * Frontend POSTs to /api/ai/jobs and gets 202 + job id; then polls
 * /api/ai/jobs/active every ~10s. Multi-tenant scoping is enforced on every
 * read by filtering org_id from the authenticated user (CLAUDE.md
 * invariant). AiJob does NOT use BelongsToOrg trait — see model docblock.
 */
class AiJobController extends Controller
{
    /**
     * Dispatch a new AI job.
     *
     * Returns:
     *   202 — accepted, job queued
     *   409 — duplicate (job already pending/running for same subject)
     *   429 — concurrent quota exhausted for this user
     *   503 — admin disabled AI jobs platform-wide
     */
    public function store(StoreAiJobRequest $req): JsonResponse
    {
        if (! config('ai.jobs_enabled', true)) {
            return response()->json([
                'error' => 'AI jobs disabled by admin',
            ], 503);
        }

        $user = $req->user();
        $orgId = $user->org_id;
        $userId = $user->id;

        if (! $orgId) {
            return response()->json([
                'error' => 'User has no organization context',
            ], 422);
        }

        // Dedup: refuse a second active job on the same subject + type for
        // this user. Returns the existing job id so the frontend can attach
        // its progress UI to the in-flight job rather than creating a new
        // one. subject_id may be null (e.g. "summarize whole dashboard") —
        // dedup only applies when both type and subject_id are set.
        if ($req->subject_id) {
            $existing = AiJob::forOrg($orgId)
                ->where('user_id', $userId)
                ->where('type', $req->type)
                ->where('subject_id', $req->subject_id)
                ->active()
                ->first();

            if ($existing) {
                return response()->json([
                    'error' => 'Job already in progress for this subject',
                    'job_id' => $existing->id,
                ], 409);
            }
        }

        // Per-user concurrent quota — orthogonal to dedup. Counts every
        // active job for the user regardless of subject.
        $maxConcurrent = (int) config('ai.max_concurrent_per_user', 5);
        $activeCount = AiJob::forOrg($orgId)
            ->where('user_id', $userId)
            ->active()
            ->count();

        if ($activeCount >= $maxConcurrent) {
            return response()->json([
                'error' => "Max {$maxConcurrent} concurrent jobs reached. Wait for an in-flight job to finish.",
            ], 429);
        }

        $job = AiJob::create([
            'org_id' => $orgId,
            'user_id' => $userId,
            'type' => $req->type,
            'module' => $req->module,
            'subject_id' => $req->subject_id,
            'label' => $req->label,
            'status' => AiJob::STATUS_PENDING,
            'progress' => 0,
            'payload' => $req->payload,
        ]);

        $org = Organization::find($orgId);
        ProcessAiJob::dispatch($job->id)->onQueue($this->queueFor($org));

        return response()->json([
            'id' => $job->id,
            'status' => $job->status,
        ], 202);
    }

    /**
     * Active jobs for the current user (the ones the footer animates over).
     * Returns plain array, not paginated — quota caps it at ~5.
     */
    public function active(Request $req): JsonResponse
    {
        $user = $req->user();
        $jobs = AiJob::forOrg($user->org_id)
            ->where('user_id', $user->id)
            ->active()
            ->orderByDesc('created_at')
            ->get(['id', 'type', 'module', 'subject_id', 'label', 'status', 'progress', 'created_at']);

        return response()->json($jobs);
    }

    /**
     * Terminal jobs (done/failed/cancelled) for the user's org. Paginated;
     * footer's History tab consumes this.
     *
     * Scoped to org-level rather than user-level so DPOs can see their
     * team's AI runs (audit / debugging). Tighten to user_id only if a
     * tenant requests it.
     */
    public function history(Request $req): JsonResponse
    {
        $user = $req->user();
        $jobs = AiJob::forOrg($user->org_id)
            ->whereIn('status', AiJob::TERMINAL_STATUSES)
            ->orderByDesc('finished_at')
            ->paginate(20);

        return response()->json($jobs);
    }

    /**
     * Single-job detail. Org-scoped — a user from another tenant requesting
     * by id gets 404, not 403, so we don't leak existence.
     */
    public function show(Request $req, string $id): JsonResponse
    {
        $user = $req->user();
        $job = AiJob::forOrg($user->org_id)->find($id);

        if (! $job) {
            return response()->json(['error' => 'Not found'], 404);
        }

        return response()->json($job);
    }

    /**
     * Per-tenant fairness via queue routing (INFRASTRUCTURE_PLAN.md §8).
     * Falls back to ai-jobs-low when the org has no `tier` column — the
     * MVP Organization model doesn't have one yet.
     */
    private function queueFor(?Organization $org): string
    {
        $tier = $org?->tier ?? 'standard';

        return match ($tier) {
            'enterprise' => 'ai-jobs-priority',
            'pro' => 'ai-jobs',
            default => 'ai-jobs-low',
        };
    }
}
