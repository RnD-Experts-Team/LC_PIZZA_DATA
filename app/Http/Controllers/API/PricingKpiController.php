<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\PricingKpiRequest;
use App\Models\Aggregation\DailyItemSummary;
use App\Models\Aggregation\DailyStoreSummary;
use Illuminate\Support\Facades\Log;

class PricingKpiController extends Controller
{
    /**
     * Pricing KPI CSV export
     * GET /api/reports/pricing-kpi?start_date=2026-06-01&end_date=2026-06-30&stores=03795,03796&item_ids=1001,1002
     */
    public function export(PricingKpiRequest $request)
    {
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $stores = $request->storesArray();
        $itemIds = $request->itemIdsArray();

        try {
            $storeTotals = DailyStoreSummary::whereIn('franchise_store', $stores)
                ->whereBetween('business_date', [$startDate, $endDate])
                ->selectRaw('franchise_store, SUM(royalty_obligation) as total_sales, SUM(total_orders) as total_orders')
                ->groupBy('franchise_store')
                ->get()
                ->keyBy('franchise_store');

            $itemTotals = DailyItemSummary::whereIn('franchise_store', $stores)
                ->whereIn('item_id', $itemIds)
                ->whereBetween('business_date', [$startDate, $endDate])
                ->selectRaw('franchise_store, item_id, MAX(menu_item_name) as menu_item_name, SUM(net_sales) as item_total_sales')
                ->groupBy('franchise_store', 'item_id')
                ->get()
                ->groupBy('franchise_store');

            $filename = "pricing-kpi_{$startDate}_{$endDate}.csv";

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
                fputcsv($fh, [
                    'franchise_store',
                    'store_total_sales',
                    'store_total_orders',
                    'item_id',
                    'item_name',
                    'item_total_sales',
                ]);

                foreach ($stores as $store) {
                    $storeRow = $storeTotals->get($store);
                    $storeTotalSales = number_format((float) ($storeRow->total_sales ?? 0), 2, '.', '');
                    $storeTotalOrders = (int) ($storeRow->total_orders ?? 0);

                    $itemsForStore = $itemTotals->get($store, collect())->keyBy('item_id');

                    foreach ($itemIds as $itemId) {
                        $itemRow = $itemsForStore->get($itemId);

                        fputcsv($fh, [
                            $store,
                            $storeTotalSales,
                            $storeTotalOrders,
                            $itemId,
                            $itemRow->menu_item_name ?? '',
                            number_format((float) ($itemRow->item_total_sales ?? 0), 2, '.', ''),
                        ]);
                    }
                }
            } finally {
                fclose($fh);
            }

            return response()->download($tmpPath, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error('Pricing KPI export failed', [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'stores' => $stores,
                'item_ids' => $itemIds,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 500);
        }
    }
}
