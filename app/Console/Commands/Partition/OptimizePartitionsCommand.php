<?php

namespace App\Console\Commands\Partition;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Optimize database tables and partitions
 * 
 * Usage:
 *   php artisan partition:optimize
 *   php artisan partition:optimize --tables=detail_orders_hot,order_line_hot
 *   php artisan partition:optimize --analyze
 *   php artisan partition:optimize --rebuild-stats
 */
class OptimizePartitionsCommand extends Command
{
    protected $signature = 'partition:optimize 
                            {--tables= : Comma-separated list of tables to optimize}
                            {--analyze : Run ANALYZE TABLE as well}
                            {--rebuild-stats : Rebuild table statistics}';

    protected $description = 'Optimize database tables and partitions';

    protected array $defaultTables = [
        'detail_orders_hot',
        'order_line_hot',
        'detail_transactions_hot',
        'summary_sales_hot',
        'summary_items_hot',
        'summary_transactions_hot',
        'waste_hot',
        'cash_management_hot',
        'financial_views_hot',
        'alta_inventory_cogs_hot',
        'alta_inventory_ingredient_orders_hot',
        'alta_inventory_ingredient_usage_hot',
        'alta_inventory_waste_hot',
        'detail_orders_archive',
        'order_line_archive',
        'detail_transactions_archive',
        'summary_sales_archive',
        'summary_items_archive',
        'summary_transactions_archive',
        'waste_archive',
        'cash_management_archive',
        'financial_views_archive',
        'alta_inventory_cogs_archive',
        'alta_inventory_ingredient_orders_archive',
        'alta_inventory_ingredient_usage_archive',
        'alta_inventory_waste_archive',
    ];

    public function handle(): int
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Optimize Database Tables');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $tables = $this->getTablesToOptimize();
        $runAnalyze = $this->option('analyze');
        $rebuildStats = $this->option('rebuild-stats');

        $this->info("📊 Tables to optimize: " . count($tables));
        $this->newLine();

        $progressBar = $this->output->createProgressBar(count($tables));
        $progressBar->start();

        $optimized = 0;
        $failed = 0;

        foreach ($tables as $table) {
            try {
                $connection = str_contains($table, '_hot') ? 'operational' : 'archive';

                // Get table stats before optimization
                $sizeBefore = $this->getTableSize($connection, $table);

                // Run OPTIMIZE TABLE
                DB::connection($connection)
                    ->statement("OPTIMIZE TABLE {$table}");

                // Run ANALYZE TABLE if requested
                if ($runAnalyze) {
                    DB::connection($connection)
                        ->statement("ANALYZE TABLE {$table}");
                }

                // Rebuild statistics if requested
                if ($rebuildStats) {
                    $this->rebuildTableStats($connection, $table);
                }

                $sizeAfter = $this->getTableSize($connection, $table);
                $saved = $sizeBefore - $sizeAfter;


                $optimized++;
                $progressBar->advance();

            } catch (\Exception $e) {
                $failed++;
                $this->newLine();
                $this->error("Failed to optimize {$table}: " . $e->getMessage());
                Log::error("Optimization failed", [
                    'table' => $table,
                    'error' => $e->getMessage()
                ]);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info("✅ Optimized: {$optimized} tables");
        if ($failed > 0) {
            $this->error("❌ Failed: {$failed} tables");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function getTablesToOptimize(): array
    {
        if ($this->option('tables')) {
            return explode(',', $this->option('tables'));
        }

        return $this->defaultTables;
    }

    protected function getTableSize(string $connection, string $table): int
    {
        $result = DB::connection($connection)
            ->select("
                SELECT (data_length + index_length) as size
                FROM information_schema.TABLES
                WHERE table_schema = DATABASE()
                AND table_name = ?
            ", [$table]);

        return $result[0]->size ?? 0;
    }

    protected function rebuildTableStats(string $connection, string $table): void
    {
        // Force index statistics rebuild
        DB::connection($connection)
            ->statement("ALTER TABLE {$table} ENGINE=InnoDB");
    }
}
