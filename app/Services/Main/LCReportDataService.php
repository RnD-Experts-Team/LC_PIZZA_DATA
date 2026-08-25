<?php

namespace App\Services\Main;

use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\API\PureIO;
use App\Services\API\Networked;
use App\Services\Aggregation\AggregationService;
use App\Services\Import\Processors\{
    DetailOrdersProcessor,
    OrderLineProcessor,
    DetailTransactionsProcessor,
    SummarySalesProcessor,
    SummaryItemsProcessor,
    SummaryTransactionsProcessor,
    WasteProcessor,
    CashManagementProcessor,
    FinancialViewsProcessor,
    AltaInventoryCogsProcessor,
    AltaInventoryIngredientOrdersProcessor,
    AltaInventoryIngredientUsageProcessor,
    AltaInventoryWasteProcessor
};

/**
 * LCReportDataService - Import Little Caesars report data
 * 
 * UPDATED: Uses existing lcegateway config and API signatures
 */
class LCReportDataService
{
    protected Client $http;
    protected PureIO $pureIO;
    protected Networked $networked;
    protected AggregationService $aggregationService;
    protected string $storeId = '03795';

    protected array $processorMap = [
        'detail-orders' => DetailOrdersProcessor::class,
        'detail-orderlines' => OrderLineProcessor::class,
        'detail-transactions' => DetailTransactionsProcessor::class,
        'summary-sales' => SummarySalesProcessor::class,
        'summary-items' => SummaryItemsProcessor::class,
        'summary-transactions' => SummaryTransactionsProcessor::class,
        'waste-report' => WasteProcessor::class,
        'cash-management' => CashManagementProcessor::class,
        'financial-views' => FinancialViewsProcessor::class,
        'inventorycogs' => AltaInventoryCogsProcessor::class,
        'inventorypurchase-orders' => AltaInventoryIngredientOrdersProcessor::class,
        'inventoryingredient-usage' => AltaInventoryIngredientUsageProcessor::class,
        'inventorywaste' => AltaInventoryWasteProcessor::class,
        'salesentryform-financialview' => FinancialViewsProcessor::class,
    ];

    public function __construct(
        PureIO $pureIO, 
        Networked $networked,
        AggregationService $aggregationService
    ) {
        $this->pureIO = $pureIO;
        $this->networked = $networked;
        $this->aggregationService = $aggregationService;
    }

