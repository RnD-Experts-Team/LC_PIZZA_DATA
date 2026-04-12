<?php

use Illuminate\Support\Facades\Schedule;

// ════════════════════════════════════════════════════════════════════════════════════════════
// DATE HELPERS
// ════════════════════════════════════════════════════════════════════════════════════════════

$yesterday = now()->subDay()->toDateString();
$twoDaysAgo = now()->subDays(2)->toDateString();

// ════════════════════════════════════════════════════════════════════════════════════════════
// IMPORTS (BACKFILL - 3 PASSES)
// ════════════════════════════════════════════════════════════════════════════════════════════

// 9:20 AM ET
Schedule::command("import:backfill --start={$yesterday} --end={$yesterday}")
    ->dailyAt('09:20')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/import-backfill-morning.log'))
    ->name('import-backfill-morning');

// 1:00 PM ET
Schedule::command("import:backfill --start={$yesterday} --end={$yesterday}")
    ->dailyAt('13:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/import-backfill-afternoon.log'))
    ->name('import-backfill-afternoon');

// FINAL PASS (2 days ago)
Schedule::command("import:backfill --start={$twoDaysAgo} --end={$twoDaysAgo}")
    ->dailyAt('09:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/import-backfill-final.log'))
    ->name('import-backfill-final');

// ════════════════════════════════════════════════════════════════════════════════════════════
// AGGREGATIONS (REBUILD - AFTER FINAL IMPORT)
// ════════════════════════════════════════════════════════════════════════════════════════════

$aggStart = now()->subDays(2)->toDateString();
$aggEnd = now()->subDay()->toDateString();

// MORNING PASS: do hourly + daily together
Schedule::command("aggregation:rebuild --start={$aggStart} --end={$aggEnd} --stages=hourly,daily")
    ->dailyAt('10:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-daily-morning.log'))
    ->name('aggregation-daily-morning');

// AFTERNOON PASS: do hourly + daily together again
Schedule::command("aggregation:rebuild --start={$aggStart} --end={$aggEnd} --stages=hourly,daily")
    ->dailyAt('13:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-daily-afternoon.log'))
    ->name('aggregation-daily-afternoon');

// ════════════════════════════════════════════════════════════════════════════════════════════
// WEEKLY (EXACT STAGE ONLY)
// ════════════════════════════════════════════════════════════════════════════════════════════

$weeklyStart = now()->subWeeks(2)->startOfWeek()->toDateString();
$weeklyEnd = now()->subWeek()->endOfWeek()->toDateString();

// Tuesday
Schedule::command("aggregation:rebuild --start={$weeklyStart} --end={$weeklyEnd} --stages=weekly")
    ->tuesdays()
    ->at('03:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-weekly-primary.log'))
    ->name('aggregation-weekly-primary');

// Wednesday retry/pass
Schedule::command("aggregation:rebuild --start={$weeklyStart} --end={$weeklyEnd} --stages=weekly")
    ->wednesdays()
    ->at('03:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-weekly-secondary.log'))
    ->name('aggregation-weekly-secondary');

// ════════════════════════════════════════════════════════════════════════════════════════════
// MONTHLY (EXACT STAGE ONLY)
// ════════════════════════════════════════════════════════════════════════════════════════════

$monthlyStart = now()->subMonths(2)->startOfMonth()->toDateString();
$monthlyEnd = now()->subMonth()->endOfMonth()->toDateString();

Schedule::command("aggregation:rebuild --start={$monthlyStart} --end={$monthlyEnd} --stages=monthly")
    ->monthlyOn(1, '04:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-monthly-primary.log'))
    ->name('aggregation-monthly-primary');

Schedule::command("aggregation:rebuild --start={$monthlyStart} --end={$monthlyEnd} --stages=monthly")
    ->monthlyOn(2, '04:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-monthly-secondary.log'))
    ->name('aggregation-monthly-secondary');

// ════════════════════════════════════════════════════════════════════════════════════════════
// QUARTERLY (EXACT STAGE ONLY)
// ════════════════════════════════════════════════════════════════════════════════════════════

$quarterStart = now()->subQuarters(2)->startOfQuarter()->toDateString();
$quarterEnd = now()->subQuarter()->endOfQuarter()->toDateString();

// First day
Schedule::command("aggregation:rebuild --start={$quarterStart} --end={$quarterEnd} --stages=quarterly")
    ->quarterly()
    ->at('04:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-quarterly-primary.log'))
    ->name('aggregation-quarterly-primary');

// Second day
Schedule::command("aggregation:rebuild --start={$quarterStart} --end={$quarterEnd} --stages=quarterly")
    ->dailyAt('04:30')
    ->timezone('America/New_York')
    ->when(fn() => now()->isSameDay(now()->copy()->firstOfQuarter()->addDay()))
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-quarterly-secondary.log'))
    ->name('aggregation-quarterly-secondary');

// ════════════════════════════════════════════════════════════════════════════════════════════
// YEARLY (EXACT STAGE ONLY)
// ════════════════════════════════════════════════════════════════════════════════════════════

$yearlyStart = now()->subYears(2)->startOfYear()->toDateString();
$yearlyEnd = now()->subYear()->endOfYear()->toDateString();

// Jan 1
Schedule::command("aggregation:rebuild --start={$yearlyStart} --end={$yearlyEnd} --stages=yearly")
    ->yearlyOn(1, 1, '05:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-yearly-primary.log'))
    ->name('aggregation-yearly-primary');

// Jan 2
Schedule::command("aggregation:rebuild --start={$yearlyStart} --end={$yearlyEnd} --stages=yearly")
    ->yearlyOn(1, 2, '05:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/aggregation-yearly-secondary.log'))
    ->name('aggregation-yearly-secondary');

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