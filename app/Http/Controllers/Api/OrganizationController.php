<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    /**
     * List all organizations (Super Admin only)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role !== 'superadmin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $orgs = Organization::withCount('users')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($org) {
                // Also get active license for each org
                $license = \App\Models\License::where('org_id', $org->id)
                    ->where('status', 'active')
                    ->first();
                $org->active_license = $license ? [
                    'id' => $license->id,
                    'key' => $license->license_key,
                    'package_type' => $license->package_type,
                    'license_type' => $license->license_type,
                    'expires_at' => $license->expires_at,
                    'status' => $license->isExpired() ? 'expired' : 'active',
                ] : null;
                return $org;
            });

        return response()->json(['data' => $orgs]);
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $org = Organization::find($user->org_id);
        if (!$org) {
            return response()->json(['message' => 'No organization'], 404);
        }
        return response()->json(['data' => $org]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $org = Organization::findOrFail($user->org_id);

        $fields = $request->only([
            'name', 'industry', 'logo_url', 'privacy_policy_url',
            'website', 'address', 'phone', 'email',
            // Onboarding fields
            'business_model', 'company_size', 'data_subjects_type', 'core_systems',
            'has_dpo', 'onboarding_completed',
        ]);

        $org->update($fields);

        return response()->json(['message' => 'Organization updated', 'data' => $org->fresh()]);
    }
}
