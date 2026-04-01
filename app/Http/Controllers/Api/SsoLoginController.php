<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TenantSso;
use App\Models\Organization;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;

class SsoLoginController extends Controller
{
    /**
     * Set dynamic config before calling Socialite.
     */
    private function setConfig(TenantSso $sso, $orgSlug)
    {
        $provider = $sso->provider;
        
        config(["services.{$provider}.client_id" => $sso->client_id]);
        config(["services.{$provider}.client_secret" => $sso->client_secret]);
        
        // Azure specific
        if ($provider === 'azure' && $sso->tenant_id) {
            config(["services.azure.tenant" => $sso->tenant_id]);
        }
        
        // Keycloak specific
        if ($provider === 'keycloak' && $sso->custom_domain) {
            config(["services.keycloak.base_url" => rtrim($sso->custom_domain, '/')]);
            config(["services.keycloak.realms" => 'master']); // Configurable if needed
        }

        // Set callback URL pointing to this API
        config(["services.{$provider}.redirect" => url("/api/sso/callback?org={$orgSlug}")]);
    }

    /**
     * Get the redirect URL for the frontend.
     */
    public function redirect(Request $request)
    {
        $request->validate(['org' => 'required|string']);
        $org = Organization::where('slug', $request->org)->orWhere('domain', $request->org)->first();

        if (!$org) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        $sso = TenantSso::where('org_id', $org->id)->where('is_active', true)->first();

        if (!$sso) {
            return response()->json(['message' => 'SSO is not configured for this organization'], 404);
        }

        $this->setConfig($sso, $request->org);

        // For API, we must return the URL instead of redirecting directly.
        $targetUrl = Socialite::driver($sso->provider)->stateless()->redirect()->getTargetUrl();

        return response()->json(['url' => $targetUrl]);
    }

    /**
     * Handle provider callback, issue token, and redirect to frontend.
     */
    public function callback(Request $request)
    {
        $orgSlug = $request->query('org');
        if (!$orgSlug) abort(404, 'Organization slug is missing');

        $org = Organization::where('slug', $orgSlug)->firstOrFail();
        $sso = TenantSso::where('org_id', $org->id)->where('is_active', true)->firstOrFail();

        $this->setConfig($sso, $orgSlug);

        try {
            $socialUser = Socialite::driver($sso->provider)->stateless()->user();
            
            // Auto layout or login
            $user = User::where('email', $socialUser->getEmail())->first();
            
            if (!$user) {
                // Auto provisioning
                $user = User::create([
                    'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'Sso User',
                    'email' => $socialUser->getEmail(),
                    'password' => bcrypt(\Illuminate\Support\Str::random(16)), // Need dummy password
                    'org_id' => $org->id,
                    'role' => 'user' // Default to user
                ]);
            } else {
                // Ensure the user actually belongs to this org
                if ($user->org_id !== $org->id && $user->role !== 'superadmin') {
                    abort(403, 'User belongs to a different organization');
                }
            }

            // Generate token
            $token = $user->createToken('sso-token')->plainTextToken;

            // Redirect back to frontend
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect("{$frontendUrl}/sso-success?token=" . urlencode($token));

        } catch (\Exception $e) {
            // Usually invalid state or user cancelled
            $frontendUrl = env('FRONTEND_URL', 'http://localhost:3000');
            return redirect("{$frontendUrl}/login?error=sso_failed");
        }
    }
}
