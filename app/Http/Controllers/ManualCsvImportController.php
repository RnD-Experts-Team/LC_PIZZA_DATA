<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessCsvImportJob;
use App\Jobs\RunAggregationRebuildJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use ZipArchive;

class ManualCsvImportController extends Controller
{
    protected array $processorMap = [
        'detail_orders' => \App\Services\Import\Processors\DetailOrdersProcessor::class,
        'order_lines' => \App\Services\Import\Processors\OrderLineProcessor::class,
        'summary_sales' => \App\Services\Import\Processors\SummarySalesProcessor::class,
        'summary_items' => \App\Services\Import\Processors\SummaryItemsProcessor::class,
        'summary_transactions' => \App\Services\Import\Processors\SummaryTransactionsProcessor::class,
        'waste' => \App\Services\Import\Processors\WasteProcessor::class,
        'cash_management' => \App\Services\Import\Processors\CashManagementProcessor::class,
        'financial_views' => \App\Services\Import\Processors\FinancialViewsProcessor::class,
        'inventory_cogs' => \App\Services\Import\Processors\AltaInventoryCogsProcessor::class,
        'inventory_orders' => \App\Services\Import\Processors\AltaInventoryIngredientOrdersProcessor::class,
        'inventory_usage' => \App\Services\Import\Processors\AltaInventoryIngredientUsageProcessor::class,
        'inventory_waste' => \App\Services\Import\Processors\AltaInventoryWasteProcessor::class,
    ];

    public function index()
    {
        return view('manual-csv-import', [
            'processors' => [
                'detail_orders' => 'Detail Orders',
                'order_lines' => 'Order Lines',
                'summary_sales' => 'Summary Sales',
                'summary_items' => 'Summary Items',
                'summary_transactions' => 'Summary Transactions',
                'waste' => 'Waste Report',
                'cash_management' => 'Cash Management',
                'financial_views' => 'Financial Views',
                'inventory_cogs' => 'Inventory COGS',
                'inventory_orders' => 'Inventory Orders',
                'inventory_usage' => 'Inventory Usage',
                'inventory_waste' => 'Inventory Waste',
            ]
        ]);
    }

    /**
     * Inspect ZIP contents without processing
     */
    public function inspectZip(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:zip|max:1048576'
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        try {
            $file = $request->file('file');
            $tempId = uniqid('temp_', true);
            $tempPath = storage_path("app/temp/{$tempId}");
            mkdir($tempPath, 0755, true);

            // Extract ZIP
            $zipPath = $tempPath . '/' . $file->getClientOriginalName();
            $file->move($tempPath, $file->getClientOriginalName());

            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) {
                throw new \Exception("Failed to open ZIP");
            }

            $zip->extractTo($tempPath);
            $zip->close();
            @unlink($zipPath);

            // Find all CSVs
            $csvFiles = $this->findCsvFiles($tempPath);

            $csvList = array_map(function ($path) {
                $size = @filesize($path);
                return [
                    'name' => basename($path),
                    'size' => $size ?: null,
                    'size_mb' => $size ? round($size / 1024 / 1024, 2) : null,
                ];
            }, $csvFiles);

            // Store temp path for later processing
            Cache::put("temp_zip_{$tempId}", [
                'path' => $tempPath,
                'files' => $csvFiles
            ], 600); // 10 minutes

            return response()->json([
                'success' => true,
                'temp_id' => $tempId,
                'csv_files' => $csvList
            ]);
        } catch (\Exception $e) {
            Log::error("inspectZip failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|mimes:csv,txt|max:1048576',
            'mappings' => 'required|array|min:1',
            'mappings.*' => 'required|string'
        ]);

