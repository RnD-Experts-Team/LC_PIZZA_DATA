<?php

namespace App\Console\Commands\Validation;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Validate data integrity across databases
 * 
 * Usage:
 *   php artisan validation:check-data
 *   php artisan validation:check-data --date=2025-11-29
 *   php artisan validation:check-data --full
 */
class ValidateDataCommand extends Command
{
    protected $signature = 'validation:check-data 
                            {--date= : Validate specific date (Y-m-d format)}
                            {--full : Run full validation (comprehensive)}
                            {--fix : Attempt to fix issues automatically}';

    protected $description = 'Validate data integrity across databases';

    protected array $issues = [];
    protected array $warnings = [];

    public function handle(): int
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Data Validation');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $startTime = microtime(true);

        if ($this->option('date')) {
            $date = Carbon::parse($this->option('date'));
            $this->validateDate($date);
        } elseif ($this->option('full')) {
            $this->validateFull();
        } else {
            $this->validateRecent();
        }

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->displayResults($duration);

        if ($this->option('fix') && !empty($this->issues)) {
            $this->attemptFixes();
        }

        return empty($this->issues) ? self::SUCCESS : self::FAILURE;
    }

    protected function validateDate(Carbon $date): void
    {
        $this->info("📅 Validating data for: {$date->format('Y-m-d')}");
        $this->newLine();

        $this->checkRowCounts($date);
        $this->checkAggregationAccuracy($date);
        $this->checkMissingData($date);
    }

    protected function validateRecent(): void
    {
        $this->info('📅 Validating last 7 days');
        $this->newLine();

        for ($i = 1; $i <= 7; $i++) {
            $date = Carbon::now()->subDays($i);
            $this->line("Checking {$date->format('Y-m-d')}...");
            $this->checkRowCounts($date);
            $this->checkAggregationAccuracy($date);
        }
    }

    protected function validateFull(): void
    {
        $this->warn('🔍 Running FULL validation (may take several minutes)');
        $this->newLine();

        $this->info('1. Checking database connections...');
        $this->checkConnections();

        $this->info('2. Checking table structures...');
        $this->checkTableStructures();

        $this->info('3. Checking data consistency...');
        $this->checkDataConsistency();
    }

    protected function checkRowCounts(Carbon $date): void
    {
        $tables = ['detail_orders', 'order_line', 'summary_sales'];

        foreach ($tables as $table) {
            $hotCount = DB::connection('operational')
                ->table("{$table}_hot")
                ->where('business_date', $date->toDateString())
                ->count();

            $archiveCount = DB::connection('archive')
                ->table("{$table}_archive")
                ->where('business_date', $date->toDateString())
                ->count();

            $total = $hotCount + $archiveCount;

            if ($total === 0) {
                $this->warnings[] = "{$table}: No data for {$date->toDateString()}";
            }
        }
    }

    protected function checkAggregationAccuracy(Carbon $date): void
    {
        $sourceSales = DB::connection('operational')
            ->table('detail_orders_hot')
            ->where('business_date', $date->toDateString())
            ->sum('gross_sales');

        $aggregatedSales = DB::connection('aggregation')
            ->table('daily_store_summary')
            ->where('business_date', $date->toDateString())
            ->sum('royalty_obligation');

        $difference = abs($sourceSales - $aggregatedSales);

        if ($difference > 0.01) {
            $this->issues[] = "Sales mismatch on {$date->toDateString()}: Diff=\${$difference}";
        }
    }

    protected function checkMissingData(Carbon $date): void
    {
        $expectedStores = ['03795'];

        $actualStores = DB::connection('operational')
            ->table('detail_orders_hot')
            ->where('business_date', $date->toDateString())
            ->distinct()
            ->pluck('franchise_store');

        $missingStores = array_diff($expectedStores, $actualStores->toArray());

        if (!empty($missingStores)) {
            $this->issues[] = "Missing data on {$date->toDateString()} for stores: " . implode(', ', $missingStores);
        }
    }

    protected function checkConnections(): void
    {
        try {
            DB::connection('operational')->getPdo();
            $this->line('  ✓ Operational database OK');
        } catch (\Exception $e) {
            $this->issues[] = 'Cannot connect to operational database';
        }

        try {
            DB::connection('aggregation')->getPdo();
            $this->line('  ✓ Aggregation database OK');
        } catch (\Exception $e) {
            $this->issues[] = 'Cannot connect to aggregation database';
        }
    }

    protected function checkTableStructures(): void
    {
        $tables = ['detail_orders_hot', 'detail_orders_archive', 'daily_store_summary'];

        foreach ($tables as $table) {
            $connection = str_contains($table, 'summary') ? 'aggregation' :
                (str_contains($table, '_hot') ? 'operational' : 'aggregation');

            if (!$this->tableExists($connection, $table)) {
                $this->issues[] = "Missing table: {$table}";
            }
        }

        if (empty($this->issues)) {
            $this->line('  ✓ All required tables exist');
        }
    }

    protected function tableExists(string $connection, string $table): bool
    {
        try {
            DB::connection($connection)->table($table)->limit(1)->get();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function checkDataConsistency(): void
    {
        $orphaned = DB::connection('operational')
            ->table('order_line_hot as ol')
            ->leftJoin('detail_orders_hot as do', function ($join) {
                $join->on('ol.franchise_store', '=', 'do.franchise_store')
                    ->on('ol.business_date', '=', 'do.business_date')
                    ->on('ol.order_id', '=', 'do.order_id');
            })
            ->whereNull('do.order_id')
            ->count();

        if ($orphaned > 0) {
            $this->warnings[] = "{$orphaned} order_line records without matching detail_order";
        } else {
            $this->line('  ✓ No orphaned records');
        }
    }

    protected function displayResults(float $duration): void
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Validation Results');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        if (empty($this->issues) && empty($this->warnings)) {
            $this->info('✅ All checks passed!');
        } else {
            if (!empty($this->issues)) {
                $this->error('❌ Issues: ' . count($this->issues));
                foreach ($this->issues as $issue) {
                    $this->error('  • ' . $issue);
                }
            }

            if (!empty($this->warnings)) {
                $this->newLine();
                $this->warn('⚠️  Warnings: ' . count($this->warnings));
                foreach ($this->warnings as $warning) {
                    $this->warn('  • ' . $warning);
                }
            }
        }

        $this->newLine();
        $this->info("⏱️  Completed in {$duration} seconds");
    }

    protected function attemptFixes(): void
    {
        $this->newLine();
        $this->warn('🔧 Attempting automatic fixes...');
        $this->info('  (Fix logic to be implemented based on specific issues)');
    }
}