    public function importReportData(string $selectedDate): bool
    {

        $this->http = new Client(['timeout' => 60]);
        $zipPath = null;
        $extractPath = null;
        $startTime = microtime(true);

        try {
            // Step 1: Fetch access token
            $accessToken = $this->networked->fetchAccessToken($this->http);

            // Step 2: Build URL and HMAC header
            $url = $this->pureIO->buildGetReportUrl($selectedDate);
            $hmacHeader = $this->pureIO->buildHmacHeader($url, 'GET');

            // Step 3: Get blob URI (NOTE: Pass hmacHeader as string, not array)
            $blobUri = $this->networked->getReportBlobUri($this->http, $url, $hmacHeader, $accessToken);

            // Step 4: Download and extract
            $zipPath = $this->networked->downloadZip($this->http, $blobUri);
            $extractPath = $this->pureIO->extractZip($zipPath);

            // Step 5: Process CSV files
            $importedCounts = $this->processExtractedCsv($extractPath, $selectedDate);

            // Step 6: Update aggregations
            $this->aggregationService->updateDailySummaries(Carbon::parse($selectedDate));

            $duration = round(microtime(true) - $startTime, 2);


            return true;

        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);

            Log::error("=" . str_repeat("=", 80));
            Log::error("Failed to import report data for {$selectedDate}");
            Log::error("Duration before failure: {$duration} seconds");
            Log::error("Error: " . $e->getMessage());
            Log::error("Trace: " . $e->getTraceAsString());
            Log::error("=" . str_repeat("=", 80));

            return false;

        } finally {
            if ($zipPath && is_file($zipPath)) {
                @unlink($zipPath);
                Log::debug("Deleted temporary ZIP file: {$zipPath}");
            }
            if ($extractPath && is_dir($extractPath)) {
                $this->pureIO->deleteDirectory($extractPath);
                Log::debug("Deleted extraction directory: {$extractPath}");
            }
        }
    }

    public function processExtractedCsv(string $extractPath, string $selectedDate): array
    {
        $csvFiles = glob("{$extractPath}/*.csv");

        if (empty($csvFiles)) {
            throw new \Exception("No CSV files found in extract path: {$extractPath}");
        }


        $importCounts = [];
        $errors = [];

        foreach ($csvFiles as $csvFile) {
            $filename = basename($csvFile);

            try {
                $processorClass = $this->getProcessorForFile($filename);

                if (!$processorClass) {
                    Log::warning("No processor found for CSV file, skipping: {$filename}");
                    continue;
                }


                $data = $this->readCsvFile($csvFile);

                if (empty($data)) {
                    Log::warning("No data in CSV file: {$filename}");
                    $importCounts[basename($filename, '.csv')] = 0;
                    continue;
                }

                $processor = new $processorClass();
                $count = $processor->process($data, $selectedDate);

                $importCounts[basename($filename, '.csv')] = $count;


            } catch (\Exception $e) {
                $errors[] = [
                    'file' => $filename,
                    'error' => $e->getMessage()
                ];

                Log::error("✗ Failed to process {$filename}: " . $e->getMessage());
            }
        }

        if (!empty($errors)) {
            Log::warning("Import completed with errors", ['errors' => $errors]);
        }

        return $importCounts;
    }

    protected function getProcessorForFile(string $filename): ?string
    {
        $lowerFilename = strtolower($filename);

        foreach ($this->processorMap as $pattern => $processorClass) {
            if (stripos($lowerFilename, $pattern) !== false) {
                return $processorClass;
            }
        }

        return null;
    }

    protected function readCsvFile(string $csvFile): array
    {
        $data = [];

        if (($handle = fopen($csvFile, 'r')) === false) {
            throw new \Exception("Cannot open CSV file: {$csvFile}");
        }

        $headers = fgetcsv($handle);

        if (!$headers) {
            fclose($handle);
            return [];
        }

        $headers = array_map(function($header) {
            $normalized = strtolower(trim($header));
            $normalized = str_replace([' ', '-', '.'], '_', $normalized);
            $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized);
            return $normalized;
        }, $headers);


        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;

            if (count($row) === count($headers)) {
                $data[] = array_combine($headers, $row);
            } else {
                Log::warning("Row {$rowNumber} has different column count than headers", [
                    'file' => basename($csvFile),
                    'expected' => count($headers),
                    'actual' => count($row)
                ]);
            }
        }

        fclose($handle);


        return $data;
    }

    public function importToArchive(string $csvFilePath, string $tableName, string $businessDate): int
    {

        $processorClassName = ucfirst(\Illuminate\Support\Str::camel($tableName)) . 'Processor';
        $processorClass = "App\\Services\\Import\\Processors\\{$processorClassName}";

        if (!class_exists($processorClass)) {
            throw new \Exception("Processor class not found: {$processorClass}");
        }

        $data = $this->readCsvFile($csvFilePath);

        if (empty($data)) {
            Log::warning("No data in CSV file");
            return 0;
        }

        $processor = new class($processorClass) extends \App\Services\Import\Processors\BaseTableProcessor {
            protected $delegateClass;

            public function __construct($delegateClass) {
                $this->delegateClass = $delegateClass;
            }

            protected function getTableName(): string {
                return (new $this->delegateClass())->getTableName();
            }

            protected function getFillableColumns(): array {
                return (new $this->delegateClass())->getFillableColumns();
            }

            protected function getUniqueKeys(): array {
                return (new $this->delegateClass())->getUniqueKeys();
            }

            protected function getDatabaseConnection(): string {
                return 'archive';
            }
        };

        $count = $processor->process($data, $businessDate);


        return $count;
    }
}