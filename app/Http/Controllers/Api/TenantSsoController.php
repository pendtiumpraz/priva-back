<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TenantSso;

class TenantSsoController extends Controller
{
    /**
     * Get SSO configuration for a specific organization or current org.
     */
    public function show(Request $request)
    {
        $orgId = $request->get('org_id', auth()->user()->org_id);
        
        // Ensure user belongs to the org or is superadmin
        if (auth()->user()->role !== 'superadmin' && auth()->user()->org_id !== $orgId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $sso = TenantSso::where('org_id', $orgId)->first();
        if (!$sso) {
            return response()->json(null);
        }

        // Return client secret ONLY if they have permission, otherwise keep it hidden (or masked)
        // Hidden array works automatically if we return JSON, but we want to allow editing,
        // so maybe we return a masked string for the secret.
        $data = $sso->toArray();
        if ($sso->client_secret) {
            $data['client_secret'] = '********'; // Masked
        }

        return response()->json($data);
    }

    /**
     * Save SSO config.
     */
    public function update(Request $request)
    {
        $orgId = $request->get('org_id', auth()->user()->org_id);
        
        if (auth()->user()->role !== 'superadmin' && auth()->user()->org_id !== $orgId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'provider' => 'required|string|in:azure,google,keycloak',
            'client_id' => 'required|string',
            'client_secret' => 'nullable|string',
            'tenant_id' => 'nullable|string',
            'custom_domain' => 'nullable|string',
            'is_active' => 'boolean'
        ]);

        $sso = TenantSso::firstOrNew(['org_id' => $orgId]);
        $sso->provider = $request->provider;
        $sso->client_id = $request->client_id;
        
        // Update secret only if it's provided and not masked
        if ($request->filled('client_secret') && $request->client_secret !== '********') {
            $sso->client_secret = $request->client_secret;
        }
        
        $sso->tenant_id = $request->tenant_id;
        $sso->custom_domain = $request->custom_domain;
        $sso->is_active = $request->is_active ?? true;
        
        $sso->save();

        return response()->json([
            'message' => 'SSO Configuration saved successfully',
            'data' => collect($sso)->except(['client_secret'])
        ]);
    }
}
