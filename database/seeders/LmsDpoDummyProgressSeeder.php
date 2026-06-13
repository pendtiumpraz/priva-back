<?php

namespace Database\Seeders;

use App\Lms\Models\Badge;
use App\Lms\Models\Course;
use App\Lms\Models\Lesson;
use App\Lms\Models\Module;
use App\Lms\Models\OrgLeaderboard;
use App\Lms\Models\UserBadge;
use App\Lms\Models\UserBookmark;
use App\Lms\Models\UserLessonProgress;
use App\Lms\Models\UserModuleProgress;
use App\Lms\Models\UserNote;
use App\Lms\Models\XpLog;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seeds dummy LMS progress for demo users so the fe-nexus LMS side pages
 * (Dashboard, Badges, Leaderboard, Bookmarks, Notes, Progress) render with
 * real content out of the box.
 *
 * Target users:
 *   - budi.dpo@tester.co.id   (PT Tester Indonesia, role: dpo)
 *   - superadmin@privasimu.com (platform-level, org_id null — gets seeded
 *     against PT Tester org so superadmin can preview the same demo data
 *     once a tenant context is set in their session)
 *
 * Idempotent: every insert uses updateOrCreate keyed on the natural unique
 * index, so running `db:seed` repeatedly does not error or duplicate rows.
 *
 * Skips silently when users or course content have not been seeded yet —
 * this lets the seeder live inside DatabaseSeeder::call([...]) without
 * breaking a fresh-database first run (users are created later).
 */
class LmsDpoDummyProgressSeeder extends Seeder
{
    private const DPO_EMAIL        = 'budi.dpo@tester.co.id';
    private const SUPERADMIN_EMAIL = 'superadmin@privasimu.com';

    // PT Tester Indonesia's admin is seeded as `pendtiumpraz@gmail.com`
    // in DatabaseSeeder.php. We accept either alias for the leaderboard.
    private const ORG_EMAILS = [
        'admin'     => 'pendtiumpraz@gmail.com',
        'admin_alt' => 'admin@tester.co.id',
        'dpo'       => 'budi.dpo@tester.co.id',
        'maker'     => 'andi.maker@tester.co.id',
        'viewer'    => 'sari.viewer@tester.co.id',
    ];

    private const COURSE_SLUG = 'kepatuhan-uu-pdp-fundamentals';

