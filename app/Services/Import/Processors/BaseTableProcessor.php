<?php

namespace App\Services\Import\Processors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BaseTableProcessor - Abstract base for all table processors
 * 
 * Supports multiple import strategies:
 * - UPSERT: Insert or update based on unique keys (default)
 * - REPLACE: Delete existing records for date+store, then insert (source of truth)
 * - INSERT: Just insert new records (no conflict handling)
 */
abstract class BaseTableProcessor
{
    // Import strategy constants
    const STRATEGY_UPSERT = 'upsert';
    const STRATEGY_REPLACE = 'replace';
    const STRATEGY_INSERT = 'insert';

    /**
     * Get the base table name (without _hot or _archive suffix)
     */
    abstract protected function getTableName(): string;

    /**
     * Get unique key columns for upsert (only used if strategy is UPSERT)
     */
    protected function getUniqueKeys(): array
    {
        return ['franchise_store', 'business_date'];
    }

    /**
     * Get fillable columns for this table
     */
    abstract protected function getFillableColumns(): array;

    /**
     * Get import strategy for this table
     * Override in child classes to change strategy
     */
    protected function getImportStrategy(): string
    {
        return self::STRATEGY_UPSERT;
    }


    protected function getDatabaseConnection(): string
    {
        return 'operational';
    }

    /**
     * Get table suffix (_hot or _archive)
     */
    protected function getTableSuffix(): string
    {
        return $this->getDatabaseConnection() === 'operational' ? '_hot' : '_archive';
    }

    /**
     * Get chunk size for bulk operations
     * MySQL has a limit of ~65535 placeholders
     * Override this if you need smaller/larger chunks
     */
    protected function getChunkSize(): int
    {
        // Safe default: 500 rows
        // For tables with many columns, you might want to lower this
        return 500;
    }

    /**
     * CSV COLUMN MAPPING - OVERRIDE IN CHILD CLASSES
     * Maps CSV column names (lowercase, no spaces) to database column names
     */
    protected function getColumnMapping(): array
    {
        // Common fields that appear in most tables
        return [
            'franchisestore' => 'franchise_store',
            'businessdate' => 'business_date',
        ];
    }

    /**
     * MAP CSV ROW TO DATABASE COLUMNS
     */
    protected function mapCsvRow(array $csvRow): array
    {
        $columnMap = $this->getColumnMapping();
        $fillable = $this->getFillableColumns();
        $mapped = [];

        foreach ($fillable as $dbColumn) {
            // Find the CSV column name for this DB column
            $csvColumn = array_search($dbColumn, $columnMap);

            if ($csvColumn !== false && isset($csvRow[$csvColumn])) {
                $mapped[$dbColumn] = $csvRow[$csvColumn];
            } elseif (isset($csvRow[$dbColumn])) {
                // Direct match (CSV column same as DB column)
                $mapped[$dbColumn] = $csvRow[$dbColumn];
            } else {
                $mapped[$dbColumn] = null;
            }
        }

        return $mapped;
    }

    /**
     * Transform raw CSV data before import
     * Override this in child classes for custom transformations
     */
    protected function transformData(array $row): array
    {
        // Normalize business_date to YYYY-MM-DD for MySQL DATE columns
        if (array_key_exists('business_date', $row) && $row['business_date'] !== null && $row['business_date'] !== '') {
            $row['business_date'] = $this->parseDate($row['business_date']);
        }

        return $row;
    }


    /**
     * Process and import data for this table
     */
    public function process(array $data, string $business_date): int
    {
        if (empty($data)) {
            Log::warning("No data to import for " . $this->getTableName());
            return 0;
        }

        $tableName = $this->getTableName() . $this->getTableSuffix();
        $connection = $this->getDatabaseConnection();
        $strategy = $this->getImportStrategy();

        // Map, transform and validate data
        $transformed = $this->mapTransformAndValidate($data, $business_date);

        if (empty($transformed)) {
            Log::warning("No valid data after transformation", ['table' => $tableName]);
            return 0;
        }

        // Execute import based on strategy
        $count = $this->executeImport($transformed, $business_date, $tableName, $connection, $strategy);


        return $count;
    }

