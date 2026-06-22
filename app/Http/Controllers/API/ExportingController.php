<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Database\DatabaseRouter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Query\Builder;

class ExportingController extends Controller
{
    protected const CHUNK_SIZE = 5000;

    /**
     * CSV export
     * GET /api/export/csv?start=2025-01-01&end=2025-12-31&store=03795&model=detail_orders
     * GET /api/export/csv?model=all (exports all models as ZIP)
     */
    public function exportCSV(Request $request)
    {
        DB::connection()->disableQueryLog();
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $startDate = null;
        $endDate = null;
        $store = null;

        try {
            $validated = $request->validate([
                'start' => 'nullable|date',
                'end' => 'nullable|date|after_or_equal:start',
                'store' => 'nullable|string',
                'model' => 'required|string|in:' . implode(',', array_merge($this->getAvailableModels(), ['all'])),
            ]);

            $startDate = !empty($validated['start']) ? Carbon::parse($validated['start']) : null;
            $endDate = !empty($validated['end']) ? Carbon::parse($validated['end']) : null;
            $store = $validated['store'] ?? null;
            $model = $validated['model'];


            if ($model === 'all') {
                return $this->exportAllModelsZip($startDate, $endDate, $store);
            }

            return $this->exportSingleModelCsv($model, $startDate, $endDate, $store);
        } catch (\Throwable $e) {
            $this->logExportException($e, [
                'type' => 'csv',
                'model' => $request->get('model'),
                'start' => $startDate?->toDateString(),
                'end' => $endDate?->toDateString(),
                'store' => $store,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * JSON export
     * GET /api/export/json?start=2025-01-01&end=2025-12-31&store=03795&model=detail_orders&limit=1000
     */
    public function exportJson(Request $request)
    {
        DB::connection()->disableQueryLog();
        set_time_limit(0);

        $validated = $request->validate([
            'start' => 'nullable|date',
            'end' => 'nullable|date|after_or_equal:start',
            'store' => 'nullable|string',
            'model' => 'required|string|in:' . implode(',', $this->getAvailableModels()),
            'limit' => 'nullable|integer|min:1|max:50000',
        ]);

        $startDate = !empty($validated['start']) ? Carbon::parse($validated['start']) : null;
        $endDate = !empty($validated['end']) ? Carbon::parse($validated['end']) : null;
        $store = $validated['store'] ?? null;
        $model = $validated['model'];
        $limit = $validated['limit'] ?? null;


        try {
            if ($this->isAggregationTable($model)) {
                $q = $this->buildAggregationQuery($model, $startDate, $endDate);
                if ($store)
                    $q->where('franchise_store', $store);
                if ($limit)
                    $q->limit($limit);

                $data = $q->get();

                return response()->json([
                    'success' => true,
                    'record_count' => $data->count(),
                    'data' => $data,
                ]);
            }

            $queries = DatabaseRouter::routedQueries($model, $startDate, $endDate);

            $all = collect();
            foreach ($queries as $q) {
                if ($store)
                    $q->where('franchise_store', $store);

                if ($limit) {
                    $remaining = $limit - $all->count();
                    if ($remaining <= 0)
                        break;
                    $q->limit($remaining);
                }

                $all = $all->concat($q->get());
            }

            return response()->json([
                'success' => true,
                'record_count' => $all->count(),
                'data' => $all->values(),
            ]);
        } catch (\Throwable $e) {
            $this->logExportException($e, [
                'type' => 'json',
                'model' => $model,
                'start' => $startDate?->toDateString(),
                'end' => $endDate?->toDateString(),
                'store' => $store,
                'limit' => $limit,
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }

    /**
     * Single model CSV export
     */
    protected function exportSingleModelCsv(
        string $model,
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?string $store
    ) {
        $columns = $this->getColumnsForModel($model);
        $filename = $this->makeFilename($model, $startDate, $endDate, $store, 'csv');

        $tmpDir = storage_path('app/export_tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }
        $tmpPath = $tmpDir . '/' . $filename . '.' . uniqid('tmp_', true);

        $fh = fopen($tmpPath, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open temp CSV file: {$tmpPath}");
        }

        try {
            fputcsv($fh, $columns);
            $this->writeCsvRowsForModel($fh, $model, $startDate, $endDate, $store, $columns);
        } finally {
            fclose($fh);
        }

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ])->deleteFileAfterSend(true);
    }

    /**
     * ZIP export for model=all
     */
    protected function exportAllModelsZip(?Carbon $startDate, ?Carbon $endDate, ?string $store)
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension is not installed.');
        }

        $models = $this->getAvailableModels();
        $zipFilename = $this->makeFilename('all_models', $startDate, $endDate, $store, 'zip');

        $tmpDir = storage_path('app/export_tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $zipPath = $tmpDir . '/export_' . uniqid('', true) . '.zip';
        $tmpCsvPaths = [];

        Log::info('EXPORT ALL MODELS ZIP', ['models' => count($models)]);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create zip file.');
        }

        try {
            foreach ($models as $model) {
                $columns = $this->getColumnsForModel($model);
                $csvName = $this->makeFilename($model, $startDate, $endDate, $store, 'csv');

                $tmpCsv = $tmpDir . '/' . $csvName . '.' . uniqid('tmp_', true);
                $tmpCsvPaths[] = $tmpCsv;

                $fh = fopen($tmpCsv, 'w');
                if ($fh === false) {
                    throw new \RuntimeException("Cannot open temp CSV file for {$model}");
                }

                fputcsv($fh, $columns);

                try {
                    $this->writeCsvRowsForModel($fh, $model, $startDate, $endDate, $store, $columns);
                } catch (\Throwable $e) {
                    $this->logExportException($e, [
                        'type' => 'zip-model',
                        'model' => $model,
                        'start' => $startDate?->toDateString(),
                        'end' => $endDate?->toDateString(),
                        'store' => $store,
                    ]);
                } finally {
                    fclose($fh);
                }

                $zip->addFile($tmpCsv, $csvName);
            }

            $zip->close();
        } finally {
            foreach ($tmpCsvPaths as $p) {
                if (file_exists($p))
                    @unlink($p);
            }
        }

        return response()->download($zipPath, $zipFilename, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Write CSV rows for a model
     * NOTE: Adds orderBy before chunk()
     */
    protected function writeCsvRowsForModel(
        $fh,
        string $model,
        ?Carbon $startDate,
        ?Carbon $endDate,
        ?string $store,
        array $columns
    ): void {
        if ($this->isAggregationTable($model)) {
            $q = $this->buildAggregationQuery($model, $startDate, $endDate);
            if ($store)
                $q->where('franchise_store', $store);

            $orderColumns = $this->getOrderColumnsForModel($model);
            foreach ($orderColumns as $col) {
                $q->orderBy($col);
            }

            $rowCount = 0;
            $q->select($columns)->chunk(self::CHUNK_SIZE, function ($rows) use ($fh, $columns, &$rowCount) {
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = $row->{$col} ?? null;
                    }
                    fputcsv($fh, $line);
                    $rowCount++;
                }
            });


            return;
        }

        // Hot/archive tables via DatabaseRouter
        $queries = DatabaseRouter::routedQueries($model, $startDate, $endDate);

        $totalRows = 0;
        foreach ($queries as $q) {
            if ($store)
                $q->where('franchise_store', $store);

            $tableName = $q->from;

            $orderColumns = $this->getOrderColumnsForModel($model);
            foreach ($orderColumns as $col) {
                $q->orderBy($col);
            }

            $queryRows = 0;
            $q->select($columns)->chunk(self::CHUNK_SIZE, function ($rows) use ($fh, $columns, &$queryRows) {
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($columns as $col) {
                        $line[] = $row->{$col} ?? null;
                    }
                    fputcsv($fh, $line);
                    $queryRows++;
                }
            });


            $totalRows += $queryRows;
        }
    }

    /**
     * Get order columns for chunk() - since tables don't have auto-increment 'id'
     */
    protected function getOrderColumnsForModel(string $model): array
    {
        $orderMap = [
            'detail_orders' => ['franchise_store', 'business_date'],
            'order_line' => ['franchise_store', 'business_date'],
            'summary_sales' => ['franchise_store', 'business_date'],
            'summary_items' => ['franchise_store', 'business_date'],
            'summary_transactions' => ['franchise_store', 'business_date'],
            'waste' => ['franchise_store', 'business_date'],
            'cash_management' => ['franchise_store', 'business_date'],
            'financial_views' => ['franchise_store', 'business_date'],
            'alta_inventory_waste' => ['franchise_store', 'business_date'],
            'alta_inventory_ingredient_usage' => ['franchise_store', 'business_date'],
            'alta_inventory_ingredient_orders' => ['franchise_store', 'business_date'],
            'alta_inventory_cogs' => ['franchise_store', 'business_date'],

            'yearly_store_summary' => ['franchise_store', 'year_num'],
            'yearly_item_summary' => ['franchise_store', 'year_num'],
            'weekly_store_summary' => ['franchise_store', 'year_num', 'week_num'],
            'weekly_item_summary' => ['franchise_store', 'year_num', 'week_num'],
            'quarterly_store_summary' => ['franchise_store', 'year_num', 'quarter_num'],
            'quarterly_item_summary' => ['franchise_store', 'year_num', 'quarter_num'],
            'monthly_store_summary' => ['franchise_store', 'year_num', 'month_num'],
            'monthly_item_summary' => ['franchise_store', 'year_num', 'month_num'],
            'daily_store_summary' => ['franchise_store', 'business_date'],
            'daily_item_summary' => ['franchise_store', 'business_date'],

            'hourly_store_summary' => ['franchise_store', 'business_date', 'hour'],
            'hourly_item_summary' => ['franchise_store', 'business_date', 'hour', 'item_id'],
        ];

        return $orderMap[$model] ?? ['franchise_store', 'business_date'];
    }

    /**
     * Log exceptions
     */
    protected function logExportException(\Throwable $e, array $context = []): void
    {
        $payload = [
            'when' => now()->toDateTimeString(),
            'context' => $context,
            'error' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        try {
            Log::error('EXPORT FAILED', $payload);
        } catch (\Throwable $x) {
        }

        @file_put_contents(storage_path('logs/export_errors.log'), json_encode($payload) . PHP_EOL, FILE_APPEND);
    }

    protected function isAggregationTable(string $model): bool
    {
        return in_array($model, [
            'yearly_store_summary',
            'yearly_item_summary',
            'weekly_store_summary',
            'weekly_item_summary',
            'quarterly_store_summary',
            'quarterly_item_summary',
            'monthly_store_summary',
            'monthly_item_summary',
            'daily_store_summary',
            'daily_item_summary',
            'hourly_store_summary',
            'hourly_item_summary',
        ], true);
    }

    protected function buildAggregationQuery(string $model, ?Carbon $startDate, ?Carbon $endDate): Builder
    {
        $query = DB::connection('aggregation')->table($model);

        // Normalize open-ended ranges
        if ($startDate && !$endDate) {
            $endDate = Carbon::now();
        }
        if (!$startDate && $endDate) {
            // pick something safely early for your dataset
            $startDate = Carbon::create(2000, 1, 1);
        }

        // No date filtering requested
        if (!$startDate && !$endDate) {
            return $query;
        }

        // Always compare on date strings to avoid time parts
        $start = $startDate->toDateString();
        $end = $endDate->toDateString();

        // Helper: overlap logic for period tables
        // period overlaps requested range iff period_start <= end AND period_end >= start
        $applyOverlap = function (Builder $q, string $periodStartCol, string $periodEndCol) use ($start, $end) {
            return $q->where($periodStartCol, '<=', $end)
                ->where($periodEndCol, '>=', $start);
        };

        // If you want: a simple mapping makes this harder to break later
        switch ($model) {

            // Yearly: if yearly tables represent a year bucket, filter by year_num range.
            case 'yearly_store_summary':
            case 'yearly_item_summary':
                return $query->whereBetween('year_num', [$startDate->year, $endDate->year]);

            // Weekly: use overlap, NOT whereBetween on starts/ends.
            case 'weekly_store_summary':
            case 'weekly_item_summary':
                return $applyOverlap($query, 'week_start_date', 'week_end_date');

            // Quarterly: use overlap, NOT whereBetween on starts/ends.
            case 'quarterly_store_summary':
            case 'quarterly_item_summary':
                return $applyOverlap($query, 'quarter_start_date', 'quarter_end_date');

            // Monthly: your schema uses (year_num, month_num).
            // Filter by a computed numeric key YYYYMM so ranges work across years.
            case 'monthly_store_summary':
            case 'monthly_item_summary':
                $startKey = ($startDate->year * 100) + $startDate->month;
                $endKey = ($endDate->year * 100) + $endDate->month;

                // This works even when spanning multiple years.
                return $query->whereRaw('(year_num * 100 + month_num) BETWEEN ? AND ?', [$startKey, $endKey]);

            // Daily / hourly: business_date is the grain, so simple between is correct.
            case 'daily_store_summary':
            case 'daily_item_summary':
            case 'hourly_store_summary':
            case 'hourly_item_summary':
                return $query->whereBetween('business_date', [$start, $end]);

            default:
                // safest fallback: do nothing (or throw if you prefer strict)
                return $query;
        }
    }

    protected function getAvailableModels(): array
    {
        return [
            'detail_orders',
            'order_line',
            'summary_sales',
            'summary_items',
            'summary_transactions',
            'waste',
            'cash_management',
            'financial_views',
            'alta_inventory_waste',
            'alta_inventory_ingredient_usage',
            'alta_inventory_ingredient_orders',
            'alta_inventory_cogs',

            'yearly_store_summary',
            'yearly_item_summary',
            'weekly_store_summary',
            'weekly_item_summary',
            'quarterly_store_summary',
            'quarterly_item_summary',
            'monthly_store_summary',
            'monthly_item_summary',
            'daily_store_summary',
            'daily_item_summary',
            'hourly_store_summary',
            'hourly_item_summary',
        ];
    }

    /**
     * ✅ UPDATED: store summary columns now include the new category split fields:
     * - pizza / hnr / bread / wings / beverages / other_foods / side_items
     * - {cat}_delivery_quantity, {cat}_delivery_sales, {cat}_carryout_quantity, {cat}_carryout_sales
     * - Removes crazy_puffs_* references
     */
    protected function getColumnsForModel(string $model): array
    {

        $storeCategorySplits = [
            'pizza_delivery_quantity',
            'pizza_delivery_sales',
            'pizza_carryout_quantity',
            'pizza_carryout_sales',
            'hnr_delivery_quantity',
            'hnr_delivery_sales',
            'hnr_carryout_quantity',
            'hnr_carryout_sales',
            'bread_delivery_quantity',
            'bread_delivery_sales',
            'bread_carryout_quantity',
            'bread_carryout_sales',
            'wings_delivery_quantity',
            'wings_delivery_sales',
            'wings_carryout_quantity',
            'wings_carryout_sales',
            'beverages_delivery_quantity',
            'beverages_delivery_sales',
            'beverages_carryout_quantity',
            'beverages_carryout_sales',
            'other_foods_delivery_quantity',
            'other_foods_delivery_sales',
            'other_foods_carryout_quantity',
            'other_foods_carryout_sales',
            'side_items_delivery_quantity',
            'side_items_delivery_sales',
            'side_items_carryout_quantity',
            'side_items_carryout_sales',
        ];

        $commonStoreSummary = array_merge([
            'royalty_obligation',
            'gross_sales',
            'net_sales',
            'refund_amount',
            'total_orders',
            'completed_orders',
            'cancelled_orders',
            'modified_orders',
            'refunded_orders',
            'avg_order_value',
            'customer_count',

            'phone_orders',
            'phone_sales',
            'website_orders',
            'website_sales',
            'mobile_orders',
            'mobile_sales',
            'call_center_orders',
            'call_center_sales',
            'drive_thru_orders',
            'drive_thru_sales',

            'doordash_orders',
            'doordash_sales',
            'ubereats_orders',
            'ubereats_sales',
            'grubhub_orders',
            'grubhub_sales',

            'delivery_orders',
            'delivery_sales',
            'carryout_orders',
            'carryout_sales',

            // ✅ NEW totals + splits
        ], $storeCategorySplits, [
            'sales_tax',
            'delivery_fees',
            'delivery_tips',
            'store_tips',
            'total_tips',
            'cash_sales',
            'over_short',

            'portal_eligible_orders',
            'portal_used_orders',
            'portal_usage_rate',
            'portal_on_time_orders',
            'portal_on_time_rate',

            'digital_orders',
            'digital_sales',
            'digital_penetration',

            'hnr_transactions',
            'hnr_broken_promises'
        ]);

        $columnMap = [
            // RAW MODELS (unchanged)
            'detail_orders' => [
                'franchise_store',
                'business_date',
                'order_id',
                'date_time_placed',
                'date_time_fulfilled',
                'royalty_obligation',
                'quantity',
                'customer_count',
                'taxable_amount',
                'non_taxable_amount',
                'tax_exempt_amount',
                'non_royalty_amount',
                'sales_tax',
                'gross_sales',
                'occupational_tax',
                'employee',
                'override_approval_employee',
                'order_placed_method',
                'order_fulfilled_method',
                'delivery_tip',
                'delivery_tip_tax',
                'delivery_fee',
                'delivery_fee_tax',
                'delivery_service_fee',
                'delivery_service_fee_tax',
                'delivery_small_order_fee',
                'delivery_small_order_fee_tax',
                'modified_order_amount',
                'modification_reason',
                'refunded',
                'payment_methods',
                'transaction_type',
                'store_tip_amount',
                'promise_date',
                'tax_exemption_id',
                'tax_exemption_entity_name',
                'user_id',
                'hnrOrder',
                'broken_promise',
                'portal_eligible',
                'portal_used',
                'put_into_portal_before_promise_time',
                'portal_compartments_used',
                'time_loaded_into_portal'
            ],
            'order_line' => [
                'franchise_store',
                'business_date',
                'order_id',
                'item_id',
                'date_time_placed',
                'date_time_fulfilled',
                'menu_item_name',
                'menu_item_account',
                'bundle_name',
                'net_amount',
                'quantity',
                'royalty_item',
                'taxable_item',
                'tax_included_amount',
                'employee',
                'override_approval_employee',
                'order_placed_method',
                'order_fulfilled_method',
                'modified_order_amount',
                'modification_reason',
                'payment_methods',
                'refunded'
            ],
            'summary_sales' => [
                'franchise_store',
                'business_date',
                'royalty_obligation',
                'customer_count',
                'taxable_amount',
                'non_taxable_amount',
                'tax_exempt_amount',
                'non_royalty_amount',
                'refund_amount',
                'sales_tax',
                'gross_sales',
                'occupational_tax',
                'delivery_tip',
                'delivery_fee',
                'delivery_service_fee',
                'delivery_small_order_fee',
                'modified_order_amount',
                'store_tip_amount',
                'prepaid_cash_orders',
                'prepaid_non_cash_orders',
                'prepaid_sales',
                'prepaid_delivery_tip',
                'prepaid_in_store_tip_amount',
                'over_short',
                'previous_day_refunds',
                'saf',
                'manager_notes'
            ],
            'summary_items' => [
                'franchise_store',
                'business_date',
                'menu_item_name',
                'menu_item_account',
                'item_id',
                'item_quantity',
                'royalty_obligation',
                'taxable_amount',
                'non_taxable_amount',
                'tax_exempt_amount',
                'non_royalty_amount',
                'tax_included_amount'
            ],
            'summary_transactions' => [
                'franchise_store',
                'business_date',
                'payment_method',
                'sub_payment_method',
                'total_amount',
                'saf_qty',
                'saf_total'
            ],
            'waste' => [
                'business_date',
                'franchise_store',
                'cv_item_id',
                'menu_item_name',
                'expired',
                'waste_date_time',
                'produce_date_time',
                'waste_reason',
                'cv_order_id',
                'waste_type',
                'item_cost',
                'quantity'
            ],
            'cash_management' => [
                'franchise_store',
                'business_date',
                'create_datetime',
                'verified_datetime',
                'till',
                'check_type',
                'system_totals',
                'verified',
                'variance',
                'created_by',
                'verified_by'
            ],
            'financial_views' => [
                'franchise_store',
                'business_date',
                'area',
                'sub_account',
                'amount'
            ],
            'alta_inventory_waste' => [
                'franchise_store',
                'business_date',
                'item_id',
                'item_description',
                'waste_reason',
                'unit_food_cost',
                'qty'
            ],
            'alta_inventory_ingredient_usage' => [
                'franchise_store',
                'business_date',
                'count_period',
                'ingredient_id',
                'ingredient_description',
                'ingredient_category',
                'ingredient_unit',
                'ingredient_unit_cost',
                'starting_inventory_qty',
                'received_qty',
                'net_transferred_qty',
                'ending_inventory_qty',
                'actual_usage',
                'theoretical_usage',
                'variance_qty',
                'waste_qty'
            ],
            'alta_inventory_ingredient_orders' => [
                'franchise_store',
                'business_date',
                'supplier',
                'invoice_number',
                'purchase_order_number',
                'ingredient_id',
                'ingredient_description',
                'ingredient_category',
                'ingredient_unit',
                'unit_price',
                'order_qty',
                'sent_qty',
                'received_qty',
                'total_cost'
            ],
            'alta_inventory_cogs' => [
                'franchise_store',
                'business_date',
                'count_period',
                'inventory_category',
                'starting_value',
                'received_value',
                'net_transfer_value',
                'ending_value',
                'used_value',
                'theoretical_usage_value',
                'variance_value'
            ],

            // ✅ AGGREGATION MODELS (UPDATED)

            'yearly_store_summary' => array_merge(
                ['franchise_store', 'year_num'],
                $commonStoreSummary,
                ['operational_days', 'operational_months', 'avg_daily_sales', 'avg_monthly_sales', 'sales_vs_prior_year', 'sales_growth_percent']
            ),

            'yearly_item_summary' => [
                'franchise_store',
                'year_num',
                'item_id',
                'menu_item_name',
                'menu_item_account',
                'quantity_sold',
                'gross_sales',
                'net_sales',
                'avg_item_price',
                'avg_daily_quantity',
                'delivery_quantity',
                'carryout_quantity'
            ],

            'weekly_store_summary' => array_merge(
                ['franchise_store', 'year_num', 'week_num', 'week_start_date', 'week_end_date'],
                $commonStoreSummary,
                ['avg_daily_sales', 'avg_daily_orders', 'sales_vs_prior_week', 'sales_growth_percent']
            ),

            'weekly_item_summary' => [
                'franchise_store',
                'year_num',
                'week_num',
                'week_start_date',
                'week_end_date',
                'item_id',
                'menu_item_name',
                'menu_item_account',
                'quantity_sold',
                'gross_sales',
                'net_sales',
                'avg_item_price',
                'avg_daily_quantity',
                'delivery_quantity',
                'carryout_quantity'
            ],

            'quarterly_store_summary' => array_merge(
                ['franchise_store', 'year_num', 'quarter_num', 'quarter_start_date', 'quarter_end_date'],
                $commonStoreSummary,
                ['operational_days', 'operational_months', 'avg_daily_sales', 'avg_monthly_sales', 'sales_vs_prior_quarter', 'sales_growth_percent', 'sales_vs_same_quarter_prior_year', 'yoy_growth_percent']
            ),

            'quarterly_item_summary' => [
                'franchise_store',
                'year_num',
                'quarter_num',
                'quarter_start_date',
                'quarter_end_date',
                'item_id',
                'menu_item_name',
                'menu_item_account',
                'quantity_sold',
                'gross_sales',
                'net_sales',
                'avg_item_price',
                'avg_daily_quantity',
                'delivery_quantity',
                'carryout_quantity'
            ],

            'monthly_store_summary' => array_merge(
                ['franchise_store', 'year_num', 'month_num', 'month_name'],
                $commonStoreSummary,
                ['operational_days', 'avg_daily_sales', 'avg_daily_orders', 'sales_vs_prior_month', 'sales_growth_percent', 'sales_vs_same_month_prior_year', 'yoy_growth_percent']
            ),

            'monthly_item_summary' => [
                'franchise_store',
                'year_num',
                'month_num',
                'item_id',
                'menu_item_name',
                'menu_item_account',
                'quantity_sold',
                'gross_sales',
                'net_sales',
                'avg_item_price',
                'avg_daily_quantity',
                'delivery_quantity',
                'carryout_quantity'
            ],

            'daily_store_summary' => array_merge(
                ['franchise_store', 'business_date'],
                $commonStoreSummary
            ),

            'daily_item_summary' => [
                'franchise_store',
                'business_date',
                'item_id',
                'menu_item_name',
                'menu_item_account',
                'quantity_sold',
                'gross_sales',
                'net_sales',
                'avg_item_price',
                'delivery_quantity',
                'carryout_quantity',
                'modified_quantity',
                'refunded_quantity'
            ],

            'hourly_store_summary' => array_merge(
                ['franchise_store', 'business_date', 'hour'],
                $commonStoreSummary
            ),

            'hourly_item_summary' => [
                'franchise_store',
                'business_date',
                'hour',
                'item_id',
                'menu_item_name',
                'menu_item_account',

                'quantity_sold',
                'gross_sales',
                'net_sales',
                'avg_item_price',

                'delivery_quantity',
                'carryout_quantity',

                'modified_quantity',
                'refunded_quantity',
            ],
        ];

        return $columnMap[$model] ?? ['*'];
    }

    protected function makeFilename(string $model, ?Carbon $startDate, ?Carbon $endDate, ?string $store, string $extension): string
    {
        $parts = [$model];
        $parts[] = $startDate ? $startDate->format('Ymd') : 'all';
        $parts[] = $endDate ? $endDate->format('Ymd') : 'all';
        if ($store)
            $parts[] = $store;

        return implode('_', $parts) . '.' . $extension;
    }
}
