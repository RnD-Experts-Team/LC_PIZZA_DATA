<?php

namespace App\Services\Export;

use App\Services\Database\DatabaseRouter;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use ZipArchive;

/**
 * Rebuilds the Little Caesars daily report ZIP (same zip/CSV naming and CSV
 * column formatting the LC gateway produces) from data already stored in our
 * database, instead of re-downloading it from the LC gateway.
 *
 * Only the 9 report types that are actually imported into a table are
 * covered (see getAvailableReports()). Daily-Projections, Detail-Transactions
 * and Summary-Toppings are not persisted anywhere and are intentionally
 * omitted.
 */
class LcArchiveZipService
{
    public const DEFAULT_STORE = '03795';

    /**
     * Build the zip for a given business date, across every store/location
     * present in the database for that date (no store filtering).
     *
     * $labelStore only controls the zip/CSV file naming (the LC gateway's
     * naming convention embeds a store code), it never filters the data.
     *
     * @return array{path: string, filename: string}
     */
    public function buildZip(string $businessDate, string $labelStore = self::DEFAULT_STORE): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension is not installed.');
        }

        $tmpDir = storage_path('app/export_tmp');
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0775, true);
        }

        $zipFilename = "{$labelStore}_{$businessDate}.zip";
        $zipPath = $tmpDir . '/' . uniqid('lczip_', true) . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create zip file.');
        }

        $tmpCsvPaths = [];

        try {
            foreach ($this->reportDefinitions() as $report) {
                $csvName = "{$report['label']}-{$labelStore}_{$businessDate}.csv";
                $csvPath = $tmpDir . '/' . uniqid('lccsv_', true) . '.csv';
                $tmpCsvPaths[] = $csvPath;

                $this->writeReportCsv($csvPath, $report, $businessDate);

                $zip->addFile($csvPath, $csvName);
            }

            $zip->close();
        } finally {
            foreach ($tmpCsvPaths as $p) {
                if (file_exists($p)) {
                    @unlink($p);
                }
            }
        }

        return ['path' => $zipPath, 'filename' => $zipFilename];
    }

    /**
     * Report labels covered by this export (used by callers that want to
     * show/validate what will be included).
     */
    public function getAvailableReports(): array
    {
        return array_column($this->reportDefinitions(), 'label');
    }

    protected function writeReportCsv(string $path, array $report, string $businessDate): void
    {
        $fh = fopen($path, 'w');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open temp CSV file: {$path}");
        }

        try {
            fputcsv($fh, array_column($report['columns'], 'header'), ',', '"', '\\');

            $rows = $this->fetchRows($report['table'], $businessDate, $report['orderBy']);

            foreach ($rows as $row) {
                $line = [];
                foreach ($report['columns'] as $col) {
                    $line[] = $this->formatValue($col, $row);
                }
                fputcsv($fh, $line, ',', '"', '\\');
            }
        } finally {
            fclose($fh);
        }
    }

    protected function fetchRows(string $table, string $businessDate, array $orderBy): Collection
    {
        $date = Carbon::parse($businessDate);
        $queries = DatabaseRouter::routedQueries($table, $date, $date);

        $all = collect();
        foreach ($queries as $q) {
            foreach ($orderBy as $col) {
                $q->orderBy($col);
            }

            $all = $all->concat($q->get());
        }

        return $all;
    }

    protected function formatValue(array $col, object $row): string
    {
        if (isset($col['compute'])) {
            return (string) ($col['compute'])($row);
        }

        $value = $row->{$col['db']} ?? null;

        return match ($col['type']) {
            'decimal2' => $this->formatDecimal($value, 2),
            'decimal4' => $this->formatDecimal($value, 4),
            'integer' => (string) (int) $value,
            'iso_datetime' => $this->formatIsoDateTime($value),
            'us_datetime' => $this->formatUsDateTime($value),
            'bool_yes_no' => $this->formatBoolYesNo($value),
            default => (string) ($value ?? ''),
        };
    }

    protected function formatDecimal(mixed $value, int $decimals): string
    {
        // The real LC export never leaves a money/quantity field blank (it
        // uses 0.0000), so a NULL/missing DB value is rendered as zero
        // rather than an empty string, which some downstream importers
        // reject as an invalid decimal literal under strict SQL mode.
        return number_format((float) ($value ?? 0), $decimals, '.', '');
    }

    protected function formatIsoDateTime(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        return Carbon::parse($value)->format('Y-m-d\TH:i:s.000\Z');
    }

    protected function formatUsDateTime(mixed $value): string
    {
        if (empty($value)) {
            return '';
        }

        return Carbon::parse($value)->format('m-d-Y h:i:s A');
    }

    protected function formatBoolYesNo(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return ((bool) $value) ? 'Yes' : 'No';
    }

    protected function ageInMinutes(object $row): string
    {
        if (empty($row->waste_date_time) || empty($row->produce_date_time)) {
            return '';
        }

        $waste = Carbon::parse($row->waste_date_time);
        $produce = Carbon::parse($row->produce_date_time);

        return (string) (int) round($produce->diffInMinutes($waste));
    }

    /**
     * Ordered report definitions. Column order MUST match the original LC
     * CSV header order exactly (verified against a real downloaded zip),
     * not the order fields happen to be declared in the import processors.
     */
    protected function reportDefinitions(): array
    {
        return [
            [
                'label' => 'Cash-Management',
                'table' => 'cash_management',
                'orderBy' => ['franchise_store', 'create_datetime', 'till', 'check_type'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'CreateDatetime', 'db' => 'create_datetime', 'type' => 'iso_datetime'],
                    ['header' => 'VerifiedDatetime', 'db' => 'verified_datetime', 'type' => 'iso_datetime'],
                    ['header' => 'Till', 'db' => 'till', 'type' => 'string'],
                    ['header' => 'CheckType', 'db' => 'check_type', 'type' => 'string'],
                    ['header' => 'SystemTotals', 'db' => 'system_totals', 'type' => 'decimal4'],
                    ['header' => 'Verified', 'db' => 'verified', 'type' => 'decimal4'],
                    ['header' => 'Variance', 'db' => 'variance', 'type' => 'decimal4'],
                    ['header' => 'CreatedBy', 'db' => 'created_by', 'type' => 'string'],
                    ['header' => 'VerifiedBy', 'db' => 'verified_by', 'type' => 'string'],
                ],
            ],
            [
                'label' => 'Detail-OrderLines',
                'table' => 'order_line',
                'orderBy' => ['franchise_store', 'date_time_placed', 'order_id', 'item_id'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'DateTimePlaced', 'db' => 'date_time_placed', 'type' => 'iso_datetime'],
                    ['header' => 'DateTimeFulfilled', 'db' => 'date_time_fulfilled', 'type' => 'iso_datetime'],
                    ['header' => 'NetAmount', 'db' => 'net_amount', 'type' => 'decimal4'],
                    ['header' => 'Quantity', 'db' => 'quantity', 'type' => 'integer'],
                    ['header' => 'RoyaltyItem', 'db' => 'royalty_item', 'type' => 'string'],
                    ['header' => 'TaxableItem', 'db' => 'taxable_item', 'type' => 'string'],
                    ['header' => 'OrderId', 'db' => 'order_id', 'type' => 'string'],
                    ['header' => 'ItemId', 'db' => 'item_id', 'type' => 'string'],
                    ['header' => 'MenuItemName', 'db' => 'menu_item_name', 'type' => 'string'],
                    ['header' => 'MenuItemAccount', 'db' => 'menu_item_account', 'type' => 'string'],
                    ['header' => 'BundleName', 'db' => 'bundle_name', 'type' => 'string'],
                    ['header' => 'Employee', 'db' => 'employee', 'type' => 'string'],
                    ['header' => 'OverrideApprovalEmployee', 'db' => 'override_approval_employee', 'type' => 'string'],
                    ['header' => 'OrderPlacedMethod', 'db' => 'order_placed_method', 'type' => 'string'],
                    ['header' => 'OrderFulfilledMethod', 'db' => 'order_fulfilled_method', 'type' => 'string'],
                    ['header' => 'ModifiedOrderAmount', 'db' => 'modified_order_amount', 'type' => 'decimal4'],
                    ['header' => 'ModificationReason', 'db' => 'modification_reason', 'type' => 'string'],
                    ['header' => 'PaymentMethods', 'db' => 'payment_methods', 'type' => 'string'],
                    ['header' => 'Refunded', 'db' => 'refunded', 'type' => 'string'],
                    ['header' => 'TaxIncludedAmount', 'db' => 'tax_included_amount', 'type' => 'decimal4'],
                    // Not persisted by OrderLineProcessor (always blank in source data anyway).
                    ['header' => 'FacturaUniqueId', 'compute' => fn() => ''],
                ],
            ],
            [
                'label' => 'Detail-Orders',
                'table' => 'detail_orders',
                'orderBy' => ['franchise_store', 'order_id', 'transaction_type'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'DateTimePlaced', 'db' => 'date_time_placed', 'type' => 'iso_datetime'],
                    ['header' => 'DateTimeFulfilled', 'db' => 'date_time_fulfilled', 'type' => 'iso_datetime'],
                    ['header' => 'RoyaltyObligation', 'db' => 'royalty_obligation', 'type' => 'decimal4'],
                    ['header' => 'Quantity', 'db' => 'quantity', 'type' => 'integer'],
                    ['header' => 'CustomerCount', 'db' => 'customer_count', 'type' => 'integer'],
                    ['header' => 'OrderId', 'db' => 'order_id', 'type' => 'string'],
                    ['header' => 'TaxableAmount', 'db' => 'taxable_amount', 'type' => 'decimal4'],
                    ['header' => 'NonTaxableAmount', 'db' => 'non_taxable_amount', 'type' => 'decimal4'],
                    ['header' => 'TaxExemptAmount', 'db' => 'tax_exempt_amount', 'type' => 'decimal4'],
                    ['header' => 'NonRoyaltyAmount', 'db' => 'non_royalty_amount', 'type' => 'decimal4'],
                    ['header' => 'SalesTax', 'db' => 'sales_tax', 'type' => 'decimal4'],
                    ['header' => 'Employee', 'db' => 'employee', 'type' => 'string'],
                    ['header' => 'GrossSales', 'db' => 'gross_sales', 'type' => 'decimal4'],
                    ['header' => 'OccupationalTax', 'db' => 'occupational_tax', 'type' => 'decimal4'],
                    ['header' => 'OverrideApprovalEmployee', 'db' => 'override_approval_employee', 'type' => 'string'],
                    ['header' => 'OrderPlacedMethod', 'db' => 'order_placed_method', 'type' => 'string'],
                    ['header' => 'DeliveryTip', 'db' => 'delivery_tip', 'type' => 'decimal4'],
                    ['header' => 'DeliveryTipTax', 'db' => 'delivery_tip_tax', 'type' => 'decimal4'],
                    ['header' => 'OrderFulfilledMethod', 'db' => 'order_fulfilled_method', 'type' => 'string'],
                    ['header' => 'DeliveryFee', 'db' => 'delivery_fee', 'type' => 'decimal4'],
                    ['header' => 'ModifiedOrderAmount', 'db' => 'modified_order_amount', 'type' => 'decimal4'],
                    ['header' => 'DeliveryFeeTax', 'db' => 'delivery_fee_tax', 'type' => 'decimal4'],
                    ['header' => 'ModificationReason', 'db' => 'modification_reason', 'type' => 'string'],
                    ['header' => 'PaymentMethods', 'db' => 'payment_methods', 'type' => 'string'],
                    ['header' => 'DeliveryServiceFee', 'db' => 'delivery_service_fee', 'type' => 'decimal4'],
                    ['header' => 'DeliveryServiceFeeTax', 'db' => 'delivery_service_fee_tax', 'type' => 'decimal4'],
                    ['header' => 'Refunded', 'db' => 'refunded', 'type' => 'string'],
                    ['header' => 'DeliverySmallOrderFee', 'db' => 'delivery_small_order_fee', 'type' => 'decimal4'],
                    ['header' => 'DeliverySmallOrderFeeTax', 'db' => 'delivery_small_order_fee_tax', 'type' => 'decimal4'],
                    ['header' => 'TransactionType', 'db' => 'transaction_type', 'type' => 'string'],
                    ['header' => 'StoreTipAmount', 'db' => 'store_tip_amount', 'type' => 'decimal4'],
                    ['header' => 'PromiseDate', 'db' => 'promise_date', 'type' => 'iso_datetime'],
                    ['header' => 'TaxExemptionId', 'db' => 'tax_exemption_id', 'type' => 'string'],
                    ['header' => 'TaxExemptionEntityName', 'db' => 'tax_exemption_entity_name', 'type' => 'string'],
                    ['header' => 'UserId', 'db' => 'user_id', 'type' => 'string'],
                    // Not persisted separately; identical instant to PromiseDate, just US-formatted.
                    ['header' => 'DateTimePromised', 'compute' => fn($row) => empty($row->promise_date)
                        ? ''
                        : Carbon::parse($row->promise_date)->format('m-d-Y h:i:s A')],
                    ['header' => 'hnrOrder', 'db' => 'hnrOrder', 'type' => 'string'],
                    ['header' => 'BrokenPromise', 'db' => 'broken_promise', 'type' => 'string'],
                    ['header' => 'PortalEligible', 'db' => 'portal_eligible', 'type' => 'string'],
                    ['header' => 'PortalUsed', 'db' => 'portal_used', 'type' => 'string'],
                    ['header' => 'TimeLoadedIntoPortal', 'db' => 'time_loaded_into_portal', 'type' => 'us_datetime'],
                    ['header' => 'PutIntoPortalBeforePromiseTime', 'db' => 'put_into_portal_before_promise_time', 'type' => 'string'],
                    ['header' => 'PortalCompartmentsUsed', 'db' => 'portal_compartments_used', 'type' => 'string'],
                    // Not persisted by DetailOrdersProcessor (aggregator order ref / Mexico factura id).
                    ['header' => 'MarketplaceOrderNumber', 'compute' => fn() => ''],
                    ['header' => 'FacturaUniqueId', 'compute' => fn() => ''],
                ],
            ],
            [
                'label' => 'InventoryWaste',
                'table' => 'alta_inventory_waste',
                'orderBy' => ['franchise_store', 'item_id'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'ItemID', 'db' => 'item_id', 'type' => 'string'],
                    ['header' => 'ItemDescription', 'db' => 'item_description', 'type' => 'string'],
                    ['header' => 'WasteReason', 'db' => 'waste_reason', 'type' => 'string'],
                    ['header' => 'UnitFoodCost', 'db' => 'unit_food_cost', 'type' => 'decimal2'],
                    ['header' => 'Qty', 'db' => 'qty', 'type' => 'decimal2'],
                ],
            ],
            [
                'label' => 'SalesEntryForm-FinancialView',
                'table' => 'financial_views',
                'orderBy' => ['franchise_store', 'area', 'sub_account'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'Area', 'db' => 'area', 'type' => 'string'],
                    ['header' => 'SubAccount', 'db' => 'sub_account', 'type' => 'string'],
                    ['header' => 'Amount', 'db' => 'amount', 'type' => 'decimal4'],
                ],
            ],
            [
                'label' => 'Summary-Items',
                'table' => 'summary_items',
                'orderBy' => ['franchise_store', 'menu_item_name', 'item_id'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'MenuItemName', 'db' => 'menu_item_name', 'type' => 'string'],
                    ['header' => 'MenuItemAccount', 'db' => 'menu_item_account', 'type' => 'string'],
                    ['header' => 'ItemId', 'db' => 'item_id', 'type' => 'string'],
                    ['header' => 'ItemQuantity', 'db' => 'item_quantity', 'type' => 'integer'],
                    ['header' => 'RoyaltyObligation', 'db' => 'royalty_obligation', 'type' => 'decimal4'],
                    ['header' => 'TaxableAmount', 'db' => 'taxable_amount', 'type' => 'decimal4'],
                    ['header' => 'NonTaxableAmount', 'db' => 'non_taxable_amount', 'type' => 'decimal4'],
                    ['header' => 'TaxExemptAmount', 'db' => 'tax_exempt_amount', 'type' => 'decimal4'],
                    ['header' => 'NonRoyaltyAmount', 'db' => 'non_royalty_amount', 'type' => 'decimal4'],
                    ['header' => 'TaxIncludedAmount', 'db' => 'tax_included_amount', 'type' => 'decimal4'],
                ],
            ],
            [
                'label' => 'Summary-Sales',
                'table' => 'summary_sales',
                'orderBy' => ['franchise_store'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'RoyaltyObligation', 'db' => 'royalty_obligation', 'type' => 'decimal4'],
                    ['header' => 'CustomerCount', 'db' => 'customer_count', 'type' => 'integer'],
                    ['header' => 'TaxableAmount', 'db' => 'taxable_amount', 'type' => 'decimal4'],
                    ['header' => 'NonTaxableAmount', 'db' => 'non_taxable_amount', 'type' => 'decimal4'],
                    ['header' => 'TaxExemptAmount', 'db' => 'tax_exempt_amount', 'type' => 'decimal4'],
                    ['header' => 'NonRoyaltyAmount', 'db' => 'non_royalty_amount', 'type' => 'decimal4'],
                    ['header' => 'RefundAmount', 'db' => 'refund_amount', 'type' => 'decimal4'],
                    ['header' => 'SalesTax', 'db' => 'sales_tax', 'type' => 'decimal4'],
                    ['header' => 'GrossSales', 'db' => 'gross_sales', 'type' => 'decimal4'],
                    ['header' => 'OccupationalTax', 'db' => 'occupational_tax', 'type' => 'decimal4'],
                    ['header' => 'DeliveryTip', 'db' => 'delivery_tip', 'type' => 'decimal4'],
                    ['header' => 'DeliveryFee', 'db' => 'delivery_fee', 'type' => 'decimal4'],
                    ['header' => 'DeliveryServiceFee', 'db' => 'delivery_service_fee', 'type' => 'decimal4'],
                    ['header' => 'DeliverySmallOrderFee', 'db' => 'delivery_small_order_fee', 'type' => 'decimal4'],
                    ['header' => 'ModifiedOrderAmount', 'db' => 'modified_order_amount', 'type' => 'decimal4'],
                    ['header' => 'StoreTipAmount', 'db' => 'store_tip_amount', 'type' => 'decimal4'],
                    ['header' => 'PrepaidCashOrders', 'db' => 'prepaid_cash_orders', 'type' => 'decimal4'],
                    ['header' => 'PrepaidNonCashOrders', 'db' => 'prepaid_non_cash_orders', 'type' => 'decimal4'],
                    ['header' => 'PrepaidSales', 'db' => 'prepaid_sales', 'type' => 'decimal4'],
                    ['header' => 'PrepaidDeliveryTip', 'db' => 'prepaid_delivery_tip', 'type' => 'decimal4'],
                    ['header' => 'PrepaidInStoreTipAmount', 'db' => 'prepaid_in_store_tip_amount', 'type' => 'decimal4'],
                    ['header' => 'OverShort', 'db' => 'over_short', 'type' => 'decimal4'],
                    ['header' => 'PreviousDayRefunds', 'db' => 'previous_day_refunds', 'type' => 'decimal4'],
                    ['header' => 'SAF', 'db' => 'saf', 'type' => 'string'],
                    ['header' => 'ManagerNotes', 'db' => 'manager_notes', 'type' => 'string'],
                ],
            ],
            [
                'label' => 'Summary-Transactions',
                'table' => 'summary_transactions',
                'orderBy' => ['franchise_store', 'payment_method', 'sub_payment_method'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'PaymentMethod', 'db' => 'payment_method', 'type' => 'string'],
                    ['header' => 'SubPaymentMethod', 'db' => 'sub_payment_method', 'type' => 'string'],
                    ['header' => 'TotalAmount', 'db' => 'total_amount', 'type' => 'decimal4'],
                    ['header' => 'SAFQty', 'db' => 'saf_qty', 'type' => 'integer'],
                    ['header' => 'SAFTotal', 'db' => 'saf_total', 'type' => 'decimal4'],
                ],
            ],
            [
                'label' => 'Waste-Report',
                'table' => 'waste',
                'orderBy' => ['franchise_store', 'cv_item_id', 'waste_date_time'],
                'columns' => [
                    ['header' => 'FranchiseStore', 'db' => 'franchise_store', 'type' => 'string'],
                    ['header' => 'BusinessDate', 'db' => 'business_date', 'type' => 'string'],
                    ['header' => 'CVItemId', 'db' => 'cv_item_id', 'type' => 'string'],
                    ['header' => 'MenuItemName', 'db' => 'menu_item_name', 'type' => 'string'],
                    ['header' => 'Expired', 'db' => 'expired', 'type' => 'bool_yes_no'],
                    ['header' => 'WasteDateTime', 'db' => 'waste_date_time', 'type' => 'iso_datetime'],
                    ['header' => 'ProduceDateTime', 'db' => 'produce_date_time', 'type' => 'iso_datetime'],
                    ['header' => 'WasteReason', 'db' => 'waste_reason', 'type' => 'string'],
                    ['header' => 'CVOrderId', 'db' => 'cv_order_id', 'type' => 'string'],
                    ['header' => 'WasteType', 'db' => 'waste_type', 'type' => 'string'],
                    ['header' => 'ItemCost', 'db' => 'item_cost', 'type' => 'decimal2'],
                    ['header' => 'Quantity', 'db' => 'quantity', 'type' => 'integer'],
                    // Not stored directly; fully derivable from WasteDateTime - ProduceDateTime.
                    ['header' => 'AgeInMinutes', 'compute' => fn($row) => $this->ageInMinutes($row)],
                ],
            ],
        ];
    }
}