        // Support ZIP temp_id flow
        if ($request->has('temp_id')) {
            return $this->uploadFromTemp($request);
        }

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        try {
            $files = $request->file('files');
            $mappings = json_decode($request->input('mappings'), true);

            $uploadId = uniqid('import_', true);
            $storagePath = storage_path("app/uploads/{$uploadId}");
            mkdir($storagePath, 0755, true);

            // Move all CSV files
            $csvFiles = [];
            foreach ($files as $file) {
                $filename = $file->getClientOriginalName();
                $file->move($storagePath, $filename);
                $csvFiles[$filename] = $storagePath . '/' . $filename;
            }

            // Initialize progress
            Cache::put("import_progress_{$uploadId}", [
                'status' => 'queued',
                'total_files' => count($csvFiles),
                'processed_files' => 0,
                'total_rows' => 0,
                'current_file' => null,
                'results' => [],
                'storage_path' => $storagePath,
                'started_at' => now()->toISOString()
            ], 3600);

            // Dispatch jobs
            foreach ($csvFiles as $filename => $csvPath) {
                if (!isset($mappings[$filename]) || !isset($this->processorMap[$mappings[$filename]])) {
                    continue;
                }

                ProcessCsvImportJob::dispatch(
                    $uploadId,
                    $csvPath,
                    $filename,
                    $this->processorMap[$mappings[$filename]],
                    count($csvFiles)
                );
            }

            return response()->json([
                'success' => true,
                'upload_id' => $uploadId,
                'total_files' => count($csvFiles)
            ]);
        } catch (\Exception $e) {
            Log::error("Upload failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    protected function uploadFromTemp(Request $request)
    {
        $tempId = $request->input('temp_id');
        $mappings = json_decode($request->input('mappings'), true);

        $tempData = Cache::get("temp_zip_{$tempId}");
        if (!$tempData) {
            return response()->json(['success' => false, 'message' => 'Temporary files expired'], 404);
        }

        try {
            $uploadId = uniqid('import_', true);
            $storagePath = storage_path("app/uploads/{$uploadId}");
            mkdir($storagePath, 0755, true);

            // Move files from temp to upload directory
            $csvFiles = [];
            foreach ($tempData['files'] as $tempPath) {
                if (!file_exists($tempPath)) {
                    continue;
                }

                $filename = basename($tempPath);

                // Only include mapped files
                if (!isset($mappings[$filename])) {
                    continue;
                }

                $newPath = $storagePath . '/' . $filename;

                if (@rename($tempPath, $newPath)) {
                    $csvFiles[$filename] = $newPath;
                }
            }

            // Cleanup temp directory
            if (is_dir($tempData['path'])) {
                $this->deleteDirectory($tempData['path']);
            }
            Cache::forget("temp_zip_{$tempId}");

            if (empty($csvFiles)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid files found or no files mapped'
                ], 400);
            }

            // Initialize progress
            Cache::put("import_progress_{$uploadId}", [
                'status' => 'queued',
                'total_files' => count($csvFiles),
                'processed_files' => 0,
                'total_rows' => 0,
                'current_file' => null,
                'results' => [],
                'storage_path' => $storagePath,
                'started_at' => now()->toISOString()
            ], 3600);

            // Dispatch jobs
            $dispatchedCount = 0;
            foreach ($csvFiles as $filename => $csvPath) {
                $processorKey = $mappings[$filename] ?? null;

                if (!$processorKey || !isset($this->processorMap[$processorKey])) {
                    continue;
                }

                ProcessCsvImportJob::dispatch(
                    $uploadId,
                    $csvPath,
                    $filename,
                    $this->processorMap[$processorKey],
                    count($csvFiles)
                );

                $dispatchedCount++;
            }

            if ($dispatchedCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to dispatch jobs. Check logs.'
                ], 500);
            }

            return response()->json([
                'success' => true,
                'upload_id' => $uploadId,
                'total_files' => $dispatchedCount
            ]);
        } catch (\Exception $e) {
            Log::error("uploadFromTemp failed", [
                'temp_id' => $tempId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function progress(string $uploadId)
    {
        $progress = Cache::get("import_progress_{$uploadId}");
        return $progress
            ? response()->json(['success' => true, 'progress' => $progress])
            : response()->json(['success' => false, 'message' => 'Not found'], 404);
    }

    public function reaggregate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'type' => 'required|in:hourly,daily,weekly,monthly,quarterly,yearly,all',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        try {
            $aggregationId = uniqid('agg_', true);

            Cache::put("agg_progress_{$aggregationId}", [
                'status' => 'queued',
                'aggregation_id' => $aggregationId,
                'type' => $request->input('type'),
                'processed' => 0,
                'total' => 0,
                'successful' => 0,
                'failed' => 0,
                'started_at' => now()->toISOString(),
                'updated_at' => now()->toISOString(),
                'current_unit' => null,
                'failed_units' => [],
            ], 60 * 60 * 24);

            RunAggregationRebuildJob::dispatch(
                $aggregationId,
                $request->input('start_date'),
                $request->input('end_date'),
                $request->input('type')
            );

            return response()->json([
                'success' => true,
                'aggregation_id' => $aggregationId,
            ]);
        } catch (\Throwable $e) {
            Log::error('reaggregate failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function aggregationProgress(string $aggregationId)
    {
        $progress = Cache::get("agg_progress_{$aggregationId}");

        return $progress
            ? response()->json(['success' => true, 'progress' => $progress])
            : response()->json(['success' => false, 'message' => 'Not found'], 404);
    }

    protected function findCsvFiles(string $directory): array
    {
        $csvFiles = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'csv') {
                $csvFiles[] = $file->getPathname();
            }
        }

        return $csvFiles;
    }

    protected function deleteDirectory(string $path): void
    {
        if (!is_dir($path))
            return;

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
