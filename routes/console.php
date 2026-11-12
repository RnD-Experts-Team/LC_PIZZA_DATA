<?php

use Illuminate\Support\Facades\Schedule;

// ════════════════════════════════════════════════════════════════════════════════════════════
// DATE HELPERS
// ════════════════════════════════════════════════════════════════════════════════════════════

$yesterday = now()->subDay()->toDateString();
$sevenDaysAgo = now()->subDays(7)->toDateString();

// ════════════════════════════════════════════════════════════════════════════════════════════
// IMPORTS (BACKFILL - 3 PASSES, LAST 7 DAYS)
// ════════════════════════════════════════════════════════════════════════════════════════════

// 9:20 AM ET
Schedule::command("import:backfill --start={$sevenDaysAgo} --end={$yesterday}")
    ->dailyAt('09:20')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/import-backfill-morning.log'))
    ->name('import-backfill-morning');

// 1:00 PM ET
Schedule::command("import:backfill --start={$sevenDaysAgo} --end={$yesterday}")
    ->dailyAt('13:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/import-backfill-afternoon.log'))
    ->name('import-backfill-afternoon');

// FINAL PASS (last 7 days)
Schedule::command("import:backfill --start={$sevenDaysAgo} --end={$yesterday}")
    ->dailyAt('21:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/import-backfill-final.log'))
    ->name('import-backfill-final');

// ════════════════════════════════════════════════════════════════════════════════════════════
// AGGREGATIONS (REBUILD - AFTER FINAL IMPORT)
// ════════════════════════════════════════════════════════════════════════════════════════════

$aggStart = now()->subDays(7)->toDateString();
$aggEnd = now()->subDay()->toDateString();

// Every pass rebuilds the last 7 days and then every fiscal week, period, quarter and year they
// touch (App\Support\FiscalCalendar), in that order, so the summaries always equal the sum of
// the days — including the current period/quarter/year and any day a late import corrected.
$aggStages = 'hourly,daily,weekly,monthly,quarterly,yearly';

// MORNING PASS
Schedule::command("aggregation:rebuild --start={$aggStart} --end={$aggEnd} --stages={$aggStages}")
    ->dailyAt('10:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-daily-morning.log'))
    ->name('aggregation-daily-morning');

// AFTERNOON PASS
Schedule::command("aggregation:rebuild --start={$aggStart} --end={$aggEnd} --stages={$aggStages}")
    ->dailyAt('13:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-daily-afternoon.log'))
    ->name('aggregation-daily-afternoon');

// ════════════════════════════════════════════════════════════════════════════════════════════
// PARTITION SCHEDULES
// ════════════════════════════════════════════════════════════════════════════════════════════

Schedule::command('partition:archive-data')
    ->dailyAt('02:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/partition-archive.log'))
    ->name('partition-archive');

Schedule::command('partition:optimize --analyze')
    ->monthlyOn(1, '05:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/partition-optimize.log'))
    ->name('partition-optimize');

// ════════════════════════════════════════════════════════════════════════════════════════════
// VALIDATION SCHEDULES
// ════════════════════════════════════════════════════════════════════════════════════════════

Schedule::command('validation:check-data')
    ->dailyAt('11:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/validation-check.log'))
    ->name('validation-daily')
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Data validation failed - check logs');
    });

// ════════════════════════════════════════════════════════════════════════════════════════════
// MAINTENANCE SCHEDULES
// ════════════════════════════════════════════════════════════════════════════════════════════


Schedule::call(function () {
    $logPath = storage_path('logs');
    $files = glob("{$logPath}/*.log");
    $cutoff = now()->subDays(30);

    foreach ($files as $file) {
        if (filemtime($file) < $cutoff->timestamp) {
            @unlink($file);
        }
    }
})
    ->weekly()
    ->tuesdays()
    ->at('01:00')
    ->timezone('America/New_York')
    ->name('clear-old-logs');

Schedule::command('uploads:cleanup-temp')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/cleanup-temp-uploads.log'))
    ->name('cleanup-temp-uploads');
// ════════════════════════════════════════════════════════════════════════════════════════════
// KEY DUE NOTIFICATIONS
Schedule::command('keys:send-due-notifications')
    ->everyMinute()
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/key-due-notifications.log'))
    ->name('key-due-notifications');

// DATA OUTBOX PUBLISHER
Schedule::command('outbox:publish-pending')
    ->everyMinute()
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('data-publish-pending');

// REPORT BUILDER: runs and their files are kept 7 days
Schedule::command('reports:prune-runs')
    ->dailyAt('04:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('report-runs-prune');

// REPORT BUILDER: start the report schedules that are due
Schedule::command('reports:dispatch-due')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->name('report-schedules-dispatch');
