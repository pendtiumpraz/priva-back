<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Services\NotificationService;
use Illuminate\Http\Request;

/**
 * Per-user notification preferences (kind × module × channel toggles).
 *
 * The UI shows a grid; the backend stores one row per combination that
 * deviates from the default (all enabled, instant digest). Empty rows
 * = inherit default.
 */
class NotificationPreferenceController extends Controller
{
    /** Kind × module matrix the UI renders. Keep aligned with NOTIFICATION_SYSTEM_PLAN. */
    public const MODULES = [
        'ropa', 'dpia', 'dsr', 'breach', 'consent',
        'approval', 'vendor_risk', 'data_discovery',
        'gap_assessment', 'ai', 'mentions',
        'license', 'system', 'tenant',
    ];
    public const CHANNELS = ['in_app', 'email', 'wa', 'push'];

    public function index(Request $request)
    {
        $user = $request->user();
        $rows = NotificationPreference::where('user_id', $user->id)->get();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'modules' => self::MODULES,
                'kinds' => NotificationService::KINDS,
                'channels' => self::CHANNELS,
                'digests' => ['instant', 'hourly', 'daily', 'off'],
            ],
        ]);
    }

    /**
     * Bulk update — client sends an array of rows; server upserts by
     * (user_id, kind, module, channel). Rows not in the payload are
     * left alone.
     */
    public function update(Request $request)
    {
        $user = $request->user();
        $payload = $request->validate([
            'preferences' => 'required|array',
            'preferences.*.kind' => 'required|in:alert,warning,info',
            'preferences.*.module' => 'required|string|max:40',
            'preferences.*.channel' => 'required|in:in_app,email,wa,push',
            'preferences.*.enabled' => 'required|boolean',
            'preferences.*.digest' => 'nullable|in:instant,hourly,daily,off',
        ]);

        $saved = [];
        foreach ($payload['preferences'] as $pref) {
            $row = NotificationPreference::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'kind' => $pref['kind'],
                    'module' => $pref['module'],
                    'channel' => $pref['channel'],
                ],
                [
                    'enabled' => $pref['enabled'],
                    'digest' => $pref['digest'] ?? 'instant',
                ]
            );
            $saved[] = $row;
        }

        return response()->json(['data' => $saved, 'message' => count($saved) . ' preferences saved']);
    }

    /** Reset all preferences for current user to defaults (delete all rows). */
    public function reset(Request $request)
    {
        $user = $request->user();
        NotificationPreference::where('user_id', $user->id)->delete();
        // Re-seed from role defaults
        \App\Services\NotificationPreferenceDefaults::seedForUser($user);
        return response()->json(['message' => 'Preferences reset to defaults']);
    }
}
