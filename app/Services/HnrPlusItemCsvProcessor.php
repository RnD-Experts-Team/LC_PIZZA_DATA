<?php

namespace App\Services;

use App\Models\HnrPlusItem;
use Illuminate\Support\Facades\DB;

class HnrPlusItemCsvProcessor
{
    private const CHUNK_SIZE = 500;

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
            $chunk = [];

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                // Skip header row
                if ($rowNumber === 1) {
                    continue;
                }

                // Trailing blank export rows (all columns empty) - not real data,
                // don't count them as rows at all.
                if (trim($row[0] ?? '') === '') {
                    continue;
                }

                $results['total_rows']++;

                try {
                    $chunk[] = $this->parseRow($row);
                } catch (\Exception $e) {
                    $results['failed_rows'][] = [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                    ];
                }

                if (count($chunk) >= self::CHUNK_SIZE) {
                    $results['imported_rows'] += $this->flush($chunk);
                }
            }

            if (!empty($chunk)) {
                $results['imported_rows'] += $this->flush($chunk);
            }

            fclose($handle);
            $results['success'] = true;
        } catch (\Exception $e) {
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    private function flush(array &$chunk): int
    {
        $count = count($chunk);
        DB::transaction(function () use ($chunk) {
            HnrPlusItem::insert($chunk);
        });
        $chunk = [];
        return $count;
    }

    private function parseRow(array $row): array
    {
        // CSV has 10 columns:
        // 0: Franchise Restaurant (store number)
        // 1: DateRange ("M/D/YYYY - M/D/YYYY")
        // 2: Item Id
        // 3: Menu Item Name
        // 4: Made
        // 5: Sold
        // 6: Voided
        // 7: Wasted
        // 8: Variance
        // 9: No Inventory Available
        if (count($row) < 10) {
            throw new \Exception('Row does not have all required columns');
        }

        [$weekStart, $weekEnd] = $this->parseDateRange($row[1] ?? '');

        $now = now();

        return [
            'store_number'            => trim($row[0]),
            'week_start'              => $weekStart,
            'week_end'                => $weekEnd,
            'item_id'                 => trim($row[2] ?? ''),
            'item_name'               => trim($row[3] ?? ''),
            'made'                    => (int) ($row[4] ?? 0),
            'sold'                    => (int) ($row[5] ?? 0),
            'voided'                  => (int) ($row[6] ?? 0),
            'wasted'                  => (int) ($row[7] ?? 0),
            'variance'                => (int) ($row[8] ?? 0),
            'no_inventory_available'  => (int) ($row[9] ?? 0),
            'created_at'              => $now,
            'updated_at'              => $now,
        ];
    }

    /**
     * "7/28/2026 - 8/3/2026" -> ['2026-07-28', '2026-08-03']
     */
    private function parseDateRange(string $value): array
    {
        $parts = explode(' - ', trim($value), 2);

        if (count($parts) !== 2) {
            throw new \Exception('Invalid date range format: ' . $value);
        }

        return [
            $this->parseDate($parts[0]),
            $this->parseDate($parts[1]),
        ];
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
