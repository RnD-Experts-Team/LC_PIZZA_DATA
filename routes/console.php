<?php

use Illuminate\Support\Facades\Schedule;

/**
 * Laravel 12 Console Routes and Scheduling
 * 
 * Commands are auto-discovered from app/Console/Commands/
 * Schedules defined here replace the old Kernel.php
 * 
 * AGGREGATION CHAIN:
 * RAW DATA → HOURLY → DAILY → WEEKLY → MONTHLY → QUARTERLY → YEARLY
 */

// ════════════════════════════════════════════════════════════════════════════════════════════
// DATE HELPERS
// ════════════════════════════════════════════════════════════════════════════════════════════

$yesterday = now()->subDay()->toDateString();
$twoDaysAgo = now()->subDays(2)->toDateString();


// ════════════════════════════════════════════════════════════════════════════════════════════
// IMPORTS (BACKFILL - 3 PASSES)
// ════════════════════════════════════════════════════════════════════════════════════════════

// ─────────────────────────────
// DAY D+1 → TWO PASSES (yesterday)
// ─────────────────────────────

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


// ─────────────────────────────
// DAY D+2 → FINAL PASS (2 days ago)
// ─────────────────────────────

Schedule::command("import:backfill --start={$twoDaysAgo} --end={$twoDaysAgo}")
    ->dailyAt('09:00') // MUST run before aggregations
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


// ─────────────────────────────
// MORNING AGGREGATIONS
// ─────────────────────────────

Schedule::command("aggregation:rebuild --start={$aggStart} --end={$aggEnd} --type=hourly")
    ->dailyAt('10:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/aggregation-hourly.log'))
    ->name('aggregation-hourly-morning');

Schedule::command("aggregation:rebuild --start={$aggStart} --end={$aggEnd} --type=daily")
    ->dailyAt('10:45')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/aggregation-daily.log'))
    ->name('aggregation-daily-morning');


// ─────────────────────────────
// AFTERNOON AGGREGATIONS (SECOND PASS)
// ─────────────────────────────

Schedule::command("aggregation:rebuild --start={$aggStart} --end={$aggEnd} --type=hourly")
    ->dailyAt('13:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/aggregation-hourly-afternoon.log'))
    ->name('aggregation-hourly-afternoon');

Schedule::command("aggregation:rebuild --start={$aggStart} --end={$aggEnd} --type=daily")
    ->dailyAt('13:45')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/aggregation-daily-afternoon.log'))
    ->name('aggregation-daily-afternoon');


// ════════════════════════════════════════════════════════════════════════════════════════════
// WEEKLY (REBUILD - RUN + NEXT DAY)
// ════════════════════════════════════════════════════════════════════════════════════════════

$weeklyStart = now()->subWeeks(2)->startOfWeek()->toDateString();
$weeklyEnd = now()->subWeek()->endOfWeek()->toDateString();

