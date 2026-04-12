<?php

namespace App\Console\Commands\Import;

use App\Jobs\ProcessCsvImportJob;
use App\Jobs\RunAggregationRebuildJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Import data from old system API in batches
 *
 * FIX A (per-day CSVs):
 * Instead of creating one CSV per model per batch-range, this command now creates
 * one CSV per model per day (YYYY-MM-DD). This prevents “REPLACE” processors from
 * deleting partitions that are split across multiple files/jobs.
 *
 * Usage:
 *   php artisan import:from-old-system --start=2025-11-01 --end=2025-11-30
 *   php artisan import:from-old-system --start=2025-11-01 --end=2025-11-30 --no-aggregation
 */
class ImportFromOldSystemCommand extends Command
{
    protected $signature = 'import:from-old-system
                            {--start= : Start date (Y-m-d format)}
                            {--end= : End date (Y-m-d format)}
                            {--batch-days=7 : Number of days per batch request}
                            {--delay=5 : Seconds to wait between batch imports}
                            {--no-aggregation : Skip aggregation rebuild after import}
                            {--aggregation-type=all : Aggregation type (daily, weekly, monthly, quarterly, yearly, all)}';

    protected $description = 'Import data from old system API in date range batches with auto-aggregation';

    // Old system API configuration
    protected string $oldSystemBaseUrl;
    protected string $oldSystemApiKey;

