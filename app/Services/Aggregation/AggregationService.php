<?php

namespace App\Services\Aggregation;

use App\Models\Aggregation\HourlyStoreSummary;
use App\Models\Aggregation\HourlyItemSummary;
use App\Models\Aggregation\DailyStoreSummary;
use App\Models\Aggregation\DailyItemSummary;
use App\Models\Aggregation\DailyOrderExtras;
use App\Models\Aggregation\WeeklyStoreSummary;
use App\Models\Aggregation\WeeklyItemSummary;
use App\Models\Aggregation\MonthlyStoreSummary;
use App\Models\Aggregation\MonthlyItemSummary;
use App\Models\Aggregation\QuarterlyStoreSummary;
use App\Models\Aggregation\QuarterlyItemSummary;
use App\Models\Aggregation\YearlyStoreSummary;
use App\Models\Aggregation\YearlyItemSummary;
use App\Services\Database\DatabaseRouter;
use App\Support\FiscalCalendar;
use App\Support\OrderBuckets;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AggregationService
{
    /** order_placed_method filters for the hourly channel metrics (same predicates the per-hour queries used). */
    private const HOURLY_ORDER_CHANNELS = [
        'phone' => "order_placed_method = 'Phone'",
        'website' => "order_placed_method = 'Website'",
        'mobile' => "order_placed_method = 'Mobile'",
        'call_center' => "order_placed_method IN ('SoundHoundAgent', 'CallCenterAgent')",
        'drive_thru' => "order_placed_method = 'Drive Thru'",
        'doordash' => "order_placed_method = 'DoorDash'",
        'ubereats' => "order_placed_method = 'UberEats'",
        'grubhub' => "order_placed_method = 'Grubhub'",
    ];

    /** menu_item_account values for the hourly delivery/carryout category splits. */
    private const HOURLY_CATEGORY_ACCOUNTS = [
        'pizza' => 'Pizza',
        'hnr' => 'HNR',
        'bread' => 'Bread',
        'wings' => 'Wings',
        'beverages' => 'Beverages',
        'other_foods' => 'Other Foods',
        'side_items' => 'Side Items',
    ];

    /**
     * Build a single query (as a subquery) that unions hot + archive for a base table,
     * filtered to the given date range (via DatabaseRouter).
     *
     * NOTE: Uses Query Builder (not Eloquent).
     */
    private function routedSource(string $baseTable, ?Carbon $startDate = null, ?Carbon $endDate = null): Builder
    {
        $queries = DatabaseRouter::routedQueries($baseTable, $startDate, $endDate);

        // Single routed table (always the case for a one-day range): query it directly on its own
        // connection instead of wrapping it in a derived table. Same rows, no materialization.
        if (count($queries) === 1) {
            return $queries[0];
        }

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        return DB::query()->fromSub($union, 'src');
    }

    private function replaceRow(string $modelClass, array $key, array $data): void
    {
        DB::transaction(function () use ($modelClass, $key, $data) {
            $modelClass::where($key)->delete();
            $modelClass::create($data);
        });
    }

    /**
     * Bulk equivalent of replaceRow() for many rows: one INSERT ... ON DUPLICATE KEY UPDATE per chunk
     * instead of a delete + insert round trip per row. Every column is overwritten and both timestamps
     * are refreshed, so the stored row is the same as a delete + create. Rows are filtered through the
     * model's $fillable exactly like Model::create() does.
     *
     * @return array{written:int, failed:array<int, array{row:array, error:string}>}
     */
    private function upsertRows(string $modelClass, array $rows, array $uniqueBy): array
    {
        if (empty($rows)) {
            return ['written' => 0, 'failed' => []];
        }

        $model = new $modelClass;
        $fillable = array_flip($model->getFillable());

        $columns = [];
        foreach ($rows as $i => $row) {
            $rows[$i] = array_intersect_key($row, $fillable);
            $columns += $rows[$i];
        }

        // Every row must carry the same column set; absent columns are NULL, as with create().
        $template = array_fill_keys(array_keys($columns), null);

        $update = array_keys($template);
        if ($model->usesTimestamps()) {
            $update[] = $model->getCreatedAtColumn();
        }

        $chunkSize = min(1000, max(1, intdiv(60000, count($template) + 2)));

        $written = 0;
        $failed = [];

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $chunk = array_map(fn(array $row) => array_replace($template, $row), $chunk);

            try {
                $modelClass::upsert($chunk, $uniqueBy, $update);
                $written += count($chunk);
            } catch (\Throwable $e) {
                // Isolate the bad row(s) so one failure does not discard the whole chunk.
                foreach ($chunk as $row) {
                    try {
                        $modelClass::upsert([$row], $uniqueBy, $update);
                        $written++;
                    } catch (\Throwable $rowError) {
                        $failed[] = ['row' => $row, 'error' => $rowError->getMessage()];
                    }
                }
            }
        }

        return ['written' => $written, 'failed' => $failed];
    }

    /**
     * Writes one store/period's item rows in bulk. Throws on any failed row, as the per-item
     * replaceRow() did, so the caller's per-store try/catch still counts the store as failed.
     */
    private function writeItemRows(string $modelClass, array $rows, array $uniqueBy): int
    {
        $write = $this->upsertRows($modelClass, $rows, $uniqueBy);

        if (!empty($write['failed'])) {
            throw new \RuntimeException(sprintf(
                '%d of %d item rows failed to write: %s',
                count($write['failed']),
                count($rows),
                $write['failed'][0]['error']
            ));
        }

        return $write['written'];
    }

    /** Stores per batch in the summary rebuild: bounds memory, never what is built. */
    private const STORES_PER_BATCH = 10;

    private function stageResult(
        string $stage,
        string $status,
        int $sourceRows = 0,
        int $rowsWritten = 0,
        int $storesFound = 0,
        int $storesFailed = 0,
        int $hoursFound = 0,
        int $hoursFailed = 0,
        ?string $message = null,
        array $extra = []
    ): array {
        return array_merge([
            'stage' => $stage,
            'status' => $status,
            'source_rows' => $sourceRows,
            'rows_written' => $rowsWritten,
            'stores_found' => $storesFound,
            'failed_stores' => $storesFailed,
            'hours_found' => $hoursFound,
            'failed_hours' => $hoursFailed,
            'message' => $message,
        ], $extra);
    }

    private function emptyStageResult(string $stage, string $message): array
    {
        return $this->stageResult(
            stage: $stage,
            status: 'skipped',
            sourceRows: 0,
            rowsWritten: 0,
            message: $message
        );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // PUBLIC ENTRY POINTS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Update hourly summaries from RAW transactional data (hot + archive)
     */
    public function updateHourlySummaries(Carbon $date, ?string $rebuildId = null): array
    {
        $dateStr = $date->toDateString();

        $source = $this->routedSource('detail_orders', $date, $date)
            ->where('business_date', $dateStr);

        $sourceRows = (clone $source)->count();

        if ($sourceRows === 0) {
            Log::info('Hourly stage skipped: no detail_orders rows', [
                'rebuild_id' => $rebuildId,
                'business_date' => $dateStr,
            ]);

            return $this->emptyStageResult('hourly', 'No raw detail_orders rows for date.');
        }

        $stores = (clone $source)
            ->distinct()
            ->pluck('franchise_store')
            ->filter(fn($store) => $store !== null && $store !== '')
            ->values();

        $storesFound = $stores->count();

        if ($storesFound === 0) {
            Log::warning('Hourly stage found source rows but no stores', [
                'rebuild_id' => $rebuildId,
                'business_date' => $dateStr,
                'source_rows' => $sourceRows,
            ]);

            return $this->stageResult(
                stage: 'hourly',
                status: 'failed',
                sourceRows: $sourceRows,
                rowsWritten: 0,
                storesFound: 0,
                storesFailed: 0,
                message: 'Raw rows exist but no franchise_store values were discovered.',
                extra: [
                    'business_date' => $dateStr,
                ]
            );
        }

        $rowsWritten = 0;
        $failedStores = 0;
        $hoursFound = 0;
        $failedHours = 0;

        foreach ($stores as $store) {
            try {
                $storeResult = $this->updateHourlyStoreSummary((string) $store, $date, $rebuildId);
                $itemResult = $this->updateHourlyItemSummary((string) $store, $date, $rebuildId);

                $rowsWritten += (int) ($storeResult['rows_written'] ?? 0);
                $rowsWritten += (int) ($itemResult['rows_written'] ?? 0);

                $hoursFound += (int) ($storeResult['hours_found'] ?? 0);
                $hoursFound += (int) ($itemResult['hours_found'] ?? 0);

                $failedHours += (int) ($storeResult['failed_hours'] ?? 0);
                $failedHours += (int) ($itemResult['failed_hours'] ?? 0);
            } catch (\Throwable $e) {
                $failedStores++;

                Log::error('Hourly aggregation failed for store', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $status = $failedStores > 0 || $failedHours > 0
            ? 'failed'
            : ($rowsWritten > 0 ? 'success' : 'failed');

        return $this->stageResult(
            stage: 'hourly',
            status: $status,
            sourceRows: $sourceRows,
            rowsWritten: $rowsWritten,
            storesFound: $storesFound,
            storesFailed: $failedStores,
            hoursFound: $hoursFound,
            hoursFailed: $failedHours,
            message: $status === 'success'
            ? 'Hourly stage completed.'
            : 'Hourly stage completed with failures or zero writes despite source rows.',
            extra: [
                'business_date' => $dateStr,
            ]
        );
    }

    /**
     * Update daily summaries from HOURLY data
     */
    public function updateDailySummaries(Carbon $date, ?string $rebuildId = null): array
    {
        $dateStr = $date->toDateString();

        $hourlyRows = HourlyStoreSummary::where('business_date', $dateStr)->count();

        if ($hourlyRows === 0) {
            Log::info('Daily stage skipped: no hourly rows', [
                'rebuild_id' => $rebuildId,
                'business_date' => $dateStr,
            ]);

            return $this->emptyStageResult('daily', 'No hourly summary rows for date.');
        }

        $stores = HourlyStoreSummary::where('business_date', $dateStr)
            ->distinct()
            ->pluck('franchise_store')
            ->filter(fn($store) => $store !== null && $store !== '')
            ->values();

        $storesFound = $stores->count();

        if ($storesFound === 0) {
            Log::warning('Daily stage found hourly rows but no stores', [
                'rebuild_id' => $rebuildId,
                'business_date' => $dateStr,
                'hourly_rows' => $hourlyRows,
            ]);

            return $this->stageResult(
                stage: 'daily',
                status: 'failed',
                sourceRows: $hourlyRows,
                rowsWritten: 0,
                storesFound: 0,
                storesFailed: 0,
                message: 'Hourly rows exist but no franchise_store values were discovered.',
                extra: [
                    'business_date' => $dateStr,
                ]
            );
        }

        $rowsWritten = 0;
        $failedStores = 0;

        foreach ($stores as $store) {
            try {
                $rowsWritten += $this->aggregateDailyFromHourly((string) $store, $date);
                $rowsWritten += $this->aggregateDailyItemsFromHourly((string) $store, $date);
            } catch (\Throwable $e) {
                $failedStores++;

                Log::error('Daily aggregation failed for store', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        // The day's order-level figures for every store at once.
        try {
            $rowsWritten += $this->aggregateDailyOrderExtras($date);
        } catch (\Throwable $e) {
            $failedStores++;

            Log::error('Daily order extras failed', [
                'rebuild_id' => $rebuildId,
                'business_date' => $dateStr,
                'exception' => $e->getMessage(),
            ]);
        }

        $status = $failedStores > 0
            ? 'failed'
            : ($rowsWritten > 0 ? 'success' : 'failed');

        return $this->stageResult(
            stage: 'daily',
            status: $status,
            sourceRows: $hourlyRows,
            rowsWritten: $rowsWritten,
            storesFound: $storesFound,
            storesFailed: $failedStores,
            message: $status === 'success'
            ? 'Daily stage completed.'
            : 'Daily stage completed with failures or zero writes despite hourly source rows.',
            extra: [
                'business_date' => $dateStr,
            ]
        );
    }

    /** The Tue–Mon business week of $date, rebuilt from daily. */
    public function updateWeeklySummaries(Carbon $date): array
    {
        [$start, $end] = FiscalCalendar::weekRange($date);

        return $this->updateWeeklySummariesRange($start->toMutable(), $end->toMutable());
    }

    /** The fiscal 4-week period of $date (the "monthly" tables), rebuilt from daily. */
    public function updateMonthlySummaries(Carbon $date): array
    {
        $at = FiscalCalendar::of($date);

        return $this->updateMonthlySummariesYearMonth($at['year'], $at['period']);
    }

    /** The fiscal quarter (3-3-3-4 periods) of $date, rebuilt from daily. */
    public function updateQuarterlySummaries(Carbon $date): array
    {
        $at = FiscalCalendar::of($date);

        return $this->updateQuarterlySummariesYearQuarter($at['year'], $at['quarter']);
    }

    /** The fiscal year of $date, rebuilt from daily. */
    public function updateYearlySummaries(Carbon $date): array
    {
        return $this->updateYearlySummariesYear(FiscalCalendar::yearOf($date));
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HOURLY AGGREGATION FROM RAW DATA (HOT + ARCHIVE)
    // ═══════════════════════════════════════════════════════════════════════════

    private function updateHourlyStoreSummary(string $store, Carbon $date, ?string $rebuildId = null): array
    {
        $dateStr = $date->toDateString();

        // One query per source table for the whole store/day, grouped by hour, instead of ~60 queries
        // per hour. The hours are the same DISTINCT HOUR(date_time_fulfilled) set as before.
        $orderGroups = $this->fetchHourlyOrderGroups($store, $date);
        $lineGroups = $this->fetchHourlyLineGroups($store, $date);

        $hoursFound = $orderGroups->count();
        $rows = [];
        $failedHours = 0;

        foreach ($orderGroups as $hour => $orders) {
            try {
                $rows[] = $this->aggregateHourlyStoreData($store, $dateStr, (int) $hour, $orders, $lineGroups->get($hour));
            } catch (\Throwable $e) {
                $failedHours++;

                Log::error('Hourly store aggregation failed for hour', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'hour' => (int) $hour,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $write = $this->upsertRows(HourlyStoreSummary::class, $rows, ['franchise_store', 'business_date', 'hour']);

        foreach ($write['failed'] as $failure) {
            $failedHours++;

            Log::error('Hourly store aggregation failed for hour', [
                'rebuild_id' => $rebuildId,
                'business_date' => $dateStr,
                'store' => (string) $store,
                'hour' => (int) ($failure['row']['hour'] ?? -1),
                'exception' => $failure['error'],
            ]);
        }

        return [
            'rows_written' => $write['written'],
            'hours_found' => $hoursFound,
            'failed_hours' => $failedHours,
        ];
    }

    /**
     * Every hourly store metric for one store/day in a single pass over detail_orders, grouped by hour.
     * Each column is exactly the aggregate the former per-hour query computed (same predicates, same
     * DISTINCT/row-count semantics). Keyed by hour, ascending; NULL hours are excluded as the old
     * DISTINCT HOUR() pluck excluded them.
     */
    private function fetchHourlyOrderGroups(string $store, Carbon $date): Collection
    {
        $select = [
            'HOUR(date_time_fulfilled) AS hour',
            'SUM(royalty_obligation) AS total_sales',
            'SUM(gross_sales) AS gross_sales',
            'SUM(COALESCE(taxable_amount, 0) + COALESCE(non_taxable_amount, 0)) AS net_sales',
            "SUM(CASE WHEN refunded = 'Yes' THEN royalty_obligation END) AS refund_amount",
            "SUM(CASE WHEN hnrOrder = 'Yes' THEN 1 ELSE 0 END) AS hnr_transactions",
            "SUM(CASE WHEN hnrOrder = 'Yes' AND broken_promise = 'Yes' THEN 1 ELSE 0 END) AS hnr_broken_promises",
            'COUNT(DISTINCT CASE WHEN customer_count > 0 THEN order_id END) AS total_orders',
            "COUNT(DISTINCT CASE WHEN refunded = 'Yes' THEN order_id END) AS refunded_orders",
            "COUNT(DISTINCT CASE WHEN override_approval_employee IS NOT NULL AND override_approval_employee != '' THEN order_id END) AS modified_orders",
            "COUNT(DISTINCT CASE WHEN transaction_type = 'Cancelled' THEN order_id END) AS cancelled_orders",
            'SUM(customer_count) AS customer_count',
            'SUM(sales_tax) AS sales_tax',
            'SUM(delivery_fee) AS delivery_fees',
            'SUM(delivery_tip) AS delivery_tips',
            'SUM(store_tip_amount) AS store_tips',
            "COUNT(DISTINCT CASE WHEN portal_eligible = 'Yes' THEN order_id END) AS portal_eligible",
            "COUNT(DISTINCT CASE WHEN portal_used = 'Yes' THEN order_id END) AS portal_used",
            "COUNT(DISTINCT CASE WHEN put_into_portal_before_promise_time = 'Yes' THEN order_id END) AS portal_on_time",
            // stripos($payment_methods, 'Cash') !== false: ASCII case-fold, byte match.
            "SUM(CASE WHEN CAST(LOWER(payment_methods) AS BINARY) LIKE '%cash%' THEN royalty_obligation END) AS cash_sales",
        ];

        foreach (self::HOURLY_ORDER_CHANNELS as $key => $condition) {
            $select[] = "COUNT(DISTINCT CASE WHEN {$condition} THEN order_id END) AS {$key}_orders";
            $select[] = "SUM(CASE WHEN {$condition} THEN royalty_obligation END) AS {$key}_sales";
        }

        return $this->routedSource('detail_orders', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $date->toDateString())
            ->selectRaw(implode(', ', $select))
            ->groupByRaw('HOUR(date_time_fulfilled)')
            ->orderBy('hour')
            ->get()
            ->filter(fn($row) => $row->hour !== null)
            ->keyBy(fn($row) => (int) $row->hour);
    }

    /**
     * The 28 delivery/carryout category splits for one store/day in a single pass over order_line,
     * grouped by hour. Same predicates as the former per-category queries:
     *   delivery = order_fulfilled_method = 'Delivery'
     *   carryout = order_fulfilled_method IS NULL OR != 'Delivery'
     */
    private function fetchHourlyLineGroups(string $store, Carbon $date): Collection
    {
        $delivery = "order_fulfilled_method = 'Delivery'";
        $carryout = "(order_fulfilled_method IS NULL OR order_fulfilled_method != 'Delivery')";

        $select = ['HOUR(date_time_fulfilled) AS hour'];

        foreach (self::HOURLY_CATEGORY_ACCOUNTS as $key => $account) {
            $select[] = "SUM(CASE WHEN {$delivery} AND menu_item_account = '{$account}' THEN quantity END) AS {$key}_delivery_quantity";
            $select[] = "SUM(CASE WHEN {$delivery} AND menu_item_account = '{$account}' THEN net_amount END) AS {$key}_delivery_sales";
            $select[] = "SUM(CASE WHEN {$carryout} AND menu_item_account = '{$account}' THEN quantity END) AS {$key}_carryout_quantity";
            $select[] = "SUM(CASE WHEN {$carryout} AND menu_item_account = '{$account}' THEN net_amount END) AS {$key}_carryout_sales";
        }

        return $this->routedSource('order_line', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $date->toDateString())
            ->selectRaw(implode(', ', $select))
            ->groupByRaw('HOUR(date_time_fulfilled)')
            ->get()
            ->filter(fn($row) => $row->hour !== null)
            ->keyBy(fn($row) => (int) $row->hour);
    }

    /**
     * Hourly store summary:
     * - Category splits (delivery vs carryout) are driven by:
     *   menu_item_account in (Pizza/HNR/Bread/Wings/Beverages/Other Foods/Side Items)
     *   delivery = order_fulfilled_method == "Delivery"
     *   carryout = order_fulfilled_method != "Delivery" (including NULL)
     * - delivery_orders/sales and carryout_orders/sales are sums of the category splits
     * - cash_sales hourly is estimated; daily overrides with financial_views "Total Cash Sales"
     *
     * Builds the row for one store/date/hour from the pre-aggregated groups ($orders from
     * fetchHourlyOrderGroups(), $lines from fetchHourlyLineGroups(); $lines is null when the hour has
     * no order lines, which yields the same zero splits the per-category queries returned).
     */
    private function aggregateHourlyStoreData(string $store, string $date, int $hour, object $orders, ?object $lines = null): array
    {
        $totalSales = (float) $orders->total_sales;
        $grossSales = (float) $orders->gross_sales;

        $netSales = (float) $orders->net_sales;

        $refundAmount = (float) $orders->refund_amount;

        $hnrTransactions = (int) $orders->hnr_transactions;
        $hnrBrokenPromises = (int) $orders->hnr_broken_promises;

        $totalOrders = (int) $orders->total_orders;

        $refundedOrders = (int) $orders->refunded_orders;

        $modifiedOrders = (int) $orders->modified_orders;

        $cancelledOrders = (int) $orders->cancelled_orders;

        $customerCount = (int) $orders->customer_count;

        $phoneOrders = (int) $orders->phone_orders;
        $phoneSales = (float) $orders->phone_sales;

        $websiteOrders = (int) $orders->website_orders;
        $websiteSales = (float) $orders->website_sales;

        $mobileOrders = (int) $orders->mobile_orders;
        $mobileSales = (float) $orders->mobile_sales;

        $callCenterOrders = (int) $orders->call_center_orders;
        $callCenterSales = (float) $orders->call_center_sales;

        $driveThruOrders = (int) $orders->drive_thru_orders;
        $driveThruSales = (float) $orders->drive_thru_sales;

        $doordashOrders = (int) $orders->doordash_orders;
        $doordashSales = (float) $orders->doordash_sales;

        $ubereatsOrders = (int) $orders->ubereats_orders;
        $ubereatsSales = (float) $orders->ubereats_sales;

        $grubhubOrders = (int) $orders->grubhub_orders;
        $grubhubSales = (float) $orders->grubhub_sales;

        $salesTax = (float) $orders->sales_tax;
        $deliveryFees = (float) $orders->delivery_fees;
        $deliveryTips = (float) $orders->delivery_tips;
        $storeTips = (float) $orders->store_tips;

        $portalEligible = (int) $orders->portal_eligible;
        $portalUsed = (int) $orders->portal_used;
        $portalOnTime = (int) $orders->portal_on_time;

        $split = [];
        $deliveryQtyTotal = 0;
        $deliverySalesTotal = 0.0;
        $carryoutQtyTotal = 0;
        $carryoutSalesTotal = 0.0;

        foreach (self::HOURLY_CATEGORY_ACCOUNTS as $key => $account) {
            $dQty = (int) ($lines?->{"{$key}_delivery_quantity"} ?? 0);
            $dSales = (float) ($lines?->{"{$key}_delivery_sales"} ?? 0);

            $cQty = (int) ($lines?->{"{$key}_carryout_quantity"} ?? 0);
            $cSales = (float) ($lines?->{"{$key}_carryout_sales"} ?? 0);

            $split[$key] = [
                'dQty' => $dQty,
                'dSales' => $dSales,
                'cQty' => $cQty,
                'cSales' => $cSales,
            ];

            $deliveryQtyTotal += $dQty;
            $deliverySalesTotal += $dSales;
            $carryoutQtyTotal += $cQty;
            $carryoutSalesTotal += $cSales;
        }

        $cashSales = (float) $orders->cash_sales;

        $overShort = 0.0;

        $digitalOrders = $websiteOrders + $mobileOrders;
        $digitalSales = $websiteSales + $mobileSales;

        $data = [
            'franchise_store' => $store,
            'business_date' => $date,
            'hour' => $hour,

            'royalty_obligation' => round($totalSales, 2),
            'gross_sales' => round($grossSales, 2),
            'net_sales' => round((float) $netSales, 2),
            'refund_amount' => round($refundAmount, 2),

            'total_orders' => $totalOrders,
            'completed_orders' => max(0, $totalOrders - $refundedOrders - $cancelledOrders),
            'cancelled_orders' => $cancelledOrders,
            'modified_orders' => $modifiedOrders,
            'refunded_orders' => $refundedOrders,
            'avg_order_value' => $totalOrders > 0 ? round($totalSales / $totalOrders, 2) : 0,
            'customer_count' => $customerCount,

            'phone_orders' => $phoneOrders,
            'phone_sales' => round($phoneSales, 2),
            'website_orders' => $websiteOrders,
            'website_sales' => round($websiteSales, 2),
            'mobile_orders' => $mobileOrders,
            'mobile_sales' => round($mobileSales, 2),
            'call_center_orders' => $callCenterOrders,
            'call_center_sales' => round($callCenterSales, 2),
            'drive_thru_orders' => $driveThruOrders,
            'drive_thru_sales' => round($driveThruSales, 2),

            'doordash_orders' => $doordashOrders,
            'doordash_sales' => round($doordashSales, 2),
            'ubereats_orders' => $ubereatsOrders,
            'ubereats_sales' => round($ubereatsSales, 2),
            'grubhub_orders' => $grubhubOrders,
            'grubhub_sales' => round($grubhubSales, 2),

            'delivery_orders' => $deliveryQtyTotal,
            'delivery_sales' => round($deliverySalesTotal, 2),
            'carryout_orders' => $carryoutQtyTotal,
            'carryout_sales' => round($carryoutSalesTotal, 2),

            'pizza_delivery_quantity' => $split['pizza']['dQty'],
            'pizza_delivery_sales' => round($split['pizza']['dSales'], 2),
            'pizza_carryout_quantity' => $split['pizza']['cQty'],
            'pizza_carryout_sales' => round($split['pizza']['cSales'], 2),

            'hnr_delivery_quantity' => $split['hnr']['dQty'],
            'hnr_delivery_sales' => round($split['hnr']['dSales'], 2),
            'hnr_carryout_quantity' => $split['hnr']['cQty'],
            'hnr_carryout_sales' => round($split['hnr']['cSales'], 2),

            'bread_delivery_quantity' => $split['bread']['dQty'],
            'bread_delivery_sales' => round($split['bread']['dSales'], 2),
            'bread_carryout_quantity' => $split['bread']['cQty'],
            'bread_carryout_sales' => round($split['bread']['cSales'], 2),

            'wings_delivery_quantity' => $split['wings']['dQty'],
            'wings_delivery_sales' => round($split['wings']['dSales'], 2),
            'wings_carryout_quantity' => $split['wings']['cQty'],
            'wings_carryout_sales' => round($split['wings']['cSales'], 2),

            'beverages_delivery_quantity' => $split['beverages']['dQty'],
            'beverages_delivery_sales' => round($split['beverages']['dSales'], 2),
            'beverages_carryout_quantity' => $split['beverages']['cQty'],
            'beverages_carryout_sales' => round($split['beverages']['cSales'], 2),

            'other_foods_delivery_quantity' => $split['other_foods']['dQty'],
            'other_foods_delivery_sales' => round($split['other_foods']['dSales'], 2),
            'other_foods_carryout_quantity' => $split['other_foods']['cQty'],
            'other_foods_carryout_sales' => round($split['other_foods']['cSales'], 2),

            'side_items_delivery_quantity' => $split['side_items']['dQty'],
            'side_items_delivery_sales' => round($split['side_items']['dSales'], 2),
            'side_items_carryout_quantity' => $split['side_items']['cQty'],
            'side_items_carryout_sales' => round($split['side_items']['cSales'], 2),

            'sales_tax' => round($salesTax, 2),
            'delivery_fees' => round($deliveryFees, 2),
            'delivery_tips' => round($deliveryTips, 2),
            'store_tips' => round($storeTips, 2),
            'total_tips' => round($deliveryTips + $storeTips, 2),

            'cash_sales' => round($cashSales, 2),
            'over_short' => round($overShort, 2),

            'portal_eligible_orders' => $portalEligible,
            'portal_used_orders' => $portalUsed,
            'portal_on_time_orders' => $portalOnTime,
            'portal_usage_rate' => $portalEligible > 0 ? round(($portalUsed / $portalEligible) * 100, 2) : 0,
            'portal_on_time_rate' => $portalUsed > 0 ? round(($portalOnTime / $portalUsed) * 100, 2) : 0,

            'digital_orders' => $digitalOrders,
            'digital_sales' => round($digitalSales, 2),
            'digital_penetration' => $totalOrders > 0 ? round(($digitalOrders / $totalOrders) * 100, 2) : 0,

            'hnr_transactions' => $hnrTransactions,
            'hnr_broken_promises' => $hnrBrokenPromises,
        ];

        return $data;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HOURLY ITEM AGGREGATION FROM RAW LINES (HOT + ARCHIVE)
    // ═══════════════════════════════════════════════════════════════════════════

    private function updateHourlyItemSummary(string $store, Carbon $date, ?string $rebuildId = null): array
    {
        $dateStr = $date->toDateString();

        // One query for the whole store/day, grouped by hour + item, instead of a row fetch per hour
        // and a delete + insert per item. The hours are the same DISTINCT HOUR() set as before.
        $groupsByHour = $this->fetchHourlyItemGroups($store, $date);

        $hoursFound = $groupsByHour->count();
        $rows = [];
        $rowsWritten = 0;
        $failedHours = 0;

        foreach ($groupsByHour as $hour => $groups) {
            try {
                [$hourRows, $groupCount] = $this->aggregateHourlyItemData($store, $dateStr, (int) $hour, $groups);

                $rows = array_merge($rows, $hourRows);
                $rowsWritten += $groupCount;
            } catch (\Throwable $e) {
                $failedHours++;

                Log::error('Hourly item aggregation failed for hour', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'hour' => (int) $hour,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $write = $this->upsertRows(HourlyItemSummary::class, $rows, ['franchise_store', 'business_date', 'hour', 'item_id']);

        if (!empty($write['failed'])) {
            $failedHourSet = [];

            foreach ($write['failed'] as $failure) {
                $failedHourSet[(int) ($failure['row']['hour'] ?? -1)] = true;

                Log::error('Hourly item aggregation failed for hour', [
                    'rebuild_id' => $rebuildId,
                    'business_date' => $dateStr,
                    'store' => (string) $store,
                    'hour' => (int) ($failure['row']['hour'] ?? -1),
                    'item_id' => $failure['row']['item_id'] ?? null,
                    'exception' => $failure['error'],
                ]);
            }

            $failedHours += count($failedHourSet);
            $rowsWritten -= count($write['failed']);
        }

        return [
            'rows_written' => max(0, $rowsWritten),
            'hours_found' => $hoursFound,
            'failed_hours' => $failedHours,
        ];
    }

    /**
     * All item groups for one store/day in a single pass over order_line, grouped by
     * hour + item_id + menu_item_name + menu_item_account (the same grouping key as before).
     *
     * The filters were previously applied in PHP on the fetched rows (==, !==, empty()), i.e. byte-exact,
     * so they are expressed with CAST(... AS BINARY) here rather than the collation-insensitive
     * comparison used by the store-level queries:
     *   net_sales          rows where empty(modification_reason)      -> NULL, '' or '0'
     *   delivery_quantity  rows where order_fulfilled_method == 'Delivery'
     *   carryout_quantity  rows where empty(order_fulfilled_method) || !== 'Delivery'
     *   modified_quantity  rows where !empty(modified_order_amount)   -> DECIMAL comes back as a string
     *                      ("0.00" is not empty), so only NULL is empty
     *   refunded_quantity  rows where refunded == 'Yes'
     *
     * The grouping mirrors the old PHP string key "item_id|name|account": byte-exact (CAST ... AS BINARY)
     * with NULL and '' collapsed together (COALESCE); MIN() just returns the group's single value under
     * ONLY_FULL_GROUP_BY. Groups come back in first-appearance order (MIN(id)), which is the order the
     * old code iterated them in, so "last group wins" for a duplicate item_id resolves the same way.
     *
     * gross_sales is summed as DOUBLE on purpose: the old code summed the fetched strings as PHP floats
     * and divided that float for avg_item_price, so the same floating-point accumulation is needed for
     * the same rounding at half-cent boundaries.
     *
     * Keyed by hour, ascending; NULL hours are excluded as the old DISTINCT HOUR() pluck excluded them.
     */
    private function fetchHourlyItemGroups(string $store, Carbon $date): Collection
    {
        $select = [
            'HOUR(date_time_fulfilled) AS hour',
            'MIN(item_id) AS item_id',
            'MIN(menu_item_name) AS menu_item_name',
            'MIN(menu_item_account) AS menu_item_account',
            'SUM(quantity) AS quantity_sold',
            'SUM(net_amount + 0e0) AS gross_sales',
            "SUM(CASE WHEN modification_reason IS NULL OR CAST(modification_reason AS BINARY) IN ('', '0') THEN net_amount END) AS net_sales",
            "SUM(CASE WHEN CAST(order_fulfilled_method AS BINARY) = 'Delivery' THEN quantity END) AS delivery_quantity",
            "SUM(CASE WHEN order_fulfilled_method IS NULL OR CAST(order_fulfilled_method AS BINARY) != 'Delivery' THEN quantity END) AS carryout_quantity",
            'SUM(CASE WHEN modified_order_amount IS NOT NULL THEN quantity END) AS modified_quantity',
            "SUM(CASE WHEN CAST(refunded AS BINARY) = 'Yes' THEN quantity END) AS refunded_quantity",
        ];

        return $this->routedSource('order_line', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $date->toDateString())
            ->selectRaw(implode(', ', $select))
            ->groupByRaw(
                'HOUR(date_time_fulfilled), '
                . "COALESCE(CAST(item_id AS BINARY), ''), "
                . "COALESCE(CAST(menu_item_name AS BINARY), ''), "
                . "COALESCE(CAST(menu_item_account AS BINARY), '')"
            )
            ->orderBy('hour')
            ->orderByRaw('MIN(id)')
            ->get()
            ->filter(fn($row) => $row->hour !== null)
            ->groupBy(fn($row) => (int) $row->hour);
    }

    /**
     * Builds the hourly item rows for one store/date/hour from the pre-aggregated groups.
     *
     * @return array{0: array<int, array>, 1: int} [rows to write, number of item groups]
     */
    private function aggregateHourlyItemData(string $store, string $date, int $hour, Collection $groups): array
    {
        $rows = [];
        $groupCount = 0;

        foreach ($groups as $group) {
            $groupCount++;

            $qty = (float) $group->quantity_sold;
            $gross = (float) $group->gross_sales;

            $data = [
                'franchise_store' => $store,
                'business_date' => $date,
                'hour' => $hour,
                'item_id' => $group->item_id,
                'menu_item_name' => $group->menu_item_name,
                'menu_item_account' => $group->menu_item_account,

                'quantity_sold' => $qty,
                'gross_sales' => round($gross, 2),

                'net_sales' => round((float) $group->net_sales, 2),

                'avg_item_price' => $qty > 0 ? round($gross / $qty, 2) : 0,

                'delivery_quantity' => (float) $group->delivery_quantity,
                'carryout_quantity' => (float) $group->carryout_quantity,

                'modified_quantity' => (float) $group->modified_quantity,
                'refunded_quantity' => (float) $group->refunded_quantity,
            ];

            // Same key replaceRow() used: a later group for the same item_id overwrites an earlier one.
            $rows[(string) $group->item_id] = $data;
        }

        return [array_values($rows), $groupCount];
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // DAILY AGGREGATION FROM HOURLY
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * The day's order-level figures for every store (daily_order_extras):
     * channel customers and the website/mobile split, special-items HnR and
     * upselling — a few queries over that one day's raw orders, grouped by
     * store in SQL, so reports only sum daily rows.
     */
    private function aggregateDailyOrderExtras(Carbon $date): int
    {
        $orders = fn (): Builder => $this->routedSource('detail_orders', $date, $date);
        $lines = fn (): Builder => $this->routedSource('order_line', $date, $date);
        $inStoreLines = fn (): Builder => $lines()
            ->whereIn('order_placed_method', OrderBuckets::IN_STORE['placed'])
            ->whereIn('order_fulfilled_method', OrderBuckets::IN_STORE['fulfilled']);
        // Orders of the day that have a matching line: (store, order id) pairs.
        $withLine = fn (Builder $query, Builder $lineOrders): Builder => $query->joinSub(
            $lineOrders->whereNotNull('order_id')->selectRaw('franchise_store as line_store, order_id as line_order')->distinct(),
            'lo',
            fn ($join) => $join->on('lo.line_store', '=', "{$query->from}.franchise_store")->on('lo.line_order', '=', "{$query->from}.order_id"),
        );
        $inStore = "('" . implode("','", OrderBuckets::IN_STORE_FULFILLED) . "')";

        $rows = [];
        $add = function ($result) use (&$rows): void {
            foreach ($result as $row) {
                $store = (string) $row->franchise_store;
                foreach ((array) $row as $key => $value) {
                    if ($key !== 'franchise_store') {
                        $rows[$store][$key] = $value ?? 0;
                    }
                }
            }
        };

        $add($orders()->selectRaw("
            franchise_store,
            COALESCE(SUM(customer_count), 0) as customers_total,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Phone' THEN customer_count END), 0) as customers_phone,
            COALESCE(SUM(CASE WHEN order_placed_method IN ('SoundHoundAgent', 'CallCenterAgent') THEN customer_count END), 0) as customers_call_center,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Drive Thru' THEN customer_count END), 0) as customers_drive_thru,
            COALESCE(SUM(CASE WHEN order_placed_method = 'DoorDash' THEN customer_count END), 0) as customers_doordash,
            COALESCE(SUM(CASE WHEN order_placed_method = 'UberEats' THEN customer_count END), 0) as customers_ubereats,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Grubhub' THEN customer_count END), 0) as customers_grubhub,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Website' AND order_fulfilled_method IN {$inStore} THEN royalty_obligation END), 0) as website_in_store,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Website' AND order_fulfilled_method = 'Delivery' THEN royalty_obligation END), 0) as website_delivery,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Mobile' AND order_fulfilled_method IN {$inStore} THEN royalty_obligation END), 0) as mobile_in_store,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Mobile' AND order_fulfilled_method = 'Delivery' THEN royalty_obligation END), 0) as mobile_delivery,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Website' AND order_fulfilled_method IN {$inStore} THEN customer_count END), 0) as website_in_store_customers,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Website' AND order_fulfilled_method = 'Delivery' THEN customer_count END), 0) as website_delivery_customers,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Mobile' AND order_fulfilled_method IN {$inStore} THEN customer_count END), 0) as mobile_in_store_customers,
            COALESCE(SUM(CASE WHEN order_placed_method = 'Mobile' AND order_fulfilled_method = 'Delivery' THEN customer_count END), 0) as mobile_delivery_customers
        ")->groupBy('franchise_store')->get());

        // HnR orders that include a special item.
        $special = $orders();
        $add($withLine($special, $lines()->whereIn('item_id', OrderBuckets::IMPORTANT_ITEMS_HNR_ITEM_IDS))
            ->where("{$special->from}.hnrOrder", 'Yes')
            ->selectRaw("{$special->from}.franchise_store as franchise_store, COUNT(*) as special_hnr_transactions,"
                . " COALESCE(SUM(CASE WHEN {$special->from}.broken_promise = 'Yes' THEN 1 ELSE 0 END), 0) as special_hnr_broken_promises")
            ->groupBy("{$special->from}.franchise_store")
            ->get());

        // In-store lines on orders that have a pizza line: units sold with a pizza.
        $soldWith = $inStoreLines();
        $t = $soldWith->from;
        $add($withLine($soldWith, $inStoreLines()->where('is_pizza', 1))
            ->selectRaw("
                {$t}.franchise_store as franchise_store,
                COALESCE(SUM(CASE WHEN {$t}.item_id = '103001' THEN {$t}.quantity END), 0) as upsell_crazy_bread,
                COALESCE(SUM(CASE WHEN {$t}.item_id IN ('101288', '101289') THEN {$t}.quantity END), 0) as upsell_cookies,
                COALESCE(SUM(CASE WHEN {$t}.item_id = '103002' THEN {$t}.quantity END), 0) as upsell_sauce,
                COALESCE(SUM(CASE WHEN {$t}.is_wings = 1 THEN {$t}.quantity END), 0) as upsell_wings,
                COALESCE(SUM(CASE WHEN {$t}.is_beverages = 1 THEN {$t}.quantity END), 0) as upsell_beverages,
                COALESCE(SUM(CASE WHEN {$t}.is_crazy_puffs = 1 THEN {$t}.quantity END), 0) as upsell_crazy_puffs,
                COALESCE(SUM(CASE WHEN {$t}.item_id = '204100' THEN {$t}.quantity END), 0) as upsell_bev_20oz,
                COALESCE(SUM(CASE WHEN {$t}.item_id = '204200' THEN {$t}.quantity END), 0) as upsell_bev_2l,
                COALESCE(SUM(CASE WHEN {$t}.item_id IN ('203003', '103003') THEN {$t}.quantity END), 0) as upsell_italian_cheese_bread
            ")
            ->groupBy("{$t}.franchise_store")
            ->get());

        [$cheeseId, $pepperoniId] = OrderBuckets::UPSELL_ITEM_IDS;
        $add($inStoreLines()->selectRaw("
            franchise_store,
            COALESCE(SUM(CASE WHEN is_pizza = 1 THEN quantity END), 0) as upsell_pizza_base,
            COALESCE(SUM(CASE WHEN item_id = '{$cheeseId}' THEN quantity END), 0) as upsell_emb_cheese,
            COALESCE(SUM(CASE WHEN item_id = '{$pepperoniId}' THEN quantity END), 0) as upsell_emb_pepperoni
        ")->groupBy('franchise_store')->get());

        // Replace the day: drop its rows, then write every store's in bulk.
        DailyOrderExtras::query()->where('business_date', $date->toDateString())->delete();
        $write = $this->upsertRows(
            DailyOrderExtras::class,
            array_map(
                fn (string $store, array $values): array => ['franchise_store' => $store, 'business_date' => $date->toDateString()] + $values,
                array_keys($rows),
                $rows,
            ),
            ['franchise_store', 'business_date'],
        );
        if (!empty($write['failed'])) {
            throw new \RuntimeException(sprintf('%d order-extras rows failed to write: %s', count($write['failed']), $write['failed'][0]['error']));
        }

        return $write['written'];
    }

    private function aggregateDailyFromHourly(string $store, Carbon $date): int
    {
        $dateStr = $date->toDateString();

        $hourly = HourlyStoreSummary::where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->get();

        if ($hourly->isEmpty()) {
            return 0;
        }

        $dailySummary = $this->routedSource('summary_sales', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->first();

        $overShort = (float) ($dailySummary->over_short ?? 0);

        $cashSales = (float) $this->routedSource('financial_views', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->where('sub_account', 'Total Cash Sales')
            ->sum('amount');

        $summary = $this->sumStorePeriod($hourly, [
            'franchise_store' => $store,
            'business_date' => $dateStr,
            'over_short' => $overShort,
        ]);

        $summary['cash_sales'] = round($cashSales, 2);

        $inStoreTips = (float) $this->routedSource('financial_views', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->whereIn('sub_account', ['InStoreTipAmount', 'Prepaid-InStoreTipAmount'])
            ->sum('amount');

        $deliveryTips = (float) $this->routedSource('financial_views', $date, $date)
            ->where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->whereIn('sub_account', ['Delivery-Tips', 'Prepaid-Delivery-Tips'])
            ->sum('amount');

        $summary['store_tips'] = round($inStoreTips, 2);
        $summary['delivery_tips'] = round($deliveryTips, 2);
        $summary['total_tips'] = round($deliveryTips + $inStoreTips, 2);

        $this->replaceRow(DailyStoreSummary::class, [
            'franchise_store' => $store,
            'business_date' => $dateStr,
        ], $summary);

        return 1;
    }

    private function aggregateDailyItemsFromHourly(string $store, Carbon $date): int
    {
        $dateStr = $date->toDateString();

        $items = HourlyItemSummary::where('franchise_store', $store)
            ->where('business_date', $dateStr)
            ->selectRaw('
                item_id,
                menu_item_name,
                menu_item_account,
                SUM(quantity_sold) as quantity_sold,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                AVG(avg_item_price) as avg_item_price,
                SUM(delivery_quantity) as delivery_quantity,
                SUM(carryout_quantity) as carryout_quantity,
                SUM(modified_quantity) as modified_quantity,
                SUM(refunded_quantity) as refunded_quantity
            ')
            ->groupBy('item_id', 'menu_item_name', 'menu_item_account')
            ->get();

        $rows = [];

        foreach ($items as $item) {
            // Same key replaceRow() used: a later group for the same item_id overwrites an earlier one.
            $rows[(string) $item->item_id] = [
                'franchise_store' => $store,
                'business_date' => $dateStr,
                'item_id' => $item->item_id,
                'menu_item_name' => $item->menu_item_name,
                'menu_item_account' => $item->menu_item_account,
                'quantity_sold' => $item->quantity_sold,
                'gross_sales' => round($item->gross_sales, 2),
                'net_sales' => round($item->net_sales, 2),
                'avg_item_price' => round($item->avg_item_price, 2),
                'delivery_quantity' => $item->delivery_quantity,
                'carryout_quantity' => $item->carryout_quantity,
                'modified_quantity' => $item->modified_quantity,
                'refunded_quantity' => $item->refunded_quantity,
            ];
        }

        $this->writeItemRows(DailyItemSummary::class, array_values($rows), ['franchise_store', 'business_date', 'item_id']);

        return $items->count();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // WEEKS, PERIODS, QUARTERS AND YEARS — ALL FROM DAILY
    //
    // Daily is the source of truth. Weeks (Tue–Mon) are summed from daily
    // rows. Periods, quarters and years on the fiscal calendar
    // (App\Support\FiscalCalendar) are whole weeks, so they are summed from
    // the weekly rows — exactly the sum of their days, without re-reading
    // every day. The "monthly" tables hold fiscal periods (month_num = period
    // 1–13, year_num = fiscal year). Build weeks before what sits on them.
    // ═══════════════════════════════════════════════════════════════════════════

    /** Every business week touching $start..$end. */
    public function updateWeeklySummariesRange(Carbon $start, Carbon $end): array
    {
        $buckets = [];
        for ($week = FiscalCalendar::weekStart($start); $week->lte($end); $week = $week->addWeek()) {
            $at = FiscalCalendar::of($week);
            $prior = FiscalCalendar::of($week->subWeek());
            $weekEnd = $week->addDays(6);
            $dates = ['week_start_date' => $week->toDateString(), 'week_end_date' => $weekEnd->toDateString()];
            $buckets[] = [
                'from' => $week,
                'to' => $weekEnd,
                'model' => WeeklyStoreSummary::class,
                'itemModel' => WeeklyItemSummary::class,
                'key' => ['year_num' => $at['year'], 'week_num' => $at['week']],
                'source' => 'daily',
                'storeExtra' => fn (int $days): array => $dates,
                'itemExtra' => $dates,
                'perDay' => true,
                'prior' => [['year_num' => $prior['year'], 'week_num' => $prior['week']], 'sales_vs_prior_week', 'sales_growth_percent'],
                'priorYear' => [['year_num' => $at['year'] - 1, 'week_num' => $at['week']], 'sales_vs_same_week_prior_year', 'yoy_growth_percent'],
            ];
        }

        return $this->rebuildBuckets('weekly', $buckets, [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);
    }

    /** One fiscal period, kept in the "monthly" tables (month_num = period 1–13). */
    public function updateMonthlySummariesYearMonth(int $year, int $period): array
    {
        [$from, $to] = FiscalCalendar::periodRange($year, $period);
        $prior = $period === 1 ? ['year_num' => $year - 1, 'month_num' => FiscalCalendar::PERIODS] : ['year_num' => $year, 'month_num' => $period - 1];

        return $this->rebuildBuckets('monthly', [[
            'from' => $from,
            'to' => $to,
            'model' => MonthlyStoreSummary::class,
            'itemModel' => MonthlyItemSummary::class,
            'key' => ['year_num' => $year, 'month_num' => $period],
            'source' => 'weekly',
            'storeExtra' => fn (int $days): array => [
                'month_name' => "Period {$period}",
                'operational_days' => $days,
            ],
            'itemExtra' => [],
            'perDay' => false,
            'prior' => [$prior, 'sales_vs_prior_month', 'sales_growth_percent'],
            'priorYear' => [['year_num' => $year - 1, 'month_num' => $period], 'sales_vs_same_month_prior_year', 'yoy_growth_percent'],
        ]], ['year' => $year, 'month' => $period]);
    }

    public function updateQuarterlySummariesYearQuarter(int $year, int $quarter): array
    {
        [$from, $to] = FiscalCalendar::quarterRange($year, $quarter);
        $prior = $quarter === 1 ? ['year_num' => $year - 1, 'quarter_num' => 4] : ['year_num' => $year, 'quarter_num' => $quarter - 1];

        return $this->rebuildBuckets('quarterly', [[
            'from' => $from,
            'to' => $to,
            'model' => QuarterlyStoreSummary::class,
            'itemModel' => QuarterlyItemSummary::class,
            'key' => ['year_num' => $year, 'quarter_num' => $quarter],
            'source' => 'weekly',
            'storeExtra' => fn (int $days, int $periods): array => [
                'quarter_start_date' => $from->toDateString(),
                'quarter_end_date' => $to->toDateString(),
                'operational_days' => $days,
                'operational_months' => $periods,
            ],
            // The quarterly item table keeps the quarter's dates too.
            'itemExtra' => ['quarter_start_date' => $from->toDateString(), 'quarter_end_date' => $to->toDateString()],
            'perDay' => false,
            'prior' => [$prior, 'sales_vs_prior_quarter', 'sales_growth_percent'],
            'priorYear' => [['year_num' => $year - 1, 'quarter_num' => $quarter], 'sales_vs_same_quarter_prior_year', 'yoy_growth_percent'],
        ]], ['year' => $year, 'quarter' => $quarter]);
    }

    public function updateYearlySummariesYear(int $year): array
    {
        [$from, $to] = FiscalCalendar::yearRange($year);

        return $this->rebuildBuckets('yearly', [[
            'from' => $from,
            'to' => $to,
            'model' => YearlyStoreSummary::class,
            'itemModel' => YearlyItemSummary::class,
            'key' => ['year_num' => $year],
            'source' => 'weekly',
            'storeExtra' => fn (int $days, int $periods): array => [
                'operational_days' => $days,
                'operational_months' => $periods,
            ],
            'itemExtra' => [],
            'perDay' => false,
            'prior' => [['year_num' => $year - 1], 'sales_vs_prior_year', 'sales_growth_percent'],
            'priorYear' => null,
        ]], ['year' => $year]);
    }

    /**
     * Rebuilds the store and item rows of each bucket from the daily rows of
     * its days, for every store with daily rows in it.
     *
     * @param  list<array<string, mixed>>  $buckets
     */
    private function rebuildBuckets(string $stage, array $buckets, array $extra): array
    {
        $sourceRows = 0;
        $rowsWritten = 0;
        $storesFound = 0;
        $failedStores = 0;

        foreach ($buckets as $bucket) {
            $days = [$bucket['from']->toDateString(), $bucket['to']->toDateString()];
            $this->clearBucket($bucket, $days);
            // Days and periods with sales per store (index-only counts over daily).
            $counts = DailyStoreSummary::whereBetween('business_date', $days)
                ->selectRaw('franchise_store, COUNT(*) as days, COUNT(DISTINCT ' . $this->periodSql($bucket['from']) . ') as periods')
                ->groupBy('franchise_store')
                ->get()
                ->filter(fn ($row) => $row->franchise_store !== null && $row->franchise_store !== '')
                ->keyBy('franchise_store');
            $sourceRows += $bucket['source'] === 'daily'
                ? (int) $counts->sum('days')
                : WeeklyStoreSummary::whereBetween('week_start_date', $days)->count();
            $storesFound += $counts->count();

            foreach (array_chunk($counts->keys()->all(), self::STORES_PER_BATCH) as $batch) {
                try {
                    $rowsWritten += $this->buildBucketBatch($batch, $bucket, $counts);
                } catch (\Throwable $e) {
                    $failedStores += count($batch);

                    Log::error("{$stage} aggregation failed for stores", [
                        'stores' => $batch,
                        'from' => $days[0],
                        'to' => $days[1],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($sourceRows === 0) {
            return $this->emptyStageResult($stage, "No {$buckets[0]['source']} summary rows in the requested range.");
        }

        $status = $failedStores > 0 ? 'failed' : ($rowsWritten > 0 ? 'success' : 'failed');

        return $this->stageResult(
            stage: $stage,
            status: $status,
            sourceRows: $sourceRows,
            rowsWritten: $rowsWritten,
            storesFound: $storesFound,
            storesFailed: $failedStores,
            message: $status === 'success'
                ? ucfirst($stage) . ' stage completed.'
                : ucfirst($stage) . ' stage completed with failures or zero writes despite source rows.',
            extra: $extra,
        );
    }

    /**
     * Drops the bucket's rows before it is rebuilt, so nothing stale survives:
     * a store with no days in it any more, or (weeks) the same week saved
     * under another label by older code.
     */
    private function clearBucket(array $bucket, array $days): void
    {
        foreach ([$bucket['model'], $bucket['itemModel']] as $model) {
            $model::query()->where($bucket['key'])->delete();
            if ($bucket['source'] === 'daily') {
                $model::query()->whereBetween('week_start_date', $days)->delete();
            }
        }
    }

    /**
     * A batch of stores' rows for one bucket: their source rows read in one
     * query (a week from daily rows, a period/quarter/year from weekly rows —
     * at most ~53 rows a store, as plain rows), summed per store, with the
     * prior-bucket comparisons read in one query each; item rows summed in
     * SQL; everything written in bulk. Returns the rows written.
     *
     * @param  list<string>  $batch
     */
    private function buildBucketBatch(array $batch, array $bucket, Collection $counts): int
    {
        $range = [$bucket['from']->toDateString(), $bucket['to']->toDateString()];
        $source = $bucket['source'] === 'daily'
            ? DailyStoreSummary::query()->toBase()->whereIn('franchise_store', $batch)->whereBetween('business_date', $range)->get()
            : WeeklyStoreSummary::query()->toBase()->whereIn('franchise_store', $batch)->whereBetween('week_start_date', $range)->get();

        $comparisons = [];
        foreach (array_filter([$bucket['prior'], $bucket['priorYear']]) as [$priorKey, $diffField, $growthField]) {
            $comparisons[] = [
                ($bucket['model'])::query()->toBase()->whereIn('franchise_store', $batch)->where($priorKey)
                    ->get(['franchise_store', 'royalty_obligation'])->keyBy('franchise_store'),
                $diffField,
                $growthField,
            ];
        }

        $storeRows = [];
        foreach ($source->groupBy('franchise_store') as $store => $records) {
            $count = $counts[$store];
            $summary = $this->sumStorePeriod(
                $records,
                ['franchise_store' => (string) $store] + $bucket['key'] + ($bucket['storeExtra'])((int) $count->days, (int) $count->periods),
            );

            if ($bucket['perDay'] && (int) $count->days > 0) {
                $summary['avg_daily_sales'] = round($summary['royalty_obligation'] / (int) $count->days, 2);
                $summary['avg_daily_orders'] = round($summary['total_orders'] / (int) $count->days, 2);
            }

            foreach ($comparisons as [$priors, $diffField, $growthField]) {
                $prior = $priors->get($store);
                if ($prior) {
                    $priorSales = (float) ($prior->royalty_obligation ?? 0);
                    $summary[$diffField] = round($summary['royalty_obligation'] - $priorSales, 2);
                    $summary[$growthField] = $priorSales > 0
                        ? round((($summary['royalty_obligation'] - $priorSales) / $priorSales) * 100, 2)
                        : 0;
                }
            }

            $storeRows[] = $summary;
        }

        $uniqueBy = ['franchise_store', ...array_keys($bucket['key'])];
        $written = $this->upsertRows($bucket['model'], $storeRows, $uniqueBy);
        if (!empty($written['failed'])) {
            throw new \RuntimeException(sprintf('%d store rows failed to write: %s', count($written['failed']), $written['failed'][0]['error']));
        }

        return $written['written'] + $this->writeItemRows($bucket['itemModel'], $this->bucketItemRows($batch, $bucket, $range), [...$uniqueBy, 'item_id']);
    }

    /**
     * The batch's item rows for a bucket, summed in SQL per store and item —
     * a week from daily items, the rest from weekly items. Price = gross ÷
     * quantity and quantity per day sold = quantity ÷ days sold, never an
     * average of averages.
     */
    private function bucketItemRows(array $batch, array $bucket, array $range): array
    {
        $query = $bucket['source'] === 'daily'
            ? DailyItemSummary::query()->toBase()->whereIn('franchise_store', $batch)->whereBetween('business_date', $range)
                ->selectRaw('COUNT(*) as days_sold')
            : WeeklyItemSummary::query()->toBase()->whereIn('franchise_store', $batch)->whereBetween('week_start_date', $range)
                // A week's days sold = its quantity ÷ its quantity per day sold.
                ->selectRaw('COALESCE(SUM(quantity_sold / NULLIF(avg_daily_quantity, 0)), 0) as days_sold');
        $items = $query
            ->selectRaw('
                franchise_store,
                item_id,
                menu_item_name,
                menu_item_account,
                SUM(quantity_sold) as quantity_sold,
                SUM(gross_sales) as gross_sales,
                SUM(net_sales) as net_sales,
                SUM(delivery_quantity) as delivery_quantity,
                SUM(carryout_quantity) as carryout_quantity
            ')
            ->groupBy('franchise_store', 'item_id', 'menu_item_name', 'menu_item_account')
            ->get();

        $rows = [];
        foreach ($items as $item) {
            $quantity = (float) $item->quantity_sold;
            // One row per store and item: a later group for the same item overwrites an earlier one.
            $rows[$item->franchise_store . '|' . $item->item_id] = ['franchise_store' => (string) $item->franchise_store] + $bucket['key'] + [
                'item_id' => $item->item_id,
                'menu_item_name' => $item->menu_item_name,
                'menu_item_account' => $item->menu_item_account,
                'quantity_sold' => $item->quantity_sold,
                'gross_sales' => round((float) $item->gross_sales, 2),
                'net_sales' => round((float) $item->net_sales, 2),
                'avg_item_price' => $quantity > 0 ? round((float) $item->gross_sales / $quantity, 2) : 0,
                'avg_daily_quantity' => (float) $item->days_sold > 0 ? round($quantity / (float) $item->days_sold, 2) : 0,
                'delivery_quantity' => $item->delivery_quantity,
                'carryout_quantity' => $item->carryout_quantity,
            ] + $bucket['itemExtra'];
        }

        return array_values($rows);
    }

    /**
     * SQL: the fiscal period index of business_date, counted from $from (a
     * period start) — 4-week blocks, so COUNT(DISTINCT …) = periods with sales.
     */
    private function periodSql(CarbonImmutable $from): string
    {
        $days = FiscalCalendar::PERIOD_WEEKS * 7;

        return "FLOOR(DATEDIFF(business_date, '{$from->toDateString()}') / {$days})";
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // HELPER - SUM ALL METRICS FOR A PERIOD
    // ═══════════════════════════════════════════════════════════════════════════

    private function sumStorePeriod($records, array $base): array
    {
        $summary = $base + [
            // Sales
            'royalty_obligation' => round((float) $records->sum('royalty_obligation'), 2),
            'gross_sales' => round((float) $records->sum('gross_sales'), 2),
            'net_sales' => round((float) $records->sum('net_sales'), 2),
            'refund_amount' => round((float) $records->sum('refund_amount'), 2),

            // Orders
            'total_orders' => (int) $records->sum('total_orders'),
            'completed_orders' => (int) $records->sum('completed_orders'),
            'cancelled_orders' => (int) $records->sum('cancelled_orders'),
            'modified_orders' => (int) $records->sum('modified_orders'),
            'refunded_orders' => (int) $records->sum('refunded_orders'),
            'customer_count' => (int) $records->sum('customer_count'),

            // Channels (Orders)
            'phone_orders' => (int) $records->sum('phone_orders'),
            'website_orders' => (int) $records->sum('website_orders'),
            'mobile_orders' => (int) $records->sum('mobile_orders'),
            'call_center_orders' => (int) $records->sum('call_center_orders'),
            'drive_thru_orders' => (int) $records->sum('drive_thru_orders'),

            // Channels (Sales)
            'phone_sales' => round((float) $records->sum('phone_sales'), 2),
            'website_sales' => round((float) $records->sum('website_sales'), 2),
            'mobile_sales' => round((float) $records->sum('mobile_sales'), 2),
            'call_center_sales' => round((float) $records->sum('call_center_sales'), 2),
            'drive_thru_sales' => round((float) $records->sum('drive_thru_sales'), 2),

            // Marketplace
            'doordash_orders' => (int) $records->sum('doordash_orders'),
            'doordash_sales' => round((float) $records->sum('doordash_sales'), 2),
            'ubereats_orders' => (int) $records->sum('ubereats_orders'),
            'ubereats_sales' => round((float) $records->sum('ubereats_sales'), 2),
            'grubhub_orders' => (int) $records->sum('grubhub_orders'),
            'grubhub_sales' => round((float) $records->sum('grubhub_sales'), 2),

            // Fulfillment totals
            'delivery_orders' => (int) $records->sum('delivery_orders'),
            'delivery_sales' => round((float) $records->sum('delivery_sales'), 2),
            'carryout_orders' => (int) $records->sum('carryout_orders'),
            'carryout_sales' => round((float) $records->sum('carryout_sales'), 2),

            // Category splits
            'pizza_delivery_quantity' => (int) $records->sum('pizza_delivery_quantity'),
            'pizza_delivery_sales' => round((float) $records->sum('pizza_delivery_sales'), 2),
            'pizza_carryout_quantity' => (int) $records->sum('pizza_carryout_quantity'),
            'pizza_carryout_sales' => round((float) $records->sum('pizza_carryout_sales'), 2),

            'hnr_delivery_quantity' => (int) $records->sum('hnr_delivery_quantity'),
            'hnr_delivery_sales' => round((float) $records->sum('hnr_delivery_sales'), 2),
            'hnr_carryout_quantity' => (int) $records->sum('hnr_carryout_quantity'),
            'hnr_carryout_sales' => round((float) $records->sum('hnr_carryout_sales'), 2),

            'bread_delivery_quantity' => (int) $records->sum('bread_delivery_quantity'),
            'bread_delivery_sales' => round((float) $records->sum('bread_delivery_sales'), 2),
            'bread_carryout_quantity' => (int) $records->sum('bread_carryout_quantity'),
            'bread_carryout_sales' => round((float) $records->sum('bread_carryout_sales'), 2),

            'wings_delivery_quantity' => (int) $records->sum('wings_delivery_quantity'),
            'wings_delivery_sales' => round((float) $records->sum('wings_delivery_sales'), 2),
            'wings_carryout_quantity' => (int) $records->sum('wings_carryout_quantity'),
            'wings_carryout_sales' => round((float) $records->sum('wings_carryout_sales'), 2),

            'beverages_delivery_quantity' => (int) $records->sum('beverages_delivery_quantity'),
            'beverages_delivery_sales' => round((float) $records->sum('beverages_delivery_sales'), 2),
            'beverages_carryout_quantity' => (int) $records->sum('beverages_carryout_quantity'),
            'beverages_carryout_sales' => round((float) $records->sum('beverages_carryout_sales'), 2),

            'other_foods_delivery_quantity' => (int) $records->sum('other_foods_delivery_quantity'),
            'other_foods_delivery_sales' => round((float) $records->sum('other_foods_delivery_sales'), 2),
            'other_foods_carryout_quantity' => (int) $records->sum('other_foods_carryout_quantity'),
            'other_foods_carryout_sales' => round((float) $records->sum('other_foods_carryout_sales'), 2),

            'side_items_delivery_quantity' => (int) $records->sum('side_items_delivery_quantity'),
            'side_items_delivery_sales' => round((float) $records->sum('side_items_delivery_sales'), 2),
            'side_items_carryout_quantity' => (int) $records->sum('side_items_carryout_quantity'),
            'side_items_carryout_sales' => round((float) $records->sum('side_items_carryout_sales'), 2),

            // Financial
            'sales_tax' => round((float) $records->sum('sales_tax'), 2),
            'delivery_fees' => round((float) $records->sum('delivery_fees'), 2),
            'delivery_tips' => round((float) $records->sum('delivery_tips'), 2),
            'store_tips' => round((float) $records->sum('store_tips'), 2),
            'total_tips' => round((float) $records->sum('total_tips'), 2),

            // Payments
            'cash_sales' => round((float) $records->sum('cash_sales'), 2),
            'over_short' => round((float) $records->sum('over_short'), 2),

            // Portal
            'portal_eligible_orders' => (int) $records->sum('portal_eligible_orders'),
            'portal_used_orders' => (int) $records->sum('portal_used_orders'),
            'portal_on_time_orders' => (int) $records->sum('portal_on_time_orders'),

            // Digital
            'digital_orders' => (int) $records->sum('digital_orders'),
            'digital_sales' => round((float) $records->sum('digital_sales'), 2),

            'hnr_transactions' => (int) $records->sum('hnr_transactions'),
            'hnr_broken_promises' => (int) $records->sum('hnr_broken_promises'),
        ];

        $summary['avg_order_value'] = $summary['total_orders'] > 0
            ? round($summary['royalty_obligation'] / $summary['total_orders'], 2)
            : 0;

        $summary['portal_usage_rate'] = $summary['portal_eligible_orders'] > 0
            ? round(($summary['portal_used_orders'] / $summary['portal_eligible_orders']) * 100, 2)
            : 0;

        $summary['portal_on_time_rate'] = $summary['portal_used_orders'] > 0
            ? round(($summary['portal_on_time_orders'] / $summary['portal_used_orders']) * 100, 2)
            : 0;

        $summary['digital_penetration'] = $summary['total_orders'] > 0
            ? round(($summary['digital_orders'] / $summary['total_orders']) * 100, 2)
            : 0;

        $summary['delivery_orders'] = (int) (
            $summary['pizza_delivery_quantity']
            + $summary['hnr_delivery_quantity']
            + $summary['bread_delivery_quantity']
            + $summary['wings_delivery_quantity']
            + $summary['beverages_delivery_quantity']
            + $summary['other_foods_delivery_quantity']
            + $summary['side_items_delivery_quantity']
        );

        $summary['delivery_sales'] = round(
            $summary['pizza_delivery_sales']
            + $summary['hnr_delivery_sales']
            + $summary['bread_delivery_sales']
            + $summary['wings_delivery_sales']
            + $summary['beverages_delivery_sales']
            + $summary['other_foods_delivery_sales']
            + $summary['side_items_delivery_sales'],
            2
        );

        $summary['carryout_orders'] = (int) (
            $summary['pizza_carryout_quantity']
            + $summary['hnr_carryout_quantity']
            + $summary['bread_carryout_quantity']
            + $summary['wings_carryout_quantity']
            + $summary['beverages_carryout_quantity']
            + $summary['other_foods_carryout_quantity']
            + $summary['side_items_carryout_quantity']
        );

        $summary['carryout_sales'] = round(
            $summary['pizza_carryout_sales']
            + $summary['hnr_carryout_sales']
            + $summary['bread_carryout_sales']
            + $summary['wings_carryout_sales']
            + $summary['beverages_carryout_sales']
            + $summary['other_foods_carryout_sales']
            + $summary['side_items_carryout_sales'],
            2
        );

        return $summary;
    }
}