<?php

namespace App\Services;

/**
 * Request-scoped holder for the active tenant org id.
 *
 * Set by the SetCurrentOrgContext middleware after auth:sanctum, then read by
 * the BelongsToOrg trait's global scope to filter every query for that user
 * to their own org. When unset (artisan commands, queue jobs without explicit
 * context, super-admin tools), the scope is a no-op so callers see all rows.
 *
 * Bound as a singleton in AppServiceProvider so every consumer in the same
 * request shares the same instance.
 *
 * Use `runAs($orgId, fn() => ...)` to temporarily impersonate an org from
 * background jobs or maintenance scripts. Always restores the prior context
 * even if the closure throws.
 */
class CurrentOrgContext
{
    private ?string $orgId = null;

    public function set(?string $orgId): void
    {
        $this->orgId = $orgId;
    }

    public function get(): ?string
    {
        return $this->orgId;
    }

    public function clear(): void
    {
        $this->orgId = null;
    }

    public function has(): bool
    {
        return $this->orgId !== null;
    }

    /**
     * Temporarily run a callback in the given org's context, restoring the
     * previous context afterwards (even on exception). Useful from queue
     * jobs that know which org they belong to.
     */
    public function runAs(string $orgId, callable $callback): mixed
    {
        $previous = $this->orgId;
        $this->orgId = $orgId;
        try {
            return $callback();
        } finally {
            $this->orgId = $previous;
        }
    }
}