    /**
     * Model mapping: old_system_export_name => [csv_filename, processor_class]
     * NOTE: filename is a relative path under the uploadId folder.
     * With FIX A, we will suffix each filename with _YYYY-MM-DD before .csv
     */
    protected array $modelMap = [
        'detailOrder' => [
            'filename' => 'detail-orders.csv',
            'processor' => \App\Services\Import\Processors\DetailOrdersProcessor::class
        ],
        'orderLine' => [
            'filename' => 'detail-orderlines.csv',
            'processor' => \App\Services\Import\Processors\OrderLineProcessor::class
        ],
        'summarySale' => [
            'filename' => 'summary-sales.csv',
            'processor' => \App\Services\Import\Processors\SummarySalesProcessor::class
        ],
        'summaryItem' => [
            'filename' => 'summary-items.csv',
            'processor' => \App\Services\Import\Processors\SummaryItemsProcessor::class
        ],
        'summaryTransaction' => [
            'filename' => 'summary-transactions.csv',
            'processor' => \App\Services\Import\Processors\SummaryTransactionsProcessor::class
        ],
        'waste' => [
            'filename' => 'waste-report.csv',
            'processor' => \App\Services\Import\Processors\WasteProcessor::class
        ],
        'cashManagement' => [
            'filename' => 'cash-management.csv',
            'processor' => \App\Services\Import\Processors\CashManagementProcessor::class
        ],
        'financialView' => [
            'filename' => 'financial-views.csv',
            'processor' => \App\Services\Import\Processors\FinancialViewsProcessor::class
        ],
        'altaInventoryCogs' => [
            'filename' => 'inventory/cogs.csv',
            'processor' => \App\Services\Import\Processors\AltaInventoryCogsProcessor::class
        ],
        'altaInventoryIngredientOrder' => [
            'filename' => 'inventory/purchase-orders.csv',
            'processor' => \App\Services\Import\Processors\AltaInventoryIngredientOrdersProcessor::class
        ],
        'altaInventoryIngredientUsage' => [
            'filename' => 'inventory/ingredient-usage.csv',
            'processor' => \App\Services\Import\Processors\AltaInventoryIngredientUsageProcessor::class
        ],
        'altaInventoryWaste' => [
            'filename' => 'inventory/waste.csv',
            'processor' => \App\Services\Import\Processors\AltaInventoryWasteProcessor::class
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        // Load old system configuration
        $this->oldSystemBaseUrl = config('services.old_api.base_url', 'http://localhost');
        $this->oldSystemApiKey = config('services.old_api.api_key', 'null_thing');
    }

    public function handle(): int
    {
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Import Data from Old System');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');

        // Validate inputs
        if (!$this->validateInputs()) {
            return self::FAILURE;
        }

        try {
            $startDate = Carbon::parse($this->option('start'));
            $endDate = Carbon::parse($this->option('end'));
        } catch (\Exception $e) {
            $this->error('Invalid date format. Use Y-m-d (e.g., 2025-01-01)');
            return self::FAILURE;
        }

        if ($startDate > $endDate) {
            $this->error('Start date must be before end date');
            return self::FAILURE;
        }

        $batchDays = (int) $this->option('batch-days');
        $delay = (int) $this->option('delay');
        $skipAggregation = $this->option('no-aggregation');
        $aggregationType = $this->option('aggregation-type');

        $totalDays = $startDate->diffInDays($endDate) + 1;
        $totalBatches = (int) ceil($totalDays / max($batchDays, 1));

        $this->info("📅 Date Range: {$startDate->toDateString()} to {$endDate->toDateString()}");
        $this->info("📊 Total Days: {$totalDays}");
        $this->info("📦 Batch Size: {$batchDays} days");
        $this->info("🔢 Total Batches: {$totalBatches}");
        $this->info("⏱️  Delay Between Batches: {$delay} seconds");
        $this->info("⚡ Processing: Jobs will be queued (async)");
        $this->info("🧾 CSV Mode: per-day files (one CSV per model per date)");

        if (!$skipAggregation) {
            $this->info("🔄 Aggregation: {$aggregationType} (will be queued after import)");
        } else {
            $this->warn("⊘ Aggregation: SKIPPED");
        }

        $this->newLine();

        if (!$this->confirm('Do you want to continue?', true)) {
            $this->warn('Import cancelled');
            return self::SUCCESS;
        }

        $this->newLine();

        $successful = 0;
        $failed = 0;
        $totalJobs = 0;

        $progressBar = $this->output->createProgressBar($totalBatches);
        $progressBar->start();

        $currentDate = $startDate->copy();
        $batchNumber = 1;

        while ($currentDate <= $endDate) {
            $batchEnd = $currentDate->copy()->addDays($batchDays - 1);
            if ($batchEnd > $endDate) {
                $batchEnd = $endDate->copy();
            }

            $this->newLine();
            $this->info("\n[Batch {$batchNumber}/{$totalBatches}] {$currentDate->toDateString()} → {$batchEnd->toDateString()}");

            try {
                $jobsDispatched = $this->importBatch($currentDate, $batchEnd);

                if ($jobsDispatched > 0) {
                    $successful++;
                    $totalJobs += $jobsDispatched;
                    $this->info("  ✓ Dispatched {$jobsDispatched} jobs");
                } else {
                    $failed++;
                    $this->error("  ✗ No jobs dispatched");
                }
            } catch (\Exception $e) {
                $failed++;
                $this->error("  ✗ Batch failed: " . $e->getMessage());
                Log::error("Import batch failed", [
                    'start' => $currentDate->toDateString(),
                    'end' => $batchEnd->toDateString(),
                    'exception' => $e->getMessage()
                ]);
            }

            $progressBar->advance();

            if ($currentDate < $endDate && $delay > 0) {
                sleep($delay);
            }

            $currentDate->addDays($batchDays);
            $batchNumber++;
        }

        $progressBar->finish();
        $this->newLine(2);

        // Dispatch aggregation job if not skipped
        $aggregationId = null;
        if (!$skipAggregation && $successful > 0) {
            $this->newLine();
            $this->info('🔄 Dispatching aggregation job...');

            try {
                $aggregationId = $this->dispatchAggregation(
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                    $aggregationType
                );

                $this->info("  ✓ Aggregation job queued (ID: {$aggregationId})");
            } catch (\Exception $e) {
                $this->warn('  ⚠️  Failed to queue aggregation: ' . $e->getMessage());
                Log::error('Failed to dispatch aggregation job', [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        $this->newLine();
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info('  Import Results');
        $this->info('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->info("✅ Successful Batches: {$successful}");
        $this->error("❌ Failed Batches: {$failed}");
        $this->info("📦 Total Batches: {$totalBatches}");
        $this->info("⚡ Total Import Jobs Queued: {$totalJobs}");

        if ($aggregationId) {
            $this->info("🔄 Aggregation Job Queued: {$aggregationId}");
        }

        $this->newLine();
        $this->info("💡 Monitor progress:");
        $this->info("   • Run queue worker: php artisan queue:work");
        $this->info("   • Check logs: storage/logs/laravel.log");

        if ($aggregationId) {
            $this->info("   • Aggregation will run AFTER all import jobs complete");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function validateInputs(): bool
    {
        if (!$this->option('start') || !$this->option('end')) {
            $this->error('Both --start and --end dates are required');
            return false;
        }

        if (empty($this->oldSystemBaseUrl)) {
            $this->error('Old system API base URL not configured');
            return false;
        }

        if (empty($this->oldSystemApiKey)) {
            $this->error('Old system API key not configured');
            return false;
        }

        // Validate aggregation type
        $validTypes = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly', 'all'];
        $aggType = $this->option('aggregation-type');
        if (!in_array($aggType, $validTypes, true)) {
            $this->error("Invalid aggregation type. Must be one of: " . implode(', ', $validTypes));
            return false;
        }

        // Validate batch-days
        $batchDays = (int) $this->option('batch-days');
        if ($batchDays <= 0) {
            $this->error('--batch-days must be a positive integer');
            return false;
        }

        return true;
    }

    /**
     * Import a batch and dispatch queue jobs
     * FIX A: Creates CSVs per day per model, not per batch-range.
     * Returns number of jobs dispatched.
     */
    protected function importBatch(Carbon $startDate, Carbon $endDate): int
    {
        $uploadId = uniqid('import_', true);
        $storagePath = storage_path("app/uploads/{$uploadId}");

        try {
            // Create storage directory
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0755, true);
            }

            $csvFiles = [];

            // FIX A: Fetch per day, per model; save per-day CSV files
            $day = $startDate->copy();
            while ($day <= $endDate) {
                $dateStr = $day->toDateString();

                foreach ($this->modelMap as $modelName => $config) {
                    try {
                        $data = $this->fetchFromOldSystem($modelName, $day, $day);

                        if (empty($data)) {
                            $this->line("  ⚠ {$modelName} ({$dateStr}): No data");
                            continue;
                        }

                        // Save as per-day CSV file (prevents overwriting and partition-splitting)
                        $datedFilename = $this->withDateSuffix($config['filename'], $dateStr);
                        $csvPath = $storagePath . '/' . $datedFilename;

                        $this->saveToCsv($csvPath, $data);

                        $csvFiles[$datedFilename] = [
                            'path' => $csvPath,
                            'processor' => $config['processor'],
                            'records' => count($data),
                            'business_date' => $dateStr,
                        ];

                        $this->line("  ✓ {$modelName} ({$dateStr}): " . number_format(count($data)) . " records");

                    } catch (\Exception $e) {
                        $this->error("  ✗ {$modelName} ({$dateStr}): " . $e->getMessage());
                        Log::error("Failed to fetch {$modelName}", [
                            'exception' => $e->getMessage(),
                            'date' => $dateStr
                        ]);
                    }
                }

                $day->addDay();
            }

            if (empty($csvFiles)) {
                return 0;
            }

            // Initialize progress tracking
            Cache::put("import_progress_{$uploadId}", [
                'status' => 'queued',
                'total_files' => count($csvFiles),
                'processed_files' => 0,
                'total_rows' => 0,
                'current_file' => null,
                'results' => [],
                'storage_path' => $storagePath,
                'started_at' => now()->toISOString(),
                'source' => 'old_system_import',
                'date_range' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString()
                ]
            ], 7200); // 2 hours TTL

            // Dispatch jobs for each CSV file
            $jobsDispatched = 0;
            foreach ($csvFiles as $filename => $info) {
                ProcessCsvImportJob::dispatch(
                    $uploadId,
                    $info['path'],
                    $filename,
                    $info['processor'],
                    count($csvFiles)
                    // If you later update the job to accept business_date explicitly, you can add:
                    // , $info['business_date']
                );
                $jobsDispatched++;
            }


            return $jobsDispatched;

        } catch (\Exception $e) {
            Log::error("Batch import failed", [
                'start' => $startDate->toDateString(),
                'end' => $endDate->toDateString(),
                'error' => $e->getMessage()
            ]);

            // Cleanup on failure
            if (is_dir($storagePath)) {
                $this->deleteDirectory($storagePath);
            }

            throw $e;
        }
    }

    /**
     * Dispatch aggregation job (same pattern as ManualCsvImportController)
     */
    protected function dispatchAggregation(string $startDate, string $endDate, string $type): string
    {
        $aggregationId = uniqid('agg_', true);

        // Initialize progress tracking
        Cache::put("agg_progress_{$aggregationId}", [
            'status' => 'queued',
            'processed' => 0,
            'total' => 0,
            'started_at' => now()->toISOString(),
            'source' => 'old_system_import',
            'date_range' => [
                'start' => $startDate,
                'end' => $endDate
            ]
        ], 7200); // 2 hours TTL

        // Dispatch the aggregation job
        RunAggregationRebuildJob::dispatch(
            $aggregationId,
            $startDate,
            $endDate,
            $type
        );


        return $aggregationId;
    }

    protected function fetchFromOldSystem(string $model, Carbon $startDate, Carbon $endDate): array
    {
        $url = "{$this->oldSystemBaseUrl}/export/{$model}/json/{$startDate->toDateString()}/{$endDate->toDateString()}";

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->oldSystemApiKey}",
                'Accept' => 'application/json',
            ])
                ->timeout(120)
                ->retry(3, 100)
                ->get($url);

            if (!$response->successful()) {
                throw new \Exception("API returned status {$response->status()}");
            }

            $result = $response->json();

            // Handle response format: {"success":true,"record_count":X,"data":[...]}
            if (isset($result['success']) && $result['success'] === true) {
                return $result['data'] ?? [];
            }

            return is_array($result) ? $result : [];

        } catch (\Exception $e) {
            Log::error("Failed to fetch from old system", [
                'model' => $model,
                'url' => $url,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function saveToCsv(string $filepath, array $data): void
    {
        if (empty($data)) {
            return;
        }

        // Ensure directory exists
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $handle = fopen($filepath, 'w');
        if ($handle === false) {
            throw new \Exception("Cannot create CSV file: {$filepath}");
        }

        // Write header
        $headers = array_keys($data[0]);
        fputcsv($handle, $headers);

        // Write data rows
        foreach ($data as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);
    }

    /**
     * Adds _YYYY-MM-DD before the file extension, preserving subfolders.
     * Examples:
     *  - detail-orders.csv -> detail-orders_2025-11-01.csv
     *  - inventory/cogs.csv -> inventory/cogs_2025-11-01.csv
     */
    protected function withDateSuffix(string $relativePath, string $dateStr): string
    {
        $dir = dirname($relativePath);
        $base = pathinfo($relativePath, PATHINFO_FILENAME);
        $ext = pathinfo($relativePath, PATHINFO_EXTENSION);

        $dated = "{$base}_{$dateStr}.{$ext}";

        return ($dir === '.' ? $dated : $dir . '/' . $dated);
    }

    protected function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

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
