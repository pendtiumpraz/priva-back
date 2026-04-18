<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\License;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Tenant lifecycle transitions. Root/superadmin-only.
 *
 * Lifecycle states:
 *   active       → normal ops
 *   frozen       → read-only, kept for compliance retention period
 *   transferred  → ownership changed (sold/merged); data kept, new admin
 *   archived     → marked for hard delete at hard_delete_at (default +30d)
 *
 * Soft delete (deleted_at) is ONLY set on 'archived' — frozen/transferred
 * tenants remain queryable for audit/compliance.
 */
class TenantOffboardController extends Controller
{
    public function freeze(Request $request, string $id)
    {
        $this->requireAuthority($request);
        $data = $request->validate([
            'reason' => 'required|string|in:end_of_contract,non_payment,pause,other',
            'notes' => 'nullable|string|max:1000',
            'password' => 'required|string',
        ]);
        $this->requirePassword($request, $data['password']);

        $org = Organization::findOrFail($id);

        License::where('org_id', $org->id)->where('status', 'active')->update(['status' => 'suspended']);

        $org->update([
            'lifecycle_status' => 'frozen',
            'offboarded_at' => now(),
            'offboarded_by' => $request->user()->id,
            'offboard_reason' => $data['reason'],
            'offboard_notes' => $data['notes'] ?? null,
        ]);

        AuditLog::log('organization', $org->id, 'frozen', [
            'reason' => $data['reason'],
            'notes' => $data['notes'] ?? null,
        ], 'lifecycle');

        return response()->json(['message' => "Tenant {$org->name} di-freeze. Data read-only; license suspended.", 'data' => $org->fresh()]);
    }

    public function unfreeze(Request $request, string $id)
    {
        $this->requireAuthority($request);
        $org = Organization::findOrFail($id);
        if ($org->lifecycle_status !== 'frozen') {
            return response()->json(['message' => 'Tenant tidak dalam status frozen'], 422);
        }

        License::where('org_id', $org->id)->where('status', 'suspended')->update(['status' => 'active']);

        $org->update([
            'lifecycle_status' => 'active',
            'offboarded_at' => null,
            'offboarded_by' => null,
            'offboard_reason' => null,
            'offboard_notes' => null,
        ]);

        AuditLog::log('organization', $org->id, 'unfrozen', [], 'lifecycle');

        return response()->json(['message' => "Tenant {$org->name} dikembalikan ke status active", 'data' => $org->fresh()]);
    }

    /**
     * Transfer ownership (e.g. company sold). Renames tenant + optionally swaps
     * the admin user's email. All data stays.
     */
    public function transfer(Request $request, string $id)
    {
        $this->requireAuthority($request);
        $data = $request->validate([
            'new_name' => 'required|string|max:255',
            'new_slug' => 'nullable|string|max:255|regex:/^[a-z0-9-]+$/',
            'new_admin_email' => 'nullable|email|max:255',
            'new_admin_name' => 'nullable|string|max:255',
            'new_admin_password' => 'nullable|string|min:8',
            'notes' => 'nullable|string|max:1000',
            'password' => 'required|string',
        ]);
        $this->requirePassword($request, $data['password']);

        $org = Organization::findOrFail($id);
        $previousName = $org->name;

        $org->update([
            'name' => $data['new_name'],
            'slug' => $data['new_slug'] ?? Str::slug($data['new_name']),
            'lifecycle_status' => 'transferred',
            'offboarded_at' => now(),
            'offboarded_by' => $request->user()->id,
            'offboard_reason' => 'sold',
            'offboard_notes' => $data['notes'] ?? null,
        ]);

        // Optionally create/reset the admin user
        if (!empty($data['new_admin_email'])) {
            $existing = User::where('email', $data['new_admin_email'])->first();
            if ($existing) {
                $existing->update([
                    'org_id' => $org->id,
                    'role' => 'admin',
                    'name' => $data['new_admin_name'] ?? $existing->name,
                    'is_active' => true,
                    ...(isset($data['new_admin_password']) ? ['password' => Hash::make($data['new_admin_password'])] : []),
                ]);
            } else {
                User::create([
                    'id' => (string) Str::uuid(),
                    'name' => $data['new_admin_name'] ?? 'New Admin',
                    'email' => $data['new_admin_email'],
                    'password' => Hash::make($data['new_admin_password'] ?? Str::random(16)),
                    'role' => 'admin',
                    'org_id' => $org->id,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
            }
        }

        AuditLog::log('organization', $org->id, 'transferred', [
            'previous_name' => $previousName,
            'new_name' => $data['new_name'],
            'new_admin_email' => $data['new_admin_email'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], 'lifecycle');

        return response()->json(['message' => "Tenant transferred from [{$previousName}] → [{$data['new_name']}].", 'data' => $org->fresh()]);
    }

    /**
     * Archive for eventual hard delete. Default: schedule hard delete in 30 days.
     * Soft-deletes the org so it no longer appears in normal queries.
     */
    public function archive(Request $request, string $id)
    {
        $this->requireAuthority($request);
        $data = $request->validate([
            'reason' => 'required|string|in:sold,merged,end_of_contract,bankrupt,other',
            'retention_days' => 'nullable|integer|min:0|max:2555', // max 7 years
            'notes' => 'nullable|string|max:1000',
            'password' => 'required|string',
        ]);
        $this->requirePassword($request, $data['password']);

        $org = Organization::findOrFail($id);
        $retention = $data['retention_days'] ?? 30;

        License::where('org_id', $org->id)->where('status', 'active')->update(['status' => 'revoked']);

        $org->update([
            'lifecycle_status' => 'archived',
            'offboarded_at' => now(),
            'offboarded_by' => $request->user()->id,
            'offboard_reason' => $data['reason'],
            'offboard_notes' => $data['notes'] ?? null,
            'hard_delete_at' => now()->addDays($retention),
        ]);
        $org->delete(); // soft-delete

        AuditLog::log('organization', $org->id, 'archived', [
            'reason' => $data['reason'],
            'retention_days' => $retention,
            'hard_delete_at' => $org->hard_delete_at?->toIso8601String(),
        ], 'lifecycle');

        return response()->json(['message' => "Tenant {$org->name} archived. Will be permanently deleted in {$retention} days."]);
    }

    public function status(Request $request, string $id)
    {
        $this->requireAuthority($request);
        $org = Organization::withTrashed()->findOrFail($id);
        return response()->json(['data' => $org]);
    }

    // ──────────────────────────────────────────────
    private function requireAuthority(Request $request): void
    {
        $role = $request->user()->role ?? null;
        if (!in_array($role, ['root', 'superadmin'], true)) {
            abort(403, 'Hanya root/superadmin yang bisa offboard tenant.');
        }
    }

    private function requirePassword(Request $request, string $password): void
    {
        if (!Hash::check($password, $request->user()->password)) {
            abort(403, 'Password konfirmasi salah — tindakan dibatalkan.');
        }
    }
}