    /**
     * Map CSV columns, transform all rows
     */
    protected function mapTransformAndValidate(array $data, string $business_date): array
    {
        $transformed = [];

        foreach ($data as $index => $csvRow) {
            // Step 1: Map CSV columns to DB columns
            $row = $this->mapCsvRow($csvRow);

            // Step 2: Add business_date if not present
            if (!isset($row['business_date'])) {
                $row['business_date'] = $business_date;
            }

            // Step 3: Transform data (datetime parsing, etc.)
            $row = $this->transformData($row);

            $transformed[] = $row;
        }

        return $transformed;
    }

    /**
     * Execute import based on strategy with chunking to avoid "too many placeholders" error
     */
    protected function executeImport(
        array $data,
        string $business_date,
        string $tableName,
        string $connection,
        string $strategy
    ): int {
        return DB::connection($connection)->transaction(function () use ($data, $business_date, $tableName, $connection, $strategy) {
            if ($strategy === self::STRATEGY_REPLACE) {
                $this->deleteExistingData($business_date, $tableName, $connection);
            }

            $totalImported = 0;
            $chunkSize = $this->getChunkSize();

            // Chunk the data to avoid MySQL placeholder limits
            foreach (array_chunk($data, $chunkSize) as $chunk) {
                if ($strategy === self::STRATEGY_UPSERT) {
                    DB::connection($connection)
                        ->table($tableName)
                        ->upsert($chunk, $this->getUniqueKeys(), array_keys($chunk[0]));
                } else {
                    // Insert data (for both REPLACE and INSERT strategies)
                    DB::connection($connection)
                        ->table($tableName)
                        ->insert($chunk);
                }

                $totalImported += count($chunk);
            }

            return $totalImported;
        });
    }

    /**
     * Delete existing data for business date
     */
    protected function deleteExistingData(string $business_date, string $tableName, string $connection): void
    {
        $deleted = DB::connection($connection)
            ->table($tableName)
            ->where('business_date', $business_date)
            ->delete();
    }

    /**
     * Partitions already cleared by this processor instance during the current
     * import run (see deletePartitionOnce()).
     */
    protected array $clearedPartitions = [];

    /**
     * Delete a (franchise_store, business_date) partition, but only the first
     * time it's seen by this processor instance.
     *
     * A single manual-upload job streams a CSV in fixed-size row chunks and
     * calls process() once per chunk (see ProcessCsvImportJob::streamCsvOptimized()),
     * reusing the same processor instance across all of them. A REPLACE-strategy
     * processor that unconditionally deletes-then-inserts on every call would
     * wipe out the rows a prior chunk just inserted for the same partition,
     * leaving only whichever chunk happened to touch that partition last. The
     * automated daily import (LCReportDataService) calls process() exactly once
     * per file, so it's unaffected either way.
     */
    protected function deletePartitionOnce(string $connection, string $tableName, string $store, string $date): void
    {
        $key = $tableName . '|' . $store . '|' . $date;

        if (isset($this->clearedPartitions[$key])) {
            return;
        }

        DB::connection($connection)
            ->table($tableName)
            ->where('franchise_store', $store)
            ->where('business_date', $date)
            ->delete();

        $this->clearedPartitions[$key] = true;
    }

    // ========== HELPER METHODS ==========

    protected function parseDateTime(?string $datetime): ?string
    {
        if (empty($datetime)) {
            return null;
        }

        try {
            $dt = \Carbon\Carbon::createFromFormat('m-d-Y h:i:s A', $datetime);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            try {
                $dt = \Carbon\Carbon::parse($datetime);
                return $dt->format('Y-m-d H:i:s');
            } catch (\Exception $e) {
                Log::warning("Failed to parse datetime", [
                    'value' => $datetime,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }
    }

    protected function parseDate(?string $date): ?string
    {
        if (empty($date)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($date)->toDateString();
        } catch (\Exception $e) {
            Log::warning("Failed to parse date", [
                'value' => $date,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    protected function toNumeric($value)
    {
        if ($value === '' || $value === null) {
            return null;
        }
        return is_numeric($value) ? $value : null;
    }
    /**
     * Convert to integer, handling empty strings
     */
    protected function toInteger($value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    protected function toBoolean($value): ?bool
    {
        if ($value === '' || $value === null) {
            return null;
        }

        $normalized = strtolower(trim((string)$value));

        if ($normalized === 'yes' || $normalized === 'true' || $normalized === '1') {
            return true;
        }
        if ($normalized === 'no' || $normalized === 'false' || $normalized === '0') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
