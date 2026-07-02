<?php

namespace App\Services;

use App\Models\CleaningReview;
use Illuminate\Support\Facades\DB;

class CleaningReviewCsvProcessor
{
    private const STORE_PREFIX = '03795';
    private const CHUNK_SIZE = 500;
    private const VALID_SCORES = ['Pass', 'Fail', 'Auto Fail'];

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
            if ($header === false || count($header) < 3) {
                $results['errors'][] = 'CSV header row is missing or has fewer than 3 columns';
                fclose($handle);
                return $results;
            }

            // Columns 2..N are the review-place names, read once from the header.
            $placeColumns = [];
            for ($i = 2; $i < count($header); $i++) {
                $placeName = trim((string) $header[$i]);
                if ($placeName !== '') {
                    $placeColumns[$i] = $placeName;
                }
            }

            $buffer = [];
            $rowNumber = 1; // header was row 1

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $results['total_rows']++;

                try {
                    $storeNumber = $this->parseStoreNumber($row[0] ?? '');
                    $date = $this->parseDate($row[1] ?? '');
                } catch (\Exception $e) {
                    // Row-level failure: whole row skipped, one failed_rows entry.
                    $results['failed_rows'][] = [
                        'row' => $rowNumber,
                        'place' => null,
                        'error' => $e->getMessage(),
                    ];
                    continue;
                }

                foreach ($placeColumns as $colIndex => $placeName) {
                    $cell = trim((string) ($row[$colIndex] ?? ''));

                    if ($cell === '') {
                        continue; // not reviewed that day — not an error, skipped silently
                    }

                    $score = $this->normalizeScore($cell);
                    if ($score === null) {
                        $results['failed_rows'][] = [
                            'row' => $rowNumber,
                            'place' => $placeName,
                            'error' => "Invalid score value: '{$cell}' (expected Pass, Fail, or Auto Fail)",
                        ];
                        continue;
                    }

                    $buffer[] = [
                        'store_number' => $storeNumber,
                        'review_place' => $placeName,
                        'score' => $score,
                        'date' => $date,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
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
            CleaningReview::insert($buffer);
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

    private function normalizeScore(string $value): ?string
    {
        $normalized = strtolower(trim($value));

        foreach (self::VALID_SCORES as $canonical) {
            if (strtolower($canonical) === $normalized) {
                return $canonical;
            }
        }

        return null;
    }
}
