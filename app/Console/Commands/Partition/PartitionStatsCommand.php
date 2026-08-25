<?php

namespace App\Console\Commands\Partition;

use App\Services\Database\DatabaseRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Show partition statistics
 * 
 * Usage:
 *   php artisan partition:stats
 */
class PartitionStatsCommand extends Command
{
    protected $signature = 'partition:stats';

    protected $description = 'Show partition statistics';

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
        $this->info('  Partition Statistics');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->newLine();

        $cutoffDate = DatabaseRouter::getCutoffDate();
        $this->info("📅 Cutoff Date: {$cutoffDate->toDateString()} (data older than this is in archive)");
        $this->newLine();

        $tableData = [];

        foreach ($this->tables as $table) {
            $stats = DatabaseRouter::getDataDistribution($table);

            $tableData[] = [
                $table,
                number_format($stats['hot_rows']),
                number_format($stats['archive_rows']),
                number_format($stats['total_rows']),
                $stats['hot_percentage'] . '%',
            ];
        }

        $this->table(
            ['Table', 'Hot Rows', 'Archive Rows', 'Total Rows', 'Hot %'],
            $tableData
        );

        $this->newLine();

        // Storage estimates
        $this->info('💾 Storage Information:');
        $this->showStorageInfo('operational', 'detail_orders_hot');
        $this->showStorageInfo('archive', 'detail_orders_archive');
        $this->showStorageInfo('operational', 'summary_sales_hot');
        $this->showStorageInfo('archive', 'summary_sales_archive');
        $this->showStorageInfo('operational', 'financial_views_hot');
        $this->showStorageInfo('archive', 'financial_views_archive');
        $this->showStorageInfo('operational', 'alta_inventory_cogs_hot');
        $this->showStorageInfo('archive', 'alta_inventory_cogs_archive');
        $this->showStorageInfo('operational', 'alta_inventory_ingredient_usage_hot');
        $this->showStorageInfo('archive', 'alta_inventory_ingredient_usage_archive');
        $this->showStorageInfo('operational', 'alta_inventory_waste_hot');
        $this->showStorageInfo('archive', 'alta_inventory_waste_archive');
        $this->showStorageInfo('operational', 'cash_management_hot');
        $this->showStorageInfo('archive', 'cash_management_archive');
        $this->showStorageInfo('operational', 'waste_hot');
        $this->showStorageInfo('archive', 'waste_archive');
        $this->showStorageInfo('operational', 'order_line_hot');
        $this->showStorageInfo('archive', 'order_line_archive');
        $this->showStorageInfo('operational', 'summary_items_hot');
        $this->showStorageInfo('archive', 'summary_items_archive');
        $this->showStorageInfo('operational', 'summary_transactions_hot');
        $this->showStorageInfo('archive', 'summary_transactions_archive');

        return self::SUCCESS;
    }

    protected function showStorageInfo(string $connection, string $table): void
    {
        try {
            $result = DB::connection($connection)
                ->select("
                    SELECT 
                        ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb
                    FROM information_schema.TABLES 
                    WHERE TABLE_NAME = ?
                ", [$table]);

            if (!empty($result)) {
                $sizeMb = $result[0]->size_mb ?? 0;
                $this->line("  • {$table}: {$sizeMb} MB");
            }
        } catch (\Exception $e) {
            // Silently skip if can't get storage info
        }
    }
}
