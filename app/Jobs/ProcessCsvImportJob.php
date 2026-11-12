<?php

namespace App\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessCsvImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour

    /**
     * Real failures are retried at most 3 times; waiting for another import of
     * the same table (see middleware()) is not a failure, so the job may wait
     * up to retryUntil() for its turn.
     */
    public $maxExceptions = 3;

    /** Seconds before a job that found its table busy tries again. */
    private const BUSY_RETRY_SECONDS = 15;

    /** How long a job may keep waiting for its turn. */
    private const MAX_WAIT_HOURS = 12;

    protected string $uploadId;
    protected string $csvPath;
    protected string $filename;
    protected string $processorClass;
    protected int $totalFiles;

    public function __construct(
        string $uploadId,
        string $csvPath,
        string $filename,
        string $processorClass,
        int $totalFiles
    ) {
        $this->uploadId = $uploadId;
        $this->csvPath = $csvPath;
        $this->filename = $filename;
        $this->processorClass = $processorClass;
        $this->totalFiles = $totalFiles;
    }

    /**
     * Imports of the same table never run side by side. Two files covering the
     * same store-day, imported at once, would both insert its rows (order
     * lines have no unique key) — that is how days ended up doubled.
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping(class_basename($this->processorClass)))
                ->releaseAfter(self::BUSY_RETRY_SECONDS)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(self::MAX_WAIT_HOURS);
    }

    public function handle()
    {
        ini_set('memory_limit', '2G');
        $startTime = microtime(true);

        $succeeded = false;
        $finalAttempt = false;

        try {
            if (!file_exists($this->csvPath)) {
                throw new \Exception("CSV file not found (before processing): {$this->csvPath}");
            }

            $this->updateProgress('processing');

            $processor = new $this->processorClass();
            $result = $this->streamCsvOptimized($processor);

            $duration = round(microtime(true) - $startTime, 2);
            $memoryPeak = round(memory_get_peak_usage(true) / 1024 / 1024, 2);

            $this->updateProgress('completed', [
                'rows' => $result['rows'],
                'dates' => $result['dates'],
                'duration' => $duration,
                'memory_mb' => $memoryPeak
            ]);

            $succeeded = true;
        } catch (\Exception $e) {
            $this->updateProgress('failed', ['error' => $e->getMessage()]);

            // Waiting turns are releases, not attempts that failed: count only real failures.
            $errorsKey = "csv_import_errors_{$this->uploadId}_" . md5($this->filename);
            $errors = Cache::increment($errorsKey);
            Cache::put($errorsKey, $errors, now()->addHours(self::MAX_WAIT_HOURS));
            $finalAttempt = $errors >= $this->maxExceptions;

            Log::error("Import job failed", [
                'upload_id' => $this->uploadId,
                'file' => $this->filename,
                'csv_path' => $this->csvPath,
                'attempt' => method_exists($this, 'attempts') ? $this->attempts() : null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        } finally {
            if ($succeeded || $finalAttempt) {
                if (file_exists($this->csvPath)) {
                    @unlink($this->csvPath);
                }
            }

            $this->cleanupIfComplete();
        }
    }

    protected function streamCsvOptimized($processor): array
    {
        $handle = fopen($this->csvPath, 'r');
        if ($handle === false) {
            throw new \Exception("Cannot open CSV file: {$this->csvPath}");
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            throw new \Exception("CSV file has no headers");
        }

        $headers = array_map(function ($header) {
            $normalized = strtolower(trim($header));
            $normalized = str_replace([' ', '-', '.'], '_', $normalized);
            return preg_replace('/[^a-z0-9_]/', '', $normalized);
        }, $headers);

        $chunkSize = 500;
        $chunk = [];
        $totalRows = 0;
        $detectedDates = [];
        $rowNumber = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count($row) !== count($headers)) {
                Log::warning("Row column count mismatch", [
                    'file' => $this->filename,
                    'row' => $rowNumber,
                    'expected' => count($headers),
                    'actual' => count($row)
                ]);
                continue;
            }

            $rowData = array_combine($headers, $row);

            // FIX: normalize BusinessDate (usually becomes "businessdate")
            if (isset($rowData['businessdate']) && $rowData['businessdate'] !== '' && $rowData['businessdate'] !== null) {
                $rowData['businessdate'] = $this->normalizeToYmd($rowData['businessdate']);
            }

            // In case some files already provide business_date
            if (isset($rowData['business_date']) && $rowData['business_date'] !== '' && $rowData['business_date'] !== null) {
                $rowData['business_date'] = $this->normalizeToYmd($rowData['business_date']);
            }

            $chunk[] = $rowData;

            if (count($chunk) >= $chunkSize) {
                $processed = $this->processChunk($chunk, $processor, $detectedDates);
                $totalRows += $processed;

                $this->updateProgress('processing', ['processed_rows' => $totalRows]);
                $chunk = [];

                if ($rowNumber % 5000 === 0) {
                    gc_collect_cycles();
                }
            }
        }

        if (!empty($chunk)) {
            $processed = $this->processChunk($chunk, $processor, $detectedDates);
            $totalRows += $processed;
        }

        fclose($handle);
        sort($detectedDates);

        return [
            'rows' => $totalRows,
            'dates' => $detectedDates
        ];
    }

    private function normalizeToYmd($value): string
    {
        // Throws if invalid -> job fails clearly instead of inserting bad dates
        return Carbon::parse(trim((string)$value))->toDateString();
    }

    protected function processChunk(array $chunk, $processor, array &$detectedDates): int
    {
        $grouped = $this->groupByDate($chunk);

        $processed = 0;
        foreach ($grouped as $date => $dateData) {
            $processor->process($dateData, $date);
            $processed += count($dateData);

            if (!in_array($date, $detectedDates, true)) {
                $detectedDates[] = $date;
            }
        }

        return $processed;
    }

    protected function groupByDate(array $chunk): array
    {
        $grouped = [];
        foreach ($chunk as $row) {
            $date = $this->extractDate($row);
            $grouped[$date][] = $row;
        }
        return $grouped;
    }

    protected function extractDate(array $row): string
    {
        // Prefer known columns first
        if (!empty($row['businessdate'])) {
            return Carbon::parse($row['businessdate'])->toDateString();
        }
        if (!empty($row['business_date'])) {
            return Carbon::parse($row['business_date'])->toDateString();
        }

        foreach ($row as $key => $value) {
            if (stripos($key, 'date') !== false && !empty($value)) {
                try {
                    return Carbon::parse($value)->toDateString();
                } catch (\Exception $e) {
                    // continue
                }
            }
        }

        return Carbon::today()->toDateString();
    }

    protected function updateProgress(string $status, array $data = [])
    {
        $progress = Cache::get("import_progress_{$this->uploadId}", [
            'status' => 'processing',
            'total_files' => $this->totalFiles,
            'processed_files' => 0,
            'total_rows' => 0,
            'current_file' => null,
            'results' => [],
            'storage_path' => null
        ]);

        $progress['status'] = $status;
        $progress['current_file'] = $this->filename;

        if ($status === 'completed') {
            $progress['processed_files']++;
            $progress['total_rows'] += $data['rows'] ?? 0;
            $progress['results'][] = [
                'file' => $this->filename,
                'status' => 'success',
                'rows' => $data['rows'] ?? 0,
                'dates' => $data['dates'] ?? [],
                'duration' => $data['duration'] ?? 0
            ];

            if ($progress['processed_files'] >= $progress['total_files']) {
                $progress['status'] = 'completed';
            }
        }

        if ($status === 'failed') {
            $progress['results'][] = [
                'file' => $this->filename,
                'status' => 'failed',
                'error' => $data['error'] ?? 'Unknown error'
            ];
        }

        if (isset($data['processed_rows'])) {
            $progress['processed_rows'] = $data['processed_rows'];
        }

        $progress['updated_at'] = now()->toISOString();

        Cache::put("import_progress_{$this->uploadId}", $progress, 3600);
    }

    protected function cleanupIfComplete(): void
    {
        $progress = Cache::get("import_progress_{$this->uploadId}");

        if (!$progress || !isset($progress['storage_path'])) {
            return;
        }

        if ($progress['processed_files'] >= $progress['total_files']) {
            $storagePath = $progress['storage_path'];
            if (is_dir($storagePath)) {
                $this->deleteDirectory($storagePath);
            }
        }
    }

    protected function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) return;

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }

        @rmdir($path);
    }
}
