<?php

namespace App\Services;

use App\Models\TransferInOut;

class TransferInOutCsvProcessor
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
        if (count($row) < 10) {
            throw new \Exception('Row does not have all required columns');
        }

        TransferInOut::create([
            'major_category'            => trim($row[0] ?? ''),
            'date'                      => $this->parseDate($row[1] ?? ''),
            'to_store_number'           => trim($row[2] ?? ''),
            'from_store_number'         => trim($row[3] ?? ''),
            'base_unit_per_report_unit' => (float) ($row[4] ?? 0),
            'ing_id'                    => trim($row[5] ?? ''),
            'ing_des'                   => trim($row[6] ?? ''),
            'quantity'                  => (float) ($row[7] ?? 0),
            'unit'                      => trim($row[8] ?? ''),
            'total_cost'                => (float) ($row[9] ?? 0),
            'is_posted'                 => isset($row[10]) ? ((int) $row[10] === 1) : true,
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
