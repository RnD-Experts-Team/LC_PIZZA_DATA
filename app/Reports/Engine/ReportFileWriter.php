<?php

declare(strict_types=1);

namespace App\Reports\Engine;

use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\BooleanCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * Writes a run's rows to storage/app/private/reports/ in one pass, holding
 * one row at a time: always a CSV with its offset index, and an XLSX in
 * addition when asked. Both have the column labels as their header row
 * (ReportRowReader pages the CSV by the run's stored column order, not by the
 * header). Nothing is left behind when writing fails.
 *
 * The index ({name}.csv.idx) holds the byte offset of every INDEX_EVERY-th data
 * row as an unsigned 64-bit big-endian integer, so ReportRowReader reaches any
 * page without reading what comes before it.
 *
 * The XLSX is streamed (OpenSpout, inline strings). A sheet holds SHEET_ROWS data
 * rows under its header, Excel's most; the next rows go on a new sheet with the
 * header again. A text cell longer than CELL_CHARS, Excel's most, is cut in the
 * XLSX only.
 */
class ReportFileWriter
{
    public const INDEX_EVERY = 1000;

    /** Data rows on one sheet: Excel's 1,048,576 rows, less the header. */
    protected const SHEET_ROWS = 1048575;

    private const CELL_CHARS = 32767;

    private const DIR = 'reports';

    /** Cells starting with one of these are read as formulas by spreadsheet programs. */
    private const FORMULA_STARTS = ['=', '+', '-', '@'];

    /** @return array{csv: string, idx: string, xlsx: string} paths on the local disk */
    public static function paths(string $name): array
    {
        return ['csv' => self::DIR."/{$name}.csv", 'idx' => self::DIR."/{$name}.csv.idx", 'xlsx' => self::DIR."/{$name}.xlsx"];
    }

    /**
     * @param  list<array{id: string, label: string, type: string}>  $columns
     * @param  iterable<list<mixed>>  $rows  cells in column order, read once
     * @param  ?callable(): void  $beat  called just before and after the XLSX is closed (it zips the sheets, which takes a while)
     * @return array{csv_path: string, xlsx_path: ?string, csv_size: int, xlsx_size: ?int, row_count: int}
     */
    public function write(string $name, array $columns, iterable $rows, bool $xlsx, ?callable $beat = null): array
    {
        $beat ??= fn () => null;
        $disk = Storage::disk('local');
        $paths = self::paths($name);
        $disk->makeDirectory(self::DIR);

        $csv = null;
        $index = null;
        $sheet = null;
        $count = 0;
        try {
            $csv = fopen($disk->path($paths['csv']), 'wb');
            $index = fopen($disk->path($paths['idx']), 'wb');
            $labels = array_map(fn (array $column) => (string) $column['label'], $columns);
            fputcsv($csv, array_map($this->csvCell(...), $labels), ',', '"', '');

            $types = array_column($columns, 'type');
            $header = new Row(array_map(fn (string $label) => new StringCell($label, new Style(fontBold: true)), $labels));
            if ($xlsx) {
                $sheet = new Writer();
                $sheet->openToFile($disk->path($paths['xlsx']));
                $this->startSheet($sheet, $header);
            }

            foreach ($rows as $row) {
                if ($count % self::INDEX_EVERY === 0) {
                    fwrite($index, pack('J', ftell($csv)));
                }
                fputcsv($csv, array_map($this->csvCell(...), $row), ',', '"', '');

                if ($sheet !== null) {
                    if ($count > 0 && $count % static::SHEET_ROWS === 0) {
                        $sheet->addNewSheetAndMakeItCurrent();
                        $this->startSheet($sheet, $header);
                    }
                    $sheet->addRow(new Row(array_map(fn ($value, $i) => $this->xlsxCell($value, $types[$i] ?? null), $row, array_keys($row))));
                }
                $count++;
            }

            fclose($csv);
            fclose($index);
            $csv = $index = null;
            if ($sheet !== null) {
                $beat();
                $sheet->close();
                $beat();
            }
        } catch (Throwable $e) {
            foreach ([$csv, $index] as $handle) {
                if ($handle !== null) {
                    fclose($handle);
                }
            }
            try {
                $sheet?->close();
            } catch (Throwable) {
                // Its file is deleted next anyway.
            }
            $disk->delete(array_values($paths));

            throw $e;
        }

        return [
            'csv_path' => $paths['csv'],
            'xlsx_path' => $xlsx ? $paths['xlsx'] : null,
            'csv_size' => $disk->size($paths['csv']),
            'xlsx_size' => $xlsx ? $disk->size($paths['xlsx']) : null,
            'row_count' => $count,
        ];
    }

    private function csvCell(mixed $value): mixed
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_string($value) && $value !== '' && in_array($value[0], self::FORMULA_STARTS, true) && ! is_numeric($value) => "'".$value,
            default => $value,
        };
    }

    /** The header in bold, kept in view. */
    private function startSheet(Writer $writer, Row $header): void
    {
        $writer->getCurrentSheet()->setSheetView((new SheetView())->withFreezeRow(2));
        $writer->addRow($header);
    }

    /** Every cell has an explicit type, so no text is ever read as a formula. */
    private function xlsxCell(mixed $value, ?string $type): Cell
    {
        return match (true) {
            $value === null => new EmptyCell(null),
            is_bool($value) => new BooleanCell($value),
            in_array($type, ['integer', 'number', 'money'], true) && is_numeric($value) => new NumericCell($value + 0),
            default => new StringCell(strlen((string) $value) > self::CELL_CHARS ? mb_substr((string) $value, 0, self::CELL_CHARS) : (string) $value),
        };
    }
}
