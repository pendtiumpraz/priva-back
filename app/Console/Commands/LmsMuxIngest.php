<?php

namespace App\Console\Commands;

use App\Lms\Models\Lesson;
use App\Lms\Models\Video;
use App\Lms\Services\MuxService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Ingest a video URL into Mux and register it in the LMS video catalog,
 * optionally attaching it to a lesson.
 *
 *   php artisan lms:mux-ingest https://example.com/clip.mp4 --lesson=mengenal-dpo
 *
 * --policy   signed (default) | public
 * --lesson   lesson id or slug to attach the new video to
 * --duration optional duration_seconds to store
 */
class LmsMuxIngest extends Command
{
    protected $signature = 'lms:mux-ingest
        {url : Public mp4/HLS URL for Mux to ingest}
        {--policy= : signed (default) or public}
        {--lesson= : Lesson id or slug to attach the video to}
        {--duration= : duration_seconds to store on the video}';

    protected $description = 'Ingest a video URL into Mux and register it as an LMS video';

    public function handle(MuxService $mux): int
    {
        if (! $mux->configured()) {
            $this->error('Mux access token not configured (MUX_TOKEN_ID / MUX_TOKEN_SECRET).');
            return self::FAILURE;
        }

        $url = (string) $this->argument('url');
        $policy = (string) ($this->option('policy') ?: config('services.mux.default_playback_policy', 'signed'));

        $this->info("Ingesting into Mux ({$policy}): {$url}");

        try {
            $result = $mux->ingestFromUrl($url, $policy);
        } catch (\Throwable $e) {
            $this->error('Mux ingest failed: '.$e->getMessage());
            return self::FAILURE;
        }

        $video = Video::create([
            'source' => 'mux',
            'external_id' => $result['playback_id'],
            'playback_policy' => $result['policy'],
            'mux_asset_id' => $result['asset_id'],
            'duration_seconds' => $this->option('duration') ? (int) $this->option('duration') : null,
            'uploaded_by' => User::query()->whereNotNull('id')->value('id'),
        ]);

        $lessonRef = $this->option('lesson');
        $attached = null;
        if ($lessonRef) {
            $lesson = is_numeric($lessonRef)
                ? Lesson::find((int) $lessonRef)
                : Lesson::where('slug', $lessonRef)->first();

            if (! $lesson) {
                $this->warn("Lesson '{$lessonRef}' not found; video created but not attached.");
            } else {
                $lesson->video_id = $video->id;
                $lesson->save();
                $attached = $lesson->slug;
            }
        }

        $this->newLine();
        $this->table(['field', 'value'], [
            ['asset_id', $result['asset_id']],
            ['playback_id', $result['playback_id']],
            ['policy', $result['policy']],
            ['lms_video_id', $video->id],
            ['attached_lesson', $attached ?? '(none)'],
        ]);
        $this->info('Note: Mux needs a few seconds/minutes to finish encoding before playback works.');

        return self::SUCCESS;
    }
}
