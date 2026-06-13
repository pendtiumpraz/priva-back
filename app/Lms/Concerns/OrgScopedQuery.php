<?php

namespace App\Lms\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Controller helper trait for org-scoped admin endpoints.
 *
 * Despite the `scope*` naming, these are *controller* helpers — not Eloquent
 * model scopes. Apply this trait to admin controllers that mutate LMS content
 * (courses, modules, lessons, etc.) where:
 *
 *   - root / superadmin sees everything
 *   - tenant admins see their own org rows + global (org_id IS NULL) rows
 *   - tenant admins may only mutate their own org rows; global rows are
 *     read-only for them
 *
 * Precondition: this trait assumes `auth:sanctum` + `lms.entitled` middleware
 * have already run on the route, so `auth()->user()` is non-null and has a
 * non-null `org_id` (unless the user is root/superadmin, in which case org_id
 * may be null and is bypassed via isRootUser()).
 */
trait OrgScopedQuery
{
    /**
     * Restrict an admin index/show query to rows the current user is allowed
     * to read. Root/superadmin sees all.
     */
    protected function scopeForAdmin(Builder $query): Builder
    {
        $user = auth()->user();

        if ($this->isRootUser($user)) {
            return $query;
        }

        return $query->where(function ($q) use ($user) {
            $q->where('org_id', $user->org_id)
              ->orWhereNull('org_id');
        });
    }

    /**
     * Throw 403 if the current user is not allowed to mutate this model.
     * Root/superadmin can mutate anything. Tenant admins cannot mutate
     * global content (org_id IS NULL) or another org's content.
     */
    protected function assertMutable(Model $model): void
    {
        $user = auth()->user();

        if ($this->isRootUser($user)) {
            return;
        }

        if ($model->org_id === null) {
            abort(403, 'Global content is read-only for tenant admins.');
        }

        if ($model->org_id !== $user->org_id) {
            abort(403, 'Cannot modify content owned by another organization.');
        }
    }

    /**
     * Centralised root/superadmin check — User does not yet expose isRoot().
     */
    protected function isRootUser($user): bool
    {
        return $user !== null && in_array($user->role ?? null, ['root', 'superadmin'], true);
    }
}
