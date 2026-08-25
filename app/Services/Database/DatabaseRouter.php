<?php

namespace App\Services\Database;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DatabaseRouter
{
    protected const HOT_DATA_DAYS = 90;
    protected const CACHE_TTL = 3600; // 1 hour

    public static function getCutoffDate(): Carbon
    {
        return Carbon::now()->subDays(self::HOT_DATA_DAYS);
    }

    protected static function tableExists(string $connection, string $table): bool
    {
        $cacheKey = "table_exists_{$connection}_{$table}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($connection, $table) {
            try {
                return Schema::connection($connection)->hasTable($table);
            } catch (\Throwable $e) {
                Log::error("Schema check failed for {$connection}.{$table}: " . $e->getMessage());
                return false;
            }
        });
    }

    public static function archiveQuery(string $baseTable): ?Builder
    {
        $table = "{$baseTable}_archive";
        if (!self::tableExists('archive', $table)) {
            return null;
        }
        return DB::connection('archive')->table($table);
    }

    public static function hotQuery(string $baseTable): ?Builder
    {
        $table = "{$baseTable}_hot";
        if (!self::tableExists('operational', $table)) {
            return null;
        }
        return DB::connection('operational')->table($table);
    }

    /**
     * Returns optimized queries with proper indexing hints
     */
    public static function routedQueries(
        string $baseTable, 
        ?Carbon $startDate = null, 
        ?Carbon $endDate = null
    ): array {
        $cutoff = self::getCutoffDate();

        // Normalize open ended ranges
        if ($startDate && !$endDate) {
            $endDate = Carbon::now();
        }
        if (!$startDate && $endDate) {
            $startDate = Carbon::create(2000, 1, 1);
        }

        // CASE 0: no dates -> both, no date filter
        if (!$startDate && !$endDate) {

            $queries = array_values(array_filter([
                self::archiveQuery($baseTable),
                self::hotQuery($baseTable),
            ]));

            if (empty($queries)) {
                throw new \RuntimeException("No tables found for {$baseTable}");
            }

            return $queries;
        }

        // CASE 1: archive only
        if ($endDate < $cutoff) {

            $q = self::archiveQuery($baseTable);
            if (!$q) {
                throw new \RuntimeException("Missing archive table: {$baseTable}_archive");
            }

            return [
                $q->whereBetween('business_date', [
                    $startDate->toDateString(), 
                    $endDate->toDateString()
                ])
            ];
        }

        // CASE 2: hot only
        if ($startDate >= $cutoff) {

            $q = self::hotQuery($baseTable);
            if (!$q) {
                throw new \RuntimeException("Missing operational table: {$baseTable}_hot");
            }

            return [
                $q->whereBetween('business_date', [
                    $startDate->toDateString(), 
                    $endDate->toDateString()
                ])
            ];
        }

        // CASE 3: spans both

        $archive = self::archiveQuery($baseTable);
        $hot     = self::hotQuery($baseTable);

        $queries = [];

        if ($archive) {
            $queries[] = $archive->whereBetween('business_date', [
                $startDate->toDateString(),
                $cutoff->copy()->subDay()->toDateString(),
            ]);
        }

        if ($hot) {
            $queries[] = $hot->whereBetween('business_date', [
                $cutoff->toDateString(),
                $endDate->toDateString(),
            ]);
        }

        if (empty($queries)) {
            throw new \RuntimeException("No routed tables available for {$baseTable}");
        }

        return $queries;
    }

    public static function getDataDistribution(string $baseTable): array
    {
        $cacheKey = "data_dist_{$baseTable}";
        
        return Cache::remember($cacheKey, 300, function() use ($baseTable) {
            $cutoff = self::getCutoffDate();

            $hotCount = DB::connection('operational')
                ->table("{$baseTable}_hot")
                ->count();

            $archiveCount = DB::connection('archive')
                ->table("{$baseTable}_archive")
                ->count();

            $totalRows = $hotCount + $archiveCount;

            return [
                'table'              => $baseTable,
                'hot_rows'           => $hotCount,
                'archive_rows'       => $archiveCount,
                'total_rows'         => $totalRows,
                'cutoff_date'        => $cutoff->toDateString(),
                'hot_percentage'     => $totalRows > 0 ? round(($hotCount / $totalRows) * 100, 2) : 0,
                'archive_percentage' => $totalRows > 0 ? round(($archiveCount / $totalRows) * 100, 2) : 0,
            ];
        });
    }

    public static function getAllDataDistribution(): array
    {
        $tables = [
            'detail_orders', 'order_line', 'detail_transactions', 'summary_sales', 'summary_items',
            'summary_transactions', 'waste', 'cash_management', 'financial_views',
            'alta_inventory_cogs', 'alta_inventory_ingredient_orders',
            'alta_inventory_ingredient_usage', 'alta_inventory_waste'
        ];

        $stats = [];
        foreach ($tables as $table) {
            $stats[$table] = self::getDataDistribution($table);
        }

        return $stats;
    }

    /**
     * Clear distribution cache
     */
    public static function clearCache(string $baseTable = null): void
    {
        if ($baseTable) {
            Cache::forget("data_dist_{$baseTable}");
        } else {
            Cache::flush();
        }
    }
}