    public function run(): void
    {
        $dpo = User::where('email', self::DPO_EMAIL)->first();
        if (! $dpo) {
            $this->command?->warn('LmsDpoDummyProgressSeeder: '.self::DPO_EMAIL.' not found, skipping.');
            return;
        }

        $orgId = $dpo->org_id;
        if (! $orgId) {
            $this->command?->warn('LmsDpoDummyProgressSeeder: '.self::DPO_EMAIL.' has no org_id, skipping.');
            return;
        }

        $course = Course::withoutGlobalScopes()
            ->where('slug', self::COURSE_SLUG)
            ->first();
        if (! $course) {
            $this->command?->warn('LmsDpoDummyProgressSeeder: course '.self::COURSE_SLUG.' missing, skipping.');
            return;
        }

        $modules = Module::where('course_id', $course->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get();
        if ($modules->count() < 2) {
            $this->command?->warn('LmsDpoDummyProgressSeeder: course needs at least 2 modules, skipping.');
            return;
        }

        $firstModule  = $modules[0];
        $secondModule = $modules[1];
        $thirdModule  = $modules[2] ?? $secondModule;

        $firstModuleLessons = Lesson::where('module_id', $firstModule->id)
            ->orderBy('order')
            ->orderBy('id')
            ->get();
        if ($firstModuleLessons->isEmpty()) {
            $this->command?->warn('LmsDpoDummyProgressSeeder: first module has no lessons, skipping.');
            return;
        }

        // ─── Seed progress for budi.dpo (PT Tester org) ───────────────────
        $dpoXp = $this->seedProgressForUser(
            user: $dpo,
            orgId: $orgId,
            firstModule: $firstModule,
            secondModule: $secondModule,
            thirdModule: $thirdModule,
            firstModuleLessons: $firstModuleLessons,
            label: 'dpo',
        );

        // ─── Seed progress for superadmin (against PT Tester org so the
        //     same /learn pages render data when superadmin acts as that
        //     tenant). Skip silently if user missing. ───────────────────
        $superXp = null;
        $superadmin = User::where('email', self::SUPERADMIN_EMAIL)->first();
        if ($superadmin) {
            $superXp = $this->seedProgressForUser(
                user: $superadmin,
                orgId: $orgId,
                firstModule: $firstModule,
                secondModule: $secondModule,
                thirdModule: $thirdModule,
                firstModuleLessons: $firstModuleLessons,
                label: 'superadmin',
            );
        } else {
            $this->command?->warn('LmsDpoDummyProgressSeeder: '.self::SUPERADMIN_EMAIL.' not found, skipping superadmin seed.');
        }

        // ─── Org leaderboard — 4 PT Tester rows + 1 superadmin row ─────
        $orgUsers = User::whereIn('email', array_values(self::ORG_EMAILS))->get()->keyBy('email');
        $adminUser = $orgUsers->get(self::ORG_EMAILS['admin_alt'])
            ?? $orgUsers->get(self::ORG_EMAILS['admin']);

        $leaderboardSpec = [
            'admin'      => ['user' => $adminUser,                                       'xp_total' => 500,                                'badges_count' => 4,                'courses_completed' => 2],
            'dpo'        => ['user' => $orgUsers->get(self::ORG_EMAILS['dpo']),          'xp_total' => $dpoXp['total'],                    'badges_count' => $dpoXp['badges'], 'courses_completed' => 0],
            'maker'      => ['user' => $orgUsers->get(self::ORG_EMAILS['maker']),        'xp_total' => 150,                                'badges_count' => 1,                'courses_completed' => 0],
            'viewer'     => ['user' => $orgUsers->get(self::ORG_EMAILS['viewer']),       'xp_total' => 80,                                 'badges_count' => 0,                'courses_completed' => 0],
            'superadmin' => ['user' => $superadmin,                                      'xp_total' => $superXp['total'] ?? 0,             'badges_count' => $superXp['badges'] ?? 0, 'courses_completed' => 0],
        ];

        $leaderboardRows = 0;
        foreach ($leaderboardSpec as $spec) {
            $user = $spec['user'];
            if (! $user) {
                continue;
            }
            OrgLeaderboard::updateOrCreate(
                ['org_id' => $orgId, 'user_id' => $user->id],
                [
                    'xp_total'          => $spec['xp_total'],
                    'badges_count'      => $spec['badges_count'],
                    'courses_completed' => $spec['courses_completed'],
                    'computed_at'       => Carbon::now(),
                ]
            );
            $leaderboardRows++;
        }

        $this->command?->info(sprintf(
            'LmsDpoDummyProgressSeeder: seeded for budi.dpo (%d xp) %s, %d leaderboard rows.',
            $dpoXp['total'],
            $superXp ? sprintf('+ superadmin (%d xp)', $superXp['total']) : '(superadmin skipped)',
            $leaderboardRows,
        ));
    }

    /**
     * Seed lesson progress, module progress, badges, bookmarks, notes,
     * and xp_log for one user. Returns ['total' => int xp, 'badges' => int].
     * Idempotent — uses updateOrCreate keyed on natural unique indexes.
     */
    private function seedProgressForUser(
        User $user,
        string $orgId,
        Module $firstModule,
        Module $secondModule,
        Module $thirdModule,
        Collection $firstModuleLessons,
        string $label,
    ): array {
        // 1) Lesson progress — 4 completed lessons in module #1
        $lessonsToComplete = $firstModuleLessons->take(4);
        $completedLessonIds = [];
        foreach ($lessonsToComplete as $idx => $lesson) {
            UserLessonProgress::updateOrCreate(
                ['user_id' => $user->id, 'lesson_id' => $lesson->id],
                [
                    'org_id'          => $orgId,
                    'completed_at'    => Carbon::now()->subDays($idx + 1)->subHours(2),
                    'watched_seconds' => 600 + ($idx * 420),
                ]
            );
            $completedLessonIds[] = $lesson->id;
        }

        // 2) Module progress — module #1 completed, module #2 in_progress
        UserModuleProgress::updateOrCreate(
            ['user_id' => $user->id, 'module_id' => $firstModule->id],
            [
                'org_id'       => $orgId,
                'status'       => 'completed',
                'started_at'   => Carbon::now()->subDays(7),
                'completed_at' => Carbon::now()->subDays(1),
                'score'        => 85,
            ]
        );
        UserModuleProgress::updateOrCreate(
            ['user_id' => $user->id, 'module_id' => $secondModule->id],
            [
                'org_id'       => $orgId,
                'status'       => 'in_progress',
                'started_at'   => Carbon::now()->subDays(2),
                'completed_at' => null,
                'score'        => null,
            ]
        );

        // 3) Badges — first 3 globally seeded badges
        $badgeSlugsToAward = ['first-lesson', 'learner-novice', 'xp-rookie'];
        $awardedBadgeCount = 0;
        foreach ($badgeSlugsToAward as $i => $slug) {
            $badge = Badge::where('slug', $slug)->first();
            if (! $badge) {
                continue;
            }
            UserBadge::updateOrCreate(
                ['user_id' => $user->id, 'badge_id' => $badge->id],
                [
                    'org_id'     => $orgId,
                    'awarded_at' => Carbon::now()->subDays(($i + 1) * 2),
                ]
            );
            $awardedBadgeCount++;
        }

        // 4) Bookmarks — 3 lessons across different modules
        $bookmarkLessons = collect();
        foreach ([$firstModule, $secondModule, $thirdModule] as $mod) {
            $candidate = Lesson::where('module_id', $mod->id)
                ->orderBy('order')
                ->orderBy('id')
                ->first();
            if ($candidate && ! $bookmarkLessons->contains('id', $candidate->id)) {
                $bookmarkLessons->push($candidate);
            }
        }
        if ($bookmarkLessons->count() < 3) {
            foreach ($firstModuleLessons as $lesson) {
                if ($bookmarkLessons->count() >= 3) break;
                if (! $bookmarkLessons->contains('id', $lesson->id)) {
                    $bookmarkLessons->push($lesson);
                }
            }
        }
        foreach ($bookmarkLessons->take(3) as $i => $lesson) {
            UserBookmark::updateOrCreate(
                ['user_id' => $user->id, 'lesson_id' => $lesson->id],
                [
                    'org_id'     => $orgId,
                    'created_at' => Carbon::now()->subDays($i + 1),
                    'updated_at' => Carbon::now()->subDays($i + 1),
                ]
            );
        }

        // 5) Notes — 2 lessons with realistic Indonesian DPO content
        $noteBodies = [
            'Catatan: prinsip purpose limitation berarti data pribadi hanya boleh diproses sesuai tujuan awal yang dideklarasikan ke subjek data. Wajib didokumentasikan di DPIA dan ROPA — kalau tujuan berubah, harus minta consent ulang atau dasar hukum baru sesuai Pasal 20 UU 27/2022.',
            'Reminder: hak subjek data di UU PDP mencakup akses, koreksi, penghapusan, penolakan pemrosesan otomatis, dan portabilitas. SLA respon kita harus < 72 jam kerja. Update playbook DSR + sosialisasi ke tim CS minggu depan.',
        ];
        $noteLessons = $firstModuleLessons->take(2)->values();
        foreach ($noteLessons as $i => $lesson) {
            if (! isset($noteBodies[$i])) break;
            UserNote::updateOrCreate(
                ['user_id' => $user->id, 'lesson_id' => $lesson->id],
                [
                    'org_id'     => $orgId,
                    'body'       => $noteBodies[$i],
                    'created_at' => Carbon::now()->subDays(2 - $i),
                    'updated_at' => Carbon::now()->subDays(2 - $i),
                ]
            );
        }

        // 6) XP log — match completed lessons + quiz events
        XpLog::where('user_id', $user->id)
            ->where('ref_type', 'lms_dpo_dummy_seeder')
            ->delete();

        $xpEntries = [];
        foreach ($completedLessonIds as $idx => $lessonId) {
            $xpEntries[] = [
                'action'     => 'lesson.completed',
                'xp_amount'  => 10,
                'ref_id'     => (string) $lessonId,
                'created_at' => Carbon::now()->subDays($idx + 1)->subHours(2),
            ];
        }
        $xpEntries[] = [
            'action'     => 'quiz.passed',
            'xp_amount'  => 50,
            'ref_id'     => 'module:'.$firstModule->id,
            'created_at' => Carbon::now()->subDays(1)->subHour(),
        ];
        $xpEntries[] = [
            'action'     => 'quiz.perfect',
            'xp_amount'  => 25,
            'ref_id'     => 'module:'.$firstModule->id,
            'created_at' => Carbon::now()->subDays(1)->subMinutes(30),
        ];
        $xpEntries[] = [
            'action'     => 'lesson.completed',
            'xp_amount'  => 10,
            'ref_id'     => 'module2-first-lesson',
            'created_at' => Carbon::now()->subHours(6),
        ];

        foreach ($xpEntries as $entry) {
            XpLog::create([
                'user_id'    => $user->id,
                'org_id'     => $orgId,
                'action'     => $entry['action'],
                'xp_amount'  => $entry['xp_amount'],
                'ref_type'   => 'lms_dpo_dummy_seeder',
                'ref_id'     => $entry['ref_id'],
                'created_at' => $entry['created_at'],
            ]);
        }

        return [
            'total'  => (int) collect($xpEntries)->sum('xp_amount'),
            'badges' => $awardedBadgeCount,
        ];
    }
}
