<?php

namespace App\Lms\Services;

use App\Lms\Models\OrgLeaderboard;
use App\Lms\Models\UserBadge;
use App\Lms\Models\XpLog;
use App\Lms\Models\XpRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class XpAwardService
{
    public function award(User $user, string $actionKey, ?string $refType = null, ?string $refId = null): void
    {
        $rule = XpRule::where('action_key', $actionKey)->first();
        if (! $rule) return;

        DB::transaction(function () use ($user, $rule, $actionKey, $refType, $refId) {
            XpLog::create([
                'user_id' => $user->id,
                'org_id' => $user->org_id,
                'action' => $actionKey,
                'xp_amount' => $rule->xp_amount,
                'ref_type' => $refType,
                'ref_id' => $refId,
            ]);

            $totalXp = (int) XpLog::where('user_id', $user->id)
                ->where('org_id', $user->org_id)
                ->sum('xp_amount');

            $badgesCount = UserBadge::where('user_id', $user->id)
                ->where('org_id', $user->org_id)->count();

            $coursesCompleted = DB::table('lms_modules as m')
                ->whereIn('m.course_id', function ($q) use ($user) {
                    $q->select('m2.course_id')
                      ->from('lms_modules as m2')
                      ->leftJoin('lms_user_module_progress as p', function ($j) use ($user) {
                          $j->on('p.module_id', '=', 'm2.id')
                            ->where('p.user_id', '=', $user->id)
                            ->where('p.status', '=', 'completed');
                      })
                      ->groupBy('m2.course_id')
                      ->havingRaw('COUNT(m2.id) > 0 AND COUNT(m2.id) = COUNT(p.id)');
                })
                ->distinct()
                ->count('m.course_id');

            OrgLeaderboard::updateOrCreate(
                ['org_id' => $user->org_id, 'user_id' => $user->id],
                [
                    'xp_total' => $totalXp,
                    'badges_count' => $badgesCount,
                    'courses_completed' => $coursesCompleted,
                    'computed_at' => now(),
                ]
            );
        });
    }
}