// Tuesday
Schedule::command("aggregation:rebuild --start={$weeklyStart} --end={$weeklyEnd} --type=weekly")
    ->tuesdays()
    ->at('03:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('aggregation-weekly-primary');

// Wednesday (repeat)
Schedule::command("aggregation:rebuild --start={$weeklyStart} --end={$weeklyEnd} --type=weekly")
    ->wednesdays()
    ->at('03:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('aggregation-weekly-secondary');


// ════════════════════════════════════════════════════════════════════════════════════════════
// MONTHLY (REBUILD - 1st + 2nd)
// ════════════════════════════════════════════════════════════════════════════════════════════

$monthlyStart = now()->subMonths(2)->startOfMonth()->toDateString();
$monthlyEnd = now()->subMonth()->endOfMonth()->toDateString();

Schedule::command("aggregation:rebuild --start={$monthlyStart} --end={$monthlyEnd} --type=monthly")
    ->monthlyOn(1, '04:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('aggregation-monthly-primary');

Schedule::command("aggregation:rebuild --start={$monthlyStart} --end={$monthlyEnd} --type=monthly")
    ->monthlyOn(2, '04:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('aggregation-monthly-secondary');


// ════════════════════════════════════════════════════════════════════════════════════════════
// QUARTERLY (REBUILD - 1st DAY + NEXT DAY)
// ════════════════════════════════════════════════════════════════════════════════════════════

$quarterStart = now()->subQuarters(2)->startOfQuarter()->toDateString();
$quarterEnd = now()->subQuarter()->endOfQuarter()->toDateString();

// First day
Schedule::command("aggregation:rebuild --start={$quarterStart} --end={$quarterEnd} --type=quarterly")
    ->quarterly()
    ->at('04:30')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('aggregation-quarterly-primary');

// Second day
Schedule::command("aggregation:rebuild --start={$quarterStart} --end={$quarterEnd} --type=quarterly")
    ->dailyAt('04:30')
    ->timezone('America/New_York')
    ->when(fn() => now()->isSameDay(now()->copy()->firstOfQuarter()->addDay()))
    ->withoutOverlapping()
    ->onOneServer()
    ->name('aggregation-quarterly-secondary');


// ════════════════════════════════════════════════════════════════════════════════════════════
// YEARLY (REBUILD - JAN 1 + JAN 2)
// ════════════════════════════════════════════════════════════════════════════════════════════

$yearlyStart = now()->subYears(2)->startOfYear()->toDateString();
$yearlyEnd = now()->subYear()->endOfYear()->toDateString();

// Jan 1
Schedule::command("aggregation:rebuild --start={$yearlyStart} --end={$yearlyEnd} --type=yearly")
    ->yearlyOn(1, 1, '05:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('aggregation-yearly-primary');

// Jan 2
Schedule::command("aggregation:rebuild --start={$yearlyStart} --end={$yearlyEnd} --type=yearly")
    ->yearlyOn(1, 2, '05:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('aggregation-yearly-secondary');

// ════════════════════════════════════════════════════════════════════════════════════════════
// PARTITION SCHEDULES
// ════════════════════════════════════════════════════════════════════════════════════════════

// Archive old data daily at 2:00 AM ET (moves 91+ day old data)
Schedule::command('partition:archive-data')
    ->dailyAt('02:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/partition-archive.log'))
    ->name('partition-archive');

// Optimize tables monthly on 1st at 5:30 AM ET
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

// Validate data daily at 11:00 AM ET (after imports and aggregations)
Schedule::command('validation:check-data')
    ->dailyAt('11:00')
    ->timezone('America/New_York')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/validation-check.log'))
    ->name('validation-daily')
    ->onFailure(function () {
        \Illuminate\Support\Facades\Log::error('Data validation failed - check logs');
        // TODO: Send alert
    });

// ════════════════════════════════════════════════════════════════════════════════════════════
// MAINTENANCE SCHEDULES
// ════════════════════════════════════════════════════════════════════════════════════════════

// Clear old logs weekly (keep last 30 days)
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

// Cleanup temporary uploads every 5 minutes
Schedule::command('uploads:cleanup-temp')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/cleanup-temp-uploads.log'))
    ->name('cleanup-temp-uploads');

// ════════════════════════════════════════════════════════════════════════════════════════════
// SCHEDULER INFO
// ════════════════════════════════════════════════════════════════════════════════════════════

/**
 * To run the scheduler, add this to cron:
 * 
 * * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
 * 
 * Useful commands:
 *   php artisan schedule:list      # List all scheduled tasks
 *   php artisan schedule:work      # Run scheduler in foreground (testing)
 *   php artisan schedule:test      # Test scheduler without running
 * 
 * AGGREGATION CHAIN:
 *   RAW DATA → HOURLY → DAILY → WEEKLY → MONTHLY → QUARTERLY → YEARLY
 * 
 * Daily Timeline (Eastern Time):
 *   01:00 AM → Clear old logs (Tuesdays)
 *   02:00 AM → Archive old data (partition)
 *   03:00 AM → Update weekly aggregations (Tuesdays)
 *   04:00 AM → Update monthly aggregations (1st of month)
 *   04:30 AM → Update quarterly aggregations (1st day of quarter)
 *   05:00 AM → Update yearly aggregations (January 1st)
 *   05:30 AM → Optimize tables (1st of month)
 *   09:20 AM → Primary import (raw data)
 *   10:20 AM → Secondary import (catch late updates)
 *   10:30 AM → Update hourly aggregations (from raw data)
 *   10:45 AM → Update daily aggregations (from hourly)
 *   11:00 AM → Validate data
 *   Every 5m → Cleanup temporary uploads
 */
