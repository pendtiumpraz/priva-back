<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
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
