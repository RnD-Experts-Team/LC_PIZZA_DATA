<?php

namespace App\Services\Import\Processors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DetailTransactionsProcessor extends BaseTableProcessor
{
    protected function getTableName(): string
    {
        return 'detail_transactions';
    }

    /**
     * Match source-of-truth behavior:
     * replace per (franchise_store, business_date) partition, then insert all rows.
     * (Multiple tenders can be applied to the same order, so there is no single
     * column guaranteeing per-row uniqueness for an UPSERT.)
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
            'date_time_placed',
            'date_time_fulfilled',
            'transaction_date_time',
            'tendered_amount',
            'payment_method',
            'order_id',
            'sub_payment_method',
            'refund',
            'employee',
            'override_approval_employee',
            'order_placed_method',
            'order_fulfilled_method',
            'po_number',
            'po_entity_name',
            'user_id',
            'terminal_payment_made',
            'card_last4',
            'saf_transaction',
        ];
    }

    protected function getColumnMapping(): array
    {
        return array_merge(parent::getColumnMapping(), [
            'datetimeplaced' => 'date_time_placed',
            'datetimefulfilled' => 'date_time_fulfilled',
            'transactiondatetime' => 'transaction_date_time',
            'tenderedamount' => 'tendered_amount',
            'paymentmethod' => 'payment_method',
            'orderid' => 'order_id',
            'subpaymentmethod' => 'sub_payment_method',
            'refund' => 'refund',
            'employee' => 'employee',
            'overrideapprovalemployee' => 'override_approval_employee',
            'orderplacedmethod' => 'order_placed_method',
            'orderfulfilledmethod' => 'order_fulfilled_method',
            'ponumber' => 'po_number',
            'poentityname' => 'po_entity_name',
            'userid' => 'user_id',
            'terminalpaymentmade' => 'terminal_payment_made',
            'cardlast4' => 'card_last4',
            'saftransaction' => 'saf_transaction',
        ]);
    }

    protected function transformData(array $row): array
    {
        $row['date_time_placed'] = $this->parseDateTime($row['date_time_placed'] ?? null);
        $row['date_time_fulfilled'] = $this->parseDateTime($row['date_time_fulfilled'] ?? null);
        $row['transaction_date_time'] = $this->parseDateTime($row['transaction_date_time'] ?? null);
        $row['tendered_amount'] = $this->toNumeric($row['tendered_amount'] ?? null);

        return $row;
    }

    /**
     * Override import execution so REPLACE happens per partition:
     * (franchise_store, business_date) exactly like OrderLineProcessor.
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
                Log::warning("DetailTransactions row missing partition keys; skipping", [
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
