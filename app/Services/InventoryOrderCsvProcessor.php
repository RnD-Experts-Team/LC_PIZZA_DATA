<?php

namespace App\Services;

use App\Models\InventoryOrder;

class InventoryOrderCsvProcessor
{
    public function process(string $filePath): array
    {
        $results = [
            'success' => false,
            'total_rows' => 0,
            'imported_rows' => 0,
            'failed_rows' => [],
            'errors' => [],
        ];

        if (!file_exists($filePath)) {
            $results['errors'][] = 'File not found';
            return $results;
        }

        try {
            $handle = fopen($filePath, 'r');
            if (!$handle) {
                $results['errors'][] = 'Could not open file';
                return $results;
            }

            $rowNumber = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Skip header row
                if ($rowNumber === 1) {
                    continue;
                }

                $results['total_rows']++;

                try {
                    $this->importRow($row);
                    $results['imported_rows']++;
                } catch (\Exception $e) {
                    $results['failed_rows'][] = [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            fclose($handle);
            $results['success'] = true;
        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    private function importRow(array $row): void
    {
        // CSV has 13 columns; column index 3 is skipped (not saved)
        if (count($row) < 13) {
            throw new \Exception('Row does not have all required columns');
        }

        // col 0: delivery_date
        // col 1: invoice_no
        // col 2: store_number
        // col 3: SKIPPED
        // col 4: vendor_number
        // col 5: vendor_name
        // col 6: asn_total
        // col 7: invoice_total
        // col 8: is_manual_asn
        // col 9: is_asn_received
        // col 10: is_ordered
        // col 11: order_status
        // col 12: is_posted

        InventoryOrder::create([
            'delivery_date'   => $this->parseDate($row[0] ?? ''),
            'invoice_no'      => trim($row[1] ?? ''),
            'store_number'    => trim($row[2] ?? ''),
            'vendor_number'   => trim($row[4] ?? ''),
            'vendor_name'     => trim($row[5] ?? ''),
            'asn_total'       => (float) ($row[6] ?? 0),
            'invoice_total'   => (float) ($row[7] ?? 0),
            'is_manual_asn'   => (int) ($row[8] ?? 0) === 1,
            'is_asn_received' => (int) ($row[9] ?? 0) === 1,
            'is_ordered'      => (int) ($row[10] ?? 0) === 1,
            'order_status'    => trim($row[11] ?? ''),
            'is_posted'       => (int) ($row[12] ?? 0) === 1,
        ]);
    }

    private function parseDate(string $value): string
    {
        $value = trim($value);

        // Excel serial date (e.g. 46144) — days since Dec 30, 1899
        if (is_numeric($value)) {
            $unix = ((int) $value - 25569) * 86400;
            return date('Y-m-d', $unix);
        }

        $date = date_create($value);
        if (!$date) {
            throw new \Exception('Invalid date format: ' . $value);
        }

        return date_format($date, 'Y-m-d');
    }
}
