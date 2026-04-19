<?php

namespace App\Console\Commands;

use App\Models\ConsentCollectionPoint;
use App\Models\ConsentLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Periodic re-sync of consent_collection_points.records_count from the
 * authoritative consent_logs table. Replaces the old $collection->increment()
 * call in ConsentLogController::capture which serialized writes and bottlenecked
 * the public capture endpoint at high concurrency.
 *
 * Runs every 5 minutes by default via Console\Kernel. The count is advisory
 * (displayed in admin UI) — it doesn't need to be exact to the second.
 */
class RecountConsentRecords extends Command
{
    protected $signature = 'consent:recount {--collection=}';
    protected $description = 'Recompute records_count on consent_collection_points from consent_logs';

    public function handle(): int
    {
        $query = ConsentCollectionPoint::query();
        if ($this->option('collection')) {
            $query->where('id', $this->option('collection'))
                ->orWhere('collection_id', $this->option('collection'));
        }

        $updated = 0;
        $query->chunkById(200, function ($chunk) use (&$updated) {
            $ids = $chunk->pluck('id')->all();
            $counts = ConsentLog::select('collection_id', DB::raw('COUNT(*) as c'))
                ->whereIn('collection_id', $ids)
                ->groupBy('collection_id')
                ->pluck('c', 'collection_id');

            foreach ($chunk as $point) {
                $newCount = (int) ($counts[$point->id] ?? 0);
                if ($point->records_count !== $newCount) {
                    // Direct update to skip observers + avoid triggering cache bust
                    // (the count doesn't affect public /config payload).
                    ConsentCollectionPoint::withoutEvents(fn () => $point->update(['records_count' => $newCount]));
                    $updated++;
                }
            }
        });

        $this->info("Recount done. {$updated} collection(s) updated.");
        return self::SUCCESS;
    }
}
