<?php

namespace App\Console\Commands\Reports;

use App\Models\ReportRun;
use App\Reports\Engine\ReportRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes report runs past their `expires_at`, files first, and any staging
 * database whose run is no longer running (left by a worker that was killed).
 * Prints nothing.
 */
class PruneReportRuns extends Command
{
    protected $signature = 'reports:prune-runs';

    protected $description = 'Delete expired report runs and their files';

    public function handle(): int
    {
        ReportRun::query()->where('expires_at', '<', now())->lazyById(500)->each(function (ReportRun $run) {
            $run->deleteFiles();
            $run->delete();
        });

        $disk = Storage::disk('local');
        $staged = $disk->files(ReportRunner::STAGING_DIR);
        $running = ReportRun::query()->where('status', 'running')->pluck('id')->all();
        foreach ($staged as $path) {
            if (! in_array(basename($path, '.sqlite'), $running, true)) {
                $disk->delete($path);
            }
        }

        return self::SUCCESS;
    }
}
