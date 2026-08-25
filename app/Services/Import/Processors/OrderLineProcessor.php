<?php

namespace App\Services\Import\Processors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderLineProcessor extends BaseTableProcessor
{
    protected function getTableName(): string
    {
        return 'order_line';
    }

    /**
     * Match source-of-truth behavior:
     * replace per (franchise_store, business_date) partition, then insert all rows.
     */
    protected function getImportStrategy(): string
    {
        return self::STRATEGY_REPLACE;
    }

    protected function getFillableColumns(): array
    {
        return [
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
            'refunded',
        ];
    }

    protected function getColumnMapping(): array
    {
        return array_merge(parent::getColumnMapping(), [
            'orderid' => 'order_id',
            'itemid' => 'item_id',
            'datetimeplaced' => 'date_time_placed',
            'datetimefulfilled' => 'date_time_fulfilled',
            'menuitemname' => 'menu_item_name',
            'menuitemaccount' => 'menu_item_account',
            'bundlename' => 'bundle_name',
            'netamount' => 'net_amount',
            'quantity' => 'quantity',
            'royaltyitem' => 'royalty_item',
            'taxableitem' => 'taxable_item',
            'taxincludedamount' => 'tax_included_amount',
            'employee' => 'employee',
            'overrideapprovalemployee' => 'override_approval_employee',
            'orderplacedmethod' => 'order_placed_method',
            'orderfulfilledmethod' => 'order_fulfilled_method',
            'modifiedorderamount' => 'modified_order_amount',
            'modificationreason' => 'modification_reason',
            'paymentmethods' => 'payment_methods',
            'refunded' => 'refunded',
        ]);
    }

    protected function transformData(array $row): array
    {
        $row['date_time_placed'] = $this->parseDateTime($row['date_time_placed'] ?? null);
        $row['date_time_fulfilled'] = $this->parseDateTime($row['date_time_fulfilled'] ?? null);
        $row['net_amount'] = $this->toNumeric($row['net_amount'] ?? null);
        $row['quantity'] = $this->toNumeric($row['quantity'] ?? null);
        $row['tax_included_amount'] = $this->toNumeric($row['tax_included_amount'] ?? null);
        $row['modified_order_amount'] = $this->toNumeric($row['modified_order_amount'] ?? null);

        return $row;
    }

    /**
     * Override import execution so REPLACE happens per partition:
     * (franchise_store, business_date) exactly like replaceOrderLinePartitionKeepAll()
     */
    protected function executeImport(
        array $data,
        string $business_date, // not relied on here; rows carry their own business_date
        string $tableName,
        string $connection,
        string $strategy
    ): int {
        // Group by store + business_date
        $byPartition = [];
        foreach ($data as $r) {
            $store = (string)($r['franchise_store'] ?? '');
            $date  = (string)($r['business_date'] ?? '');

            if ($store === '' || $date === '') {
                // If these are missing, inserting would be dangerous; skip + log
                Log::warning("OrderLine row missing partition keys; skipping", [
                    'table' => $tableName,
                    'franchise_store' => $r['franchise_store'] ?? null,
                    'business_date' => $r['business_date'] ?? null,
                ]);
                continue;
            }

            $key = $store . '|' . $date;
            $byPartition[$key][] = $r;
        }

        if (empty($byPartition)) {
            return 0;
        }

        $chunkSize = $this->getChunkSize();
        $totalImported = 0;

        foreach ($byPartition as $key => $partitionRows) {
            if (empty($partitionRows)) {
                continue;
            }

            $store = $partitionRows[0]['franchise_store'];
            $date  = $partitionRows[0]['business_date'];

            $importedThisPartition = DB::connection($connection)->transaction(function () use (
                $connection, $tableName, $store, $date, $partitionRows, $chunkSize
            ) {
                // Delete only the matching partition (store + date) — but only
                // the first time this processor instance sees it (see
                // BaseTableProcessor::deletePartitionOnce()), so a manual
                // upload that streams the same partition across multiple
                // chunks doesn't wipe out rows a prior chunk just inserted.
                $this->deletePartitionOnce($connection, $tableName, $store, $date);

                $inserted = 0;

                // Insert all rows (keep all rows from CSV)
                foreach (array_chunk($partitionRows, $chunkSize) as $batch) {
                    DB::connection($connection)->table($tableName)->insert($batch);
                    $inserted += count($batch);
                }

                return $inserted;
            });

            $totalImported += $importedThisPartition;
        }

        return $totalImported;
    }
}
