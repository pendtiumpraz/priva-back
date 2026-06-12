<?php

namespace App\Models\Concerns;

/**
 * Assignment-based row visibility, shared by RoPA & DPIA (and mirrored by
 * Vendor::scopeVisibleTo). A non-admin user only sees records that are:
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
    public const ASSIGN_DIV_DELIM = ' | ';

    public function scopeVisibleTo($query, $user)
    {
        if (! $user) {
            return $query;
        }
        $role = $user->role ?? '';
        $tenantRoleName = optional($user->tenantRole)->name;
        $isAdminish = in_array($role, ['superadmin', 'admin', 'dpo'], true)
            || in_array(strtolower((string) $tenantRoleName), ['admin', 'dpo'], true);
        if ($isAdminish) {
            return $query;
        }

        $userId = $user->id;
        $deptName = optional($user->department)->name;
        $usesCreatedBy = $this->assignmentUsesCreatedBy();
        $usesRopaWizard = $this->assignmentUsesRopaWizard();

        return $query->where(function ($w) use ($userId, $deptName, $usesCreatedBy, $usesRopaWizard) {
            // (a) All-group (assign_group kosong atau "(All Group)")
            $w->where(function ($a) {
                $a->whereNull('assign_group')
                  ->orWhere('assign_group', '(All Group)');
            });
            // (b) Assignee eksplisit
            $w->orWhereJsonContains('assignees', $userId);
            // (d) Pembuat record
            if ($usesCreatedBy) {
                $w->orWhere('created_by', $userId);
            }
            // (c) Divisi — anchored pada delimiter supaya 'HR' tidak match 'HRD'.
            if ($deptName) {
                $d = self::ASSIGN_DIV_DELIM;
                $esc = addcslashes($deptName, '%_\\');
                $w->orWhere('assign_group', $deptName)
                  ->orWhere('assign_group', 'like', $esc.$d.'%')
                  ->orWhere('assign_group', 'like', '%'.$d.$esc)
                  ->orWhere('assign_group', 'like', '%'.$d.$esc.$d.'%');
                if ($usesRopaWizard) {
                    // RoPA: divisi terlibat di wizard (multi-divisi, kompat lama).
                    $w->orWhereJsonContains('wizard_data->detail_pemrosesan->divisi_list', $deptName);
                    $w->orWhere('wizard_data->detail_pemrosesan->divisi', $deptName);
                    $w->orWhere('division', $deptName);
                }
            }
        });
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
