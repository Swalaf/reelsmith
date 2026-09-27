<?php

namespace App\Console\Commands;

use App\Support\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StorageSync extends Command
{
    protected $signature = 'reelsmith:storage-sync {--prune-days= : Also delete local copies of renders and run outputs already in the bucket and older than this many days} {--limit=500}';

    protected $description = 'Copy generated media to cloud storage (S3 / R2 / Wasabi / B2) and optionally free local disk';

    public function handle(): int
    {
        if (! Media::enabled()) {
            $this->info('Cloud storage is not configured; nothing to do.');

            return self::SUCCESS;
        }
        $local = Storage::disk('public');
        $pushed = 0;
        foreach (Media::SYNC_DIRS as $dir) {
            foreach ($local->allFiles($dir) as $rel) {
                if ($pushed >= (int) $this->option('limit')) {
                    break 2;
                }
                if (str_contains($rel, '/tmp') || DB::table('cloud_files')->where('path', $rel)->exists()) {
                    continue;
                }
                if (Media::push($rel)) {
                    $pushed++;
                }
            }
        }
        $this->info("Pushed {$pushed} file(s).");

        if ($days = (int) $this->option('prune-days')) {
            $cut = now()->subDays($days)->timestamp;
            $freed = 0;
            foreach (['renders', 'runs'] as $dir) {
                foreach ($local->allFiles($dir) as $rel) {
                    if ($local->lastModified($rel) < $cut && DB::table('cloud_files')->where('path', $rel)->exists()) {
                        $freed += $local->size($rel);
                        $local->delete($rel);
                    }
                }
            }
            $this->info('Freed '.round($freed / 1048576, 1).' MB of local disk.');
        }

        return self::SUCCESS;
    }
}
