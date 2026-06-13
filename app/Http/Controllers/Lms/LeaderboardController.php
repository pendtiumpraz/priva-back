<?php

namespace App\Http\Controllers\Lms;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    public function index(\Illuminate\Http\Request $r)
    {
        $user = $r->user();
        $orgId = $user->org_id;

        // Do NOT join users.name into the leaderboard query: users.name is
        // encrypted at rest via the EncryptedString cast on the User Eloquent
        // model. A raw DB join bypasses the cast and returns ciphertext, so
        // we resolve names through the model instead (one bulk Eloquent
        // fetch keyed by user_id, then look up per row).
        $rows = \App\Lms\Models\OrgLeaderboard::query()
            ->where('lms_org_leaderboard.org_id', $orgId)
            ->orderByDesc('lms_org_leaderboard.xp_total')
            ->orderBy('lms_org_leaderboard.user_id')
            ->get([
                'lms_org_leaderboard.user_id',
                'lms_org_leaderboard.xp_total',
                'lms_org_leaderboard.badges_count',
                'lms_org_leaderboard.courses_completed',
            ]);

        $userIds = $rows->pluck('user_id')->all();
        $userNames = \App\Models\User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name'])
            ->keyBy(fn ($u) => (string) $u->id)
            ->map(fn ($u) => $u->name); // accessor decrypts via EncryptedString cast

        $top = $rows->take(20)->values()->map(function ($row, $i) use ($userNames) {
            return [
                'user_id' => (string) $row->user_id,
                'user_name' => $userNames->get((string) $row->user_id) ?? '',
                'xp_total' => (int) $row->xp_total,
                'badges_count' => (int) $row->badges_count,
                'courses_completed' => (int) $row->courses_completed,
                'rank' => $i + 1,
            ];
        });

        $callerIndex = $rows->search(fn ($row) => (string) $row->user_id === (string) $user->id);
        if ($callerIndex !== false) {
            $row = $rows[$callerIndex];
            $currentUser = [
                'rank' => $callerIndex + 1,
                'xp_total' => (int) $row->xp_total,
                'badges_count' => (int) $row->badges_count,
                'courses_completed' => (int) $row->courses_completed,
                'in_top' => $callerIndex < 20,
            ];
        } else {
            $currentUser = [
                'rank' => $rows->count() + 1,
                'xp_total' => 0,
                'badges_count' => 0,
                'courses_completed' => 0,
                'in_top' => false,
            ];
        }

        return response()->json(['data' => ['top' => $top, 'current_user' => $currentUser]]);
    }
}
