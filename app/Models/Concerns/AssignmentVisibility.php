<?php

namespace App\Models\Concerns;

use App\Support\AssignmentScope;

/**
 * Assignment-based row visibility, shared by RoPA & DPIA (and by
 * Vendor::scopeVisibleTo, which delegates to the same helper).
 *
 * The clause itself lives in App\Support\AssignmentScope so that the connection
 * map — which reads rows through a raw query builder and therefore never sees an
 * Eloquent scope — applies the exact same rule. A non-admin user only sees
 * records that are:
 *   - assigned to "(All Group)" (or unassigned), OR
 *   - explicitly assigned to them (in the `assignees` JSON array), OR
 *   - created by them (`created_by`), OR
 *   - assigned to their division (`assign_group`, single or ' | '-joined multi).
 *
 * admin / dpo / superadmin (global role OR tenant role name) bypass — they see
 * everything in the tenant. This trait ONLY adds WHERE clauses; the caller is
 * still responsible for the org_id boundary.
 *
 * This is the single source of truth used by ModuleCrudController (list/CRUD),
 * AiAgentToolExecutor (AI tool reads), and the @mention endpoint — so AI access
 * can never be broader than what the user sees in the normal UI.
 */
trait AssignmentVisibility
{
    /** Multi-division delimiter on `assign_group` — MUST match FE ASSIGN_DIV_DELIM. */
    public const ASSIGN_DIV_DELIM = AssignmentScope::DELIM;

    public function scopeVisibleTo($query, $user)
    {
        AssignmentScope::terapkan(
            $query,
            $user,
            $this->assignmentUsesCreatedBy(),
            $this->assignmentUsesRopaWizard(),
        );

        return $query;
    }

    /** Override to false on models without a `created_by` column. */
    protected function assignmentUsesCreatedBy(): bool
    {
        return true;
    }

    /** Override to true on RoPA (wizard_data divisi paths + legacy `division`). */
    protected function assignmentUsesRopaWizard(): bool
    {
        return false;
    }
}
