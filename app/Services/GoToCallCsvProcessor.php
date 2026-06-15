<?php

namespace App\Services;

use App\Models\GoToCall;
use App\Models\GoToCallParticipant;
use Illuminate\Support\Facades\DB;

class GoToCallCsvProcessor
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
        // Validate row has all required columns
        if (count($row) < 10) {
            throw new \Exception('Row does not have all required columns');
        }

        $storeNumber = $this->parseStoreNumber($row[0]);
        $datetime = $this->parseDatetime($row[1]);
        $duration = $this->parseDuration($row[2]);
        $callResult = trim($row[3] ?? '');
        $from = trim($row[4] ?? '');
        $participants = $this->parseParticipants($row[5] ?? '');
        $status = $this->parseStatus($row[6], $row[7], $row[8], $row[9]);

        $call = GoToCall::create([
            'store_number' => $storeNumber,
            'datetime' => $datetime,
            'duration' => $duration,
            'call_result' => $callResult,
            'from' => $from,
            'status' => $status,
        ]);

        // Create participant records
        foreach ($participants as $participant) {
            GoToCallParticipant::create([
                'go_to_call_id' => $call->id,
                'participant' => $participant,
            ]);
        }
    }

    private function parseStoreNumber(string $value): string
    {
        // Example: "LCF 3795-0001" → "03795-00001"
        // Remove "LCF " prefix and any leading/trailing whitespace
        $cleaned = trim(preg_replace('/^LCF\s+/', '', $value));

        // Split by hyphen and pad each part to 5 digits
        $parts = explode('-', $cleaned);
        if (count($parts) !== 2) {
            throw new \Exception('Invalid store number format: ' . $value);
        }

        return str_pad($parts[0], 5, '0', STR_PAD_LEFT) . '-' . str_pad($parts[1], 5, '0', STR_PAD_LEFT);
    }

    private function parseDatetime(string $value): \DateTime
    {
        $datetime = \DateTime::createFromFormat(\DateTime::ISO8601, $value);
        if (!$datetime) {
            throw new \Exception('Invalid datetime format: ' . $value);
        }

        return $datetime;
    }

    private function parseDuration(string $value): int
    {
        // Expected format: "MM:SS" or "H:MM:SS"
        $parts = explode(':', trim($value));

        if (count($parts) === 2) {
            // MM:SS
            $minutes = (int)$parts[0];
            $seconds = (int)$parts[1];
        } elseif (count($parts) === 3) {
            // H:MM:SS
            $hours = (int)$parts[0];
            $minutes = (int)$parts[1];
            $seconds = (int)$parts[2];
            $minutes += $hours * 60;
        } else {
            throw new \Exception('Invalid duration format: ' . $value);
        }

        return $minutes * 60 + $seconds;
    }

    private function parseParticipants(string $value): array
    {
        if (empty(trim($value))) {
            return [];
        }

        // Split by semicolon and trim each participant
        return array_map(fn($p) => trim($p), explode(';', $value));
    }

    private function parseStatus(string $store, string $storeManager, string $callCenter, string $missed): string
    {
        if ((int)$store === 1) return 'is_store';
        if ((int)$storeManager === 1) return 'is_store_manager';
        if ((int)$callCenter === 1) return 'is_call_center';
        if ((int)$missed === 1) return 'is_missed';

        throw new \Exception('No status column marked as 1');
    }
}
