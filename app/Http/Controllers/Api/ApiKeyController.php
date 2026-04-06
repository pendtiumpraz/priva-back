<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ApiKeyController extends Controller
{
    /**
     * Get a list of the user's API tokens.
     */
    public function index(Request $request)
    {
        // For a tenant setting, you might just show tokens created by the current user.
        return response()->json([
            'status' => 'success',
            'data' => $request->user()->tokens()->orderBy('created_at', 'desc')->get()
        ]);
    }

    /**
     * Create a new API token.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array' // e.g. ['read', 'write'] - though usually ['*'] is fine for tenant admins
        ]);

        $abilities = $request->input('abilities', ['*']);
        $token = $request->user()->createToken($request->name, $abilities);

        return response()->json([
            'status' => 'success',
            'message' => 'API Key created successfully. Please copy it now as it won\'t be shown again.',
            'data' => [
                'token' => $token->plainTextToken,
                'token_record' => $token->accessToken,
            ]
        ], 201);
    }

    /**
     * Revoke / Delete an API token.
     */
    public function destroy(Request $request, $id)
    {
        $deleted = $request->user()->tokens()->where('id', $id)->delete();

        if (!$deleted) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token not found or unauthorized'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'API Key deactivated/revoked successfully'
        ]);
    }
}
