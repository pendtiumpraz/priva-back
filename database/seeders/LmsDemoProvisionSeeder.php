<?php

namespace Database\Seeders;

use App\Lms\Models\Course;
use App\Models\MenuItem;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Provisions everything a teammate needs to demo the LMS from a clean checkout,
 * which the base seeders do NOT cover:
 *
 *   1. A TenantModuleEntitlement row for the demo tenant, so org learners pass
 *      EnsureLmsEntitled instead of getting 403 LMS_NOT_ENTITLED.
 *   2. The dummy LMS progress (badges/XP/bookmarks/notes/leaderboard) — invoked
 *      here, AFTER users exist, because LmsDpoDummyProgressSeeder silently skips
 *      when its target users are missing.
 *
 * Must run after the org/user creation block in DatabaseSeeder. No-op in
 * production. Idempotent (updateOrCreate) — safe to re-run standalone:
 *   php artisan db:seed --class=Database\Seeders\LmsDemoProvisionSeeder
 *
 * NOTE — org-learner UI also requires an ACTIVE LICENSE (the FE shell redirects
 * unlicensed tenants to /license). Licenses are RS256-signed by the external
 * License Manager and cannot be minted offline, so this seeder does NOT create
 * one. Demo the full learner UI as a platform role (root/superadmin bypasses the
 * license + entitlement gates), or activate a real license for the tenant.
 */
class LmsDemoProvisionSeeder extends Seeder
{
    private const DEMO_DPO_EMAIL = 'budi.dpo@tester.co.id';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('LmsDemoProvisionSeeder: skipped in production.');
            return;
        }

        if (! config('lms.enabled', false)) {
            $this->command?->warn('LmsDemoProvisionSeeder: config lms.enabled is false — set LMS_ENABLED=true in .env or every /api/lms/* route will 503.');
        }

        $lmsMenu = MenuItem::where('menu_key', 'lms')->first();
        if (! $lmsMenu) {
            $this->command?->warn('LmsDemoProvisionSeeder: lms MenuItem missing (run LmsMenuSeeder first); skipping entitlement.');
        } else {
            // Entitle the demo tenant (PT Tester Indonesia — budi.dpo's org).
            $demoOrgId = User::where('email', self::DEMO_DPO_EMAIL)->value('org_id');
            if ($demoOrgId) {
                TenantModuleEntitlement::updateOrCreate(
                    ['org_id' => $demoOrgId, 'menu_id' => $lmsMenu->id],
                    ['is_entitled' => true, 'valid_until' => null],
                );
                $this->command?->info('LmsDemoProvisionSeeder: entitled demo tenant for LMS.');
            } else {
                $this->command?->warn('LmsDemoProvisionSeeder: '.self::DEMO_DPO_EMAIL.' not found; skipping entitlement.');
            }
        }

        // Seed dummy progress now that users + course content exist.
        if (Course::withoutGlobalScopes()->where('slug', 'kepatuhan-uu-pdp-fundamentals')->exists()) {
            $this->call(LmsDpoDummyProgressSeeder::class);
        } else {
            $this->command?->warn('LmsDemoProvisionSeeder: DPO Academy content missing (run LmsDpoAcademyContentSeeder first); skipping dummy progress.');
        }

        $this->attachSampleVideo();
    }

    /**
     * Attach a sample video to the first DPO lesson so the Mux/YouTube player is
     * demoable end-to-end. PLACEHOLDER public YouTube id — replace with real
     * unlisted-YouTube or Mux playback content via /learn/admin (Video picker).
     */
    private function attachSampleVideo(): void
    {
        $lesson = \App\Lms\Models\Lesson::where('slug', 'latar-belakang-dan-sejarah-uu-pdp')->first();
        if (! $lesson || $lesson->video_id) {
            return;
        }

        $video = \App\Lms\Models\Video::firstOrCreate(
            ['source' => 'youtube', 'external_id' => 'jNQXAC9IVRw'],
            [
                'playback_policy' => 'public',
                'duration_seconds' => 19,
                'uploaded_by' => \App\Models\User::where('email', self::DEMO_DPO_EMAIL)->value('id'),
            ],
        );
        $lesson->video_id = $video->id;
        $lesson->save();
        $this->command?->info('LmsDemoProvisionSeeder: attached sample YouTube video to first DPO lesson.');

        $this->attachMuxDemo();
    }

    /**
     * Optionally ingest a sample .mp4 into Mux as a SIGNED asset and attach it
     * to a second DPO lesson, so signed playback is demoable end-to-end.
     *
     * Off by default (LMS_SEED_MUX_DEMO) because it hits the live Mux API and
     * creates a new asset on every fresh seed. Requires valid Mux credentials.
     * Wrapped in try/catch so a seed never fails if Mux is down/misconfigured.
     */
    private function attachMuxDemo(): void
    {
        if (! filter_var(env('LMS_SEED_MUX_DEMO', false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $mux = app(\App\Lms\Services\MuxService::class);
        if (! $mux->configured()) {
            $this->command?->warn('LmsDemoProvisionSeeder: LMS_SEED_MUX_DEMO set but Mux not configured; skipping.');
            return;
        }

        $lesson = \App\Lms\Models\Lesson::where('slug', '!=', 'latar-belakang-dan-sejarah-uu-pdp')
            ->whereNull('video_id')
            ->orderBy('id')
            ->first();
        if (! $lesson) {
            return;
        }

        try {
            $result = $mux->ingestFromUrl('https://storage.googleapis.com/muxdemofiles/mux-video-intro.mp4');
            $video = \App\Lms\Models\Video::create([
                'source' => 'mux',
                'external_id' => $result['playback_id'],
                'playback_policy' => $result['policy'],
                'mux_asset_id' => $result['asset_id'],
                'duration_seconds' => null,
                'uploaded_by' => \App\Models\User::where('email', self::DEMO_DPO_EMAIL)->value('id'),
            ]);
            $lesson->video_id = $video->id;
            $lesson->save();
            $this->command?->info("LmsDemoProvisionSeeder: ingested Mux {$result['policy']} demo (asset {$result['asset_id']}) to lesson {$lesson->slug}.");
        } catch (\Throwable $e) {
            $this->command?->warn('LmsDemoProvisionSeeder: Mux demo ingest failed: '.$e->getMessage());
        }
    }
}
