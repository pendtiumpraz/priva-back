<?php

namespace App\Services;

use App\Jobs\ProcessAiJob;
use App\Models\AiJob;
use App\Models\DataDiscoveryScanPlan;
use App\Models\DataDiscoveryScanPlanSystem;
use App\Models\Organization;

/**
 * OnPrem parent-job orchestrator.
 *
 * The "parent" AiJob is type=person_scan_execute. Its handle() flows here.
 * We spawn one child AiJob per plan_system (type=person_scan_execute_app)
 * onto the same queue tier as the parent so per-tenant fairness is preserved.
 *
 * Parent progress is then driven by DataDiscoveryAppExecutor::recomputeParentProgress()
 * — children call back into the parent on every state change.
 *
 * See DATA_DISCOVERY_SEARCH_PLAN.md §6.3.
 */
class DataDiscoveryExecuteOrchestrator
{
    /**
     * Spawn child jobs for every plan_system under the parent's plan_id.
     * Returns a small summary the ProcessAiJob handler stores on the parent
     * row. Idempotent — re-running won't create duplicate child rows for
     * plan_systems that already have a child_ai_job_id.
     */
    public function orchestrate(AiJob $parent): array
    {
        $planId = $parent->payload['plan_id'] ?? $parent->subject_id;
        if (! $planId) {
            throw new \InvalidArgumentException('person_scan_execute payload missing plan_id');
        }

        $plan = DataDiscoveryScanPlan::find($planId);
        if (! $plan) {
            throw new \RuntimeException("Scan plan {$planId} not found");
        }
        if ($plan->org_id !== $parent->org_id) {
            // Would only happen via tampered payload — the plan controller
            // always sets parent.org_id from the plan record itself.
            throw new \RuntimeException('Cross-tenant plan execute blocked');
        }

        $plan->update([
            'status' => DataDiscoveryScanPlan::STATUS_EXECUTING,
            'parent_ai_job_id' => $parent->id,
            'progress' => 0,
        ]);

        $org = Organization::find($parent->org_id);
        $queue = $this->queueFor($org);

        $children = DataDiscoveryScanPlanSystem::where('scan_plan_id', $plan->id)->get();
        $spawned = 0;
        foreach ($children as $ps) {
            if ($ps->child_ai_job_id) {
                continue; // idempotent
            }
            $child = AiJob::create([
                'org_id' => $parent->org_id,
                'user_id' => $parent->user_id,
                'type' => 'person_scan_execute_app',
                'module' => 'data_discovery',
                'subject_id' => $ps->id,
                'label' => "Scan {$ps->app_name}",
                'status' => AiJob::STATUS_PENDING,
                'progress' => 0,
                'payload' => [
                    'plan_id' => $plan->id,
                    'plan_system_id' => $ps->id,
                ],
            ]);
            $ps->update(['child_ai_job_id' => $child->id]);
            ProcessAiJob::dispatch($child->id)->onQueue($queue);
            $spawned++;
        }

        return [
            'plan_id' => $plan->id,
            'spawned' => $spawned,
            'total_systems' => $children->count(),
        ];
    }

    /**
     * Mirror AiJobController::queueFor() so parent and child run on the same
     * tier. Falls back to ai-jobs-low when org has no tier.
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
