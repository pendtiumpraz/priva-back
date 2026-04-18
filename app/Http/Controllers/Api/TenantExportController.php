<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BreachIncident;
use App\Models\ConsentCollectionPoint;
use App\Models\Dpia;
use App\Models\DsrRequest;
use App\Models\GapAssessment;
use App\Models\InformationSystem;
use App\Models\License;
use App\Models\Organization;
use App\Models\Ropa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Full tenant data export — compliance obligation before archival/hard-delete.
 * Generates a JSON bundle with every record belonging to the tenant.
 * Root or superadmin only. Password re-auth required.
 */
class TenantExportController extends Controller
{
    public function export(Request $request, string $id)
    {
        $user = $request->user();
        if (!in_array($user->role, ['root', 'superadmin'], true)) {
            abort(403, 'Hanya root/superadmin yang bisa export tenant.');
        }

        $request->validate(['password' => 'required|string']);
        if (!Hash::check($request->password, $user->password)) {
            abort(403, 'Password konfirmasi salah.');
        }

        $org = Organization::withTrashed()->findOrFail($id);

        $bundle = [
            'meta' => [
                'exported_at' => now()->toIso8601String(),
                'exported_by' => ['id' => $user->id, 'email' => $user->email, 'role' => $user->role],
                'privasimu_version' => config('app.version', 'unknown'),
                'format_version' => '1.0',
            ],
            'organization' => $org->toArray(),
            'users' => User::where('org_id', $org->id)->withTrashed()->get()->makeHidden(['password', 'remember_token'])->toArray(),
            'licenses' => License::where('org_id', $org->id)->get()->toArray(),
            'ropa' => Ropa::where('org_id', $org->id)->withTrashed()->get()->toArray(),
            'dpia' => Dpia::where('org_id', $org->id)->withTrashed()->get()->toArray(),
            'gap_assessments' => GapAssessment::where('org_id', $org->id)->withTrashed()->get()->toArray(),
            'dsr_requests' => DsrRequest::where('org_id', $org->id)->withTrashed()->get()->toArray(),
            'consent_collection_points' => ConsentCollectionPoint::where('org_id', $org->id)->withTrashed()->get()->toArray(),
            'breach_incidents' => BreachIncident::where('org_id', $org->id)->withTrashed()->get()->toArray(),
            'information_systems' => InformationSystem::where('org_id', $org->id)->withTrashed()->get()->toArray(),
            'audit_logs' => AuditLog::where(function ($q) use ($org) {
                $q->where('record_id', $org->id)
                    ->orWhereJsonContains('changes->org_id', $org->id);
            })->orderBy('created_at')->get()->toArray(),
        ];

        try {
            AuditLog::log('organization', $org->id, 'full_export', [
                'tables' => array_keys($bundle),
                'record_counts' => [
                    'users' => count($bundle['users']),
                    'ropa' => count($bundle['ropa']),
                    'dpia' => count($bundle['dpia']),
                    'gap' => count($bundle['gap_assessments']),
                    'dsr' => count($bundle['dsr_requests']),
                    'consent' => count($bundle['consent_collection_points']),
                    'breach' => count($bundle['breach_incidents']),
                ],
            ], 'tenant_export');
        } catch (\Throwable $e) { \Log::warning('Audit log for export failed: ' . $e->getMessage()); }

        $filename = 'tenant-export-' . Str::slug($org->name) . '-' . now()->format('Y-m-d-His') . '.json';

        return response()->json($bundle)
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}
