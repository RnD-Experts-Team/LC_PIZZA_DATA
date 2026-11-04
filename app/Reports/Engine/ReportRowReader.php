<?php

declare(strict_types=1);

namespace App\Reports\Engine;

/**
 * Pages rows out of a run's CSV as ReportFileWriter wrote it. The page's start
 * is found through the CSV's offset index ({csv}.idx), so any page costs the
 * same: one seek, then at most INDEX_EVERY - 1 rows skipped. The header row
 * holds the column labels and is never read: cells are matched to the run's
 * stored columns by position, come back typed by their column, and have the
 * CSV-injection prefix taken off again.
 */
final class ReportRowReader
{
    /**
     * @param  list<array{type?: string}>  $columns  the run's meta.columns, in file order
     * @return list<list<mixed>>
     */
    public function read(string $path, array $columns, int $offset, int $count): array
    {
        $index = fopen($path.'.idx', 'rb');
        fseek($index, intdiv($offset, ReportFileWriter::INDEX_EVERY) * 8);
        $entry = fread($index, 8);
        fclose($index);
        if ($entry === false || strlen($entry) !== 8) {
            return [];
        }

        $kinds = array_map(fn (array $column) => $column['type'] ?? 'string', array_values($columns));
        $file = fopen($path, 'rb');
        fseek($file, unpack('J', $entry)[1]);

        // fgetcsv() reads whole records, so a cell holding a line break does not shift the page.
        $rows = [];
        $skip = $offset % ReportFileWriter::INDEX_EVERY;
        while (count($rows) < $count && ($cells = fgetcsv($file, null, ',', '"', '')) !== false) {
            if ($cells === [null]) {
                break;
            }
            if ($skip > 0) {
                $skip--;

                continue;
            }
            $rows[] = array_map($this->cell(...), $cells, array_slice($kinds, 0, count($cells)));
        }
        fclose($file);

        return $rows;
    }

    private function cell(?string $value, ?string $type): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'integer', 'number', 'money' => is_numeric($value) ? $value + 0 : $value,
            'boolean' => $value === '1',
            default => preg_match("/^'[=+\-@]/", $value) ? substr($value, 1) : $value,
        };
    }
}
