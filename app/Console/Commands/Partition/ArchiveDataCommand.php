<?php

namespace App\Console\Commands\Partition;

use App\Jobs\ArchiveDataJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Archive old data using background jobs
 * 
 * Usage:
 *   php artisan partition:archive-data
 *   php artisan partition:archive-data --cutoff-days=90
 *   php artisan partition:archive-data --batch-days=30 --verify
 */
class ArchiveDataCommand extends Command
{
    protected $signature = 'partition:archive-data 
                            {--cutoff-days=90 : Number of days to keep in operational DB}
                            {--table= : Archive specific table only}
                            {--batch-days=30 : Number of days to archive per batch}
                            {--verify : Verify data after archiving}
                            {--sync : Run synchronously (blocking, for testing)}';

    protected $description = 'Archive old data from operational to archive database (async)';

    protected array $tables = [
        'detail_orders',
        'order_line',
        'detail_transactions',
        'summary_sales',
        'summary_items',
        'summary_transactions',
        'waste',
        'cash_management',
        'financial_views',
        'alta_inventory_cogs',
        'alta_inventory_ingredient_orders',
        'alta_inventory_ingredient_usage',
        'alta_inventory_waste'
    ];

    public function handle(): int
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Archive Old Data (Background Jobs)');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        $cutoffDays = (int) $this->option('cutoff-days');
        $cutoffDate = Carbon::now()->subDays($cutoffDays);
        $specificTable = $this->option('table');
        $batchDays = (int) $this->option('batch-days');
        $verify = $this->option('verify');
        $sync = $this->option('sync');

        $this->info("📅 Cutoff Date: {$cutoffDate->format('Y-m-d')}");
        $this->info("🗄️  Archiving data older than {$cutoffDays} days");
        $this->info("📦 Batch Size: {$batchDays} days per job");
        $this->info("⚡ Mode: " . ($sync ? 'Synchronous (blocking)' : 'Asynchronous (background jobs)'));

        if ($verify) {
            $this->warn("🔍 Verification enabled (slower but safer)");
        }

        $this->newLine();

        $tablesToProcess = $specificTable 
            ? [$specificTable] 
            : $this->tables;

        // Scan all tables first
        $archivePlan = [];
        $totalJobs = 0;

        foreach ($tablesToProcess as $table) {
            $stats = $this->getArchiveStats($table, $cutoffDate);
            
            if ($stats['count'] === 0) {
                $this->line("  ⊘ {$table}: No data to archive");
                continue;
            }

            $archivePlan[$table] = $stats;
            $totalJobs += $stats['batches'];

            $this->info("  📊 {$table}: {$stats['count']} rows → {$stats['batches']} jobs");
        }

        if (empty($archivePlan)) {
            $this->warn('No data to archive');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(['Tables', 'Total Rows', 'Total Jobs'], [[
            count($archivePlan),
            array_sum(array_column($archivePlan, 'count')),
            $totalJobs
        ]]);
        $this->newLine();

        if (!$this->confirm('Dispatch archive jobs?', true)) {
            return self::SUCCESS;
        }

        // Dispatch jobs
        $archiveId = uniqid('archive_', true);
        
        Cache::put("archive_progress_{$archiveId}", [
            'status' => 'dispatching',
            'total_tables' => count($archivePlan),
            'completed_tables' => 0,
            'total_rows' => 0,
            'results' => [],
            'started_at' => now()->toISOString()
        ], 7200);

        $dispatched = 0;

        foreach ($archivePlan as $table => $stats) {
            $currentDate = $stats['min_date'];
            
            while ($currentDate <= $stats['max_date']) {
                $batchEnd = $currentDate->copy()->addDays($batchDays - 1);
                if ($batchEnd > $stats['max_date']) {
                    $batchEnd = $stats['max_date']->copy();
                }

                if ($sync) {
                    // Synchronous dispatch (for testing)
                    ArchiveDataJob::dispatchSync(
                        $archiveId,
                        $table,
                        $currentDate,
                        $batchEnd,
                        count($archivePlan),
                        $verify
                    );
                } else {
                    // Async dispatch to queue
                    ArchiveDataJob::dispatch(
                        $archiveId,
                        $table,
                        $currentDate,
                        $batchEnd,
                        count($archivePlan),
                        $verify
                    );
                }

                $dispatched++;
                $currentDate->addDays($batchDays);
            }
        }

        $this->newLine();
        $this->info("✅ Dispatched {$dispatched} archive jobs");
        $this->info("🆔 Archive ID: {$archiveId}");
        $this->newLine();

        if (!$sync) {
            $this->info("💡 Monitor progress:");
            $this->info("   • Start queue worker: php artisan queue:work --queue=archiving");
            $this->info("   • Check logs: tail -f storage/logs/laravel.log | grep {$archiveId}");
            $this->info("   • Progress cache key: archive_progress_{$archiveId}");
        }


        return self::SUCCESS;
    }

    protected function getArchiveStats(string $table, Carbon $cutoffDate): array
{
    $hotTable = "{$table}_hot";
    $batchDays = (int) $this->option('batch-days');

    $stats = DB::connection('operational')
        ->table($hotTable)
        ->where('business_date', '<', $cutoffDate->toDateString())
        ->selectRaw('COUNT(*) as count, MIN(business_date) as min_date, MAX(business_date) as max_date')
        ->first();

    if (!$stats || $stats->count === 0) {
        return ['count' => 0, 'batches' => 0, 'min_date' => null, 'max_date' => null];
    }

    $minDate = Carbon::parse($stats->min_date);
    $maxDate = Carbon::parse($stats->max_date);
    
    // Calculate total days (inclusive of start and end dates)
    $totalDays = $minDate->diffInDays($maxDate) + 1;
    
    // Calculate number of batches needed (minimum 1)
    $batches = max(1, (int) ceil($totalDays / $batchDays));

    return [
        'count' => (int) $stats->count,
        'min_date' => $minDate,
        'max_date' => $maxDate,
        'total_days' => $totalDays,
        'batches' => $batches
    ];
}

}
