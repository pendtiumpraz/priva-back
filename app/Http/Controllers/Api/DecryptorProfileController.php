<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DecryptorProfile;
use App\Models\InformationSystem;
use App\Services\DecryptService;
use Illuminate\Http\Request;

class DecryptorProfileController extends Controller
{
    public function index(Request $request, string $systemId)
    {
        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($systemId);
        $profiles = DecryptorProfile::where('system_id', $system->id)
            ->where('org_id', $request->user()->org_id)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json(['data' => $profiles]);
    }

    public function store(Request $request, string $systemId)
    {
        $request->validate([
            'name' => 'required|string|max:120',
            'algorithm' => 'required|string|in:' . implode(',', DecryptService::ALGORITHMS),
            'key' => 'required|string|min:4',
            'columns' => 'nullable|array',
            'columns.*.table' => 'required_with:columns|string',
            'columns.*.column' => 'required_with:columns|string',
            'is_active' => 'nullable|boolean',
        ]);

        $system = InformationSystem::where('org_id', $request->user()->org_id)->findOrFail($systemId);

        $profile = DecryptService::createProfile([
            'system_id' => $system->id,
            'org_id' => $request->user()->org_id,
            'name' => $request->input('name'),
            'algorithm' => $request->input('algorithm'),
            'key' => $request->input('key'),
            'columns' => $request->input('columns'),
            'is_active' => $request->boolean('is_active', true),
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $profile, 'message' => 'Profile berhasil dibuat.'], 201);
    }

    public function update(Request $request, string $systemId, string $profileId)
    {
        $request->validate([
            'name' => 'nullable|string|max:120',
            'algorithm' => 'nullable|string|in:' . implode(',', DecryptService::ALGORITHMS),
            'key' => 'nullable|string|min:4',
            'columns' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $profile = DecryptorProfile::where('system_id', $systemId)
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($profileId);

        $updated = DecryptService::updateProfile($profile, $request->only(['name', 'algorithm', 'key', 'columns', 'is_active']));
        return response()->json(['data' => $updated, 'message' => 'Profile diupdate.']);
    }

    public function destroy(Request $request, string $systemId, string $profileId)
    {
        $profile = DecryptorProfile::where('system_id', $systemId)
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($profileId);
        $profile->delete();
        return response()->json(['message' => 'Profile dihapus.']);
    }

    /**
     * Test a saved profile by letting the admin paste one ciphertext value
     * they already know the plaintext for. Returns only a masked preview to
     * confirm the key works — we never log the real plaintext.
     */
    public function test(Request $request, string $systemId, string $profileId)
    {
        $request->validate([
            'ciphertext' => 'required|string',
        ]);

        $profile = DecryptorProfile::where('system_id', $systemId)
            ->where('org_id', $request->user()->org_id)
            ->findOrFail($profileId);

        $res = DecryptService::test($profile, $request->input('ciphertext'));
        return response()->json($res, $res['ok'] ? 200 : 400);
    }
}
