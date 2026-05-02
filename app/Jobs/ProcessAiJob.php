<?php

namespace App\Jobs;

use App\Models\AiJob;
use App\Models\AuditLog;
use App\Services\AiAgentToolExecutor;
use App\Services\AiFieldMappingService;
use App\Services\AiService;
use App\Services\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async AI job dispatcher (INFRASTRUCTURE_PLAN.md §5.2).
 *
 * Wraps AiService / AiAgentToolExecutor / AiFieldMappingService calls into a
 * queued worker so the request thread can return 202 immediately. The frontend
 * polls /api/ai/jobs/active to render progress in the footer.
 *
 * Tenant invariants:
 *   - AiAgentToolExecutor is constructed with $job->org_id, never the
 *     calling user's "current" org — workers don't share request state.
 *   - All credit debits go through CreditService.
 *   - Errors are recorded on the AiJob row AND re-thrown so Laravel's queue
 *     retry/back-off logic engages ($tries=3).
 */
class ProcessAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retries before the job is moved to failed_jobs. */
    public int $tries = 3;

    /** Per-attempt timeout in seconds. */
    public int $timeout = 300;

    public function __construct(public string $jobId) {}

    public function handle(
        AiService $ai,
        AiFieldMappingService $mapper,
        CreditService $credit,
    ): void {
        $job = AiJob::find($this->jobId);
        if (! $job) {
            // Row was hard-deleted between dispatch and pickup. Nothing to do.
            return;
        }

        // Kill switch — admin can flip ai.jobs_enabled to false to drain the
        // queue without losing rows. Cancelled jobs are user-visible (history
        // view) so they know why nothing happened.
        if (! config('ai.jobs_enabled', true)) {
            $job->update([
                'status' => AiJob::STATUS_CANCELLED,
                'error' => 'AI jobs disabled by admin',
                'finished_at' => now(),
            ]);
            return;
        }

        $job->update([
            'status' => AiJob::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            $result = match ($job->type) {
                'autofill' => $this->runAutofill($mapper, $job),
                'analyzer' => $this->runAnalyzer($job),
                'summary' => $this->runSummary($ai, $job),
                'deep_scan' => throw new \RuntimeException('deep_scan not implemented in MVP'),
                default => throw new \InvalidArgumentException("Unknown AI job type: {$job->type}"),
            };

            $creditsUsed = $this->debitCredits($credit, $job, $result);

            $job->update([
                'status' => AiJob::STATUS_DONE,
                'progress' => 100,
                'result' => $this->normalizeResult($result),
                'credits_used' => $creditsUsed,
                'finished_at' => now(),
            ]);

            $this->writeAudit($job, $creditsUsed);
        } catch (\Throwable $e) {
            $job->update([
                'status' => AiJob::STATUS_FAILED,
                'error' => substr($e->getMessage(), 0, 1024),
                'finished_at' => now(),
            ]);

            Log::error('ProcessAiJob failed', [
                'job_id' => $job->id,
                'org_id' => $job->org_id,
                'type' => $job->type,
                'error' => $e->getMessage(),
            ]);

            // Re-throw so the queue marks the attempt failed and applies
            // retry/back-off semantics ($tries=3).
            throw $e;
        }
    }

    /**
     * Autofill — used by ROPA / DPIA wizards to extract structured fields
     * from raw text or a document blob. Payload shape is owned by
     * AiFieldMappingService::map().
     */
    private function runAutofill(AiFieldMappingService $mapper, AiJob $job): array
    {
        $extractedData = $job->payload['extracted_data'] ?? $job->payload;
        $targetModule = $job->module ?? $job->payload['target_module'] ?? 'ropa';

        return $mapper->map($extractedData, $targetModule, $job->org_id);
    }

    /**
     * Analyzer — dispatches a tool call through AiAgentToolExecutor with
     * tenant scope locked to this job's org_id (CLAUDE.md invariant).
     */
    private function runAnalyzer(AiJob $job): array
    {
        $tool = $job->payload['tool'] ?? null;
        $args = $job->payload['args'] ?? [];
        $approved = (bool) ($job->payload['approved'] ?? false);

        if (! $tool) {
            throw new \InvalidArgumentException('analyzer payload missing `tool`');
        }

        // Re-instantiate the executor with the row's org_id rather than
        // relying on request state (workers have none).
        $executor = app(AiAgentToolExecutor::class, ['orgId' => $job->org_id]);

        // execute() returns [result, step_description] — both shapes are
        // useful so we wrap into a normalized array.
        $output = $executor->execute($tool, $args, $approved);

        return [
            'tool' => $tool,
            'output' => $output,
        ];
    }

    /**
     * Summary — short-form text generation, e.g. dashboard summaries.
     */
    private function runSummary(AiService $ai, AiJob $job): array
    {
        $content = $job->payload['content'] ?? '';
        $systemPrompt = $job->payload['system_prompt']
            ?? 'You are a privacy compliance analyst. Summarize the input in clear, concise language.';

        if ($content === '') {
            throw new \InvalidArgumentException('summary payload missing `content`');
        }

        $response = $ai->ask($systemPrompt, $content, (int) ($job->payload['max_tokens'] ?? 1500));

        return [
            'summary' => $response,
        ];
    }

    /**
     * Translate AI job type → CreditService action_type, then debit. Returns
     * the (rounded-up integer) cost actually charged so we can persist it
     * on the AiJob row.
     */
    private function debitCredits(CreditService $credit, AiJob $job, array $result): int
    {
        $actionType = $this->actionTypeFor($job);
        if ($actionType === null) {
            return 0;
        }

        try {
            $log = CreditService::deduct(
                orgId: $job->org_id,
                userId: $job->user_id,
                actionType: $actionType,
                module: $job->module,
                recordId: $job->subject_id,
                meta: ['ai_job_id' => $job->id, 'tokens' => $result['tokens'] ?? null],
            );
            return (int) ceil((float) $log->credits_used);
        } catch (\Throwable $e) {
            // Never let a credit-ledger hiccup mask a successful AI run.
            Log::warning('CreditService::deduct failed in ProcessAiJob', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Map AI job type+module to the CreditService action_type key. Returns
     * null when no matching cost is defined (no credit debited).
     */
    private function actionTypeFor(AiJob $job): ?string
    {
        $module = $job->module ?? '';

        return match ($job->type) {
            'autofill' => match ($module) {
                'ropa' => 'autofill_ropa',
                'dpia' => 'autofill_dpia',
                'breach' => 'autofill_breach',
                'dsr' => 'autofill_dsr',
                default => 'autofill_ropa',
            },
            'analyzer' => match ($module) {
                'ropa' => 'analysis_ropa',
                'dpia' => 'analysis_dpia',
                'breach' => 'analysis_breach',
                'dsr' => 'analysis_dsr',
                'consent' => 'analysis_consent',
                default => 'analysis_ropa',
            },
            'summary' => 'dashboard_summary',
            default => null,
        };
    }

    /**
     * Ensure the result column always stores an array — some service methods
     * return scalars/strings.
     */
    private function normalizeResult(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }
        return ['value' => $result];
    }

    private function writeAudit(AiJob $job, int $creditsUsed): void
    {
        try {
            AuditLog::create([
                'module' => 'ai_job',
                'record_id' => $job->id,
                'action' => 'ai.job.complete',
                'user_id' => $job->user_id,
                'user_name' => optional($job->user)->name ?? 'AI Worker',
                'user_role' => 'system',
                'section' => $job->module,
                'changes' => [
                    'type' => $job->type,
                    'credits' => $creditsUsed,
                    'subject_id' => $job->subject_id,
                ],
            ]);
        } catch (\Throwable $e) {
            // Audit write failure must not poison a successful job result.
            Log::warning('ProcessAiJob audit write failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
