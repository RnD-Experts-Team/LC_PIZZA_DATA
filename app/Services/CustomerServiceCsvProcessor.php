<?php

namespace App\Services;

use App\Models\CustomerService;
use Illuminate\Support\Facades\DB;

class CustomerServiceCsvProcessor
{
    private const STORE_PREFIX = '03795';
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

        $handle = fopen($filePath, 'r');
        if (!$handle) {
            $results['errors'][] = 'Could not open file';
            return $results;
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                $results['errors'][] = 'CSV file is empty';
                fclose($handle);
                return $results;
            }

            $buffer = [];
            $rowNumber = 1; // header was row 1

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $results['total_rows']++;

                try {
                    $buffer[] = [
                        'store_number' => $this->parseStoreNumber($row[0] ?? ''),
                        'date' => $this->parseDate($row[1] ?? ''),
                        'lobby_in' => $this->parseTime($row[2] ?? ''),
                        'lobby_out' => $this->parseTime($row[3] ?? ''),
                        // column index 4 is not used
                        'drive_thru_in' => $this->parseTime($row[5] ?? ''),
                        'drive_thru_out' => $this->parseTime($row[6] ?? ''),
                        // column index 7 is not used
                        'guest_service' => $this->parseGuestService($row[8] ?? ''),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                } catch (\Exception $e) {
                    $results['failed_rows'][] = [
                        'row' => $rowNumber,
                        'error' => $e->getMessage(),
                    ];
                    continue;
                }

                if (count($buffer) >= self::CHUNK_SIZE) {
                    $results['imported_rows'] += $this->flush($buffer);
                }
            }

            if (!empty($buffer)) {
                $results['imported_rows'] += $this->flush($buffer);
            }

            fclose($handle);
            $results['success'] = true;
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }

    private function flush(array &$buffer): int
    {
        $count = count($buffer);
        DB::transaction(function () use ($buffer) {
            CustomerService::insert($buffer);
        });
        $buffer = [];
        return $count;
    }

    private function parseStoreNumber(string $value): string
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            throw new \Exception('Invalid store number: ' . $value);
        }

        return self::STORE_PREFIX . '-' . str_pad((string) (int) $value, 5, '0', STR_PAD_LEFT);
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

    private function parseTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Excel time-of-day fraction (e.g. 0.291666667 = 07:00:00)
        if (is_numeric($value)) {
            $fraction = ((float) $value) - floor((float) $value);
            $totalSeconds = (int) round($fraction * 86400);
            $totalSeconds %= 86400;

            return sprintf('%02d:%02d:%02d', intdiv($totalSeconds, 3600), intdiv($totalSeconds % 3600, 60), $totalSeconds % 60);
        }

        $time = date_create($value);
        if (!$time) {
            throw new \Exception('Invalid time format: ' . $value);
        }

        return date_format($time, 'H:i:s');
    }

    private function parseGuestService(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            throw new \Exception('Invalid guest service value: ' . $value);
        }

        return round(((float) $value) * 100, 2);
    }
}
