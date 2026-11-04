<?php

declare(strict_types=1);

namespace App\Reports\Engine;

use App\Http\Requests\ReportDatasetQueryRequest;
use App\Reports\Engine\Formula\Parser;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Checks a report definition (v1) against the merged catalog: datasets,
 * grain, columns, formulas, filters, sort, period and store scope. Runs on
 * every preview and run, and at template save. A report has any number of
 * datasets, columns, formulas, stores and days.
 *
 * The grain may be empty (one totals row, or one row per breakdown). A dataset
 * column without an aggregation is a breakdown column: the dataset is grouped
 * by it too. Only one dataset may add breakdown columns outside the grain.
 */
final class ReportDefinitionValidator
{
    public const GRAIN = ['store', 'date', 'employee'];

    /**
     * @param  array<string, array>  $datasets  catalog entries by dataset key
     * @return array<string, array> formula ASTs by column id, in evaluation order
     */
    public function validate(array $definition, array $datasets): array
    {
        $errors = [];

        if (($definition['v'] ?? null) !== 1) {
            $errors['v'][] = 'Only definition version 1 is understood.';
        }

        $grain = array_values(array_unique((array) ($definition['grain'] ?? [])));
        if (array_diff($grain, self::GRAIN) !== []) {
            $errors['grain'][] = 'The grain is none, some or all of: '.implode(', ', self::GRAIN).'.';
        }

        // alias => catalog entry
        $aliases = [];
        $entries = $definition['datasets'] ?? [];
        if ($entries === []) {
            $errors['datasets'][] = 'A report uses at least one dataset.';
        }
        foreach ($entries as $i => $entry) {
            $alias = (string) ($entry['alias'] ?? '');
            $dataset = $datasets[$entry['key'] ?? ''] ?? null;
            if (! preg_match('/^[a-z][a-z0-9_]{0,15}$/', $alias) || isset($aliases[$alias])) {
                $errors["datasets.{$i}.alias"][] = 'Each dataset needs its own short alias (a-z, 0-9, _).';
            } elseif ($dataset === null) {
                $errors["datasets.{$i}.key"][] = 'Unknown dataset '.($entry['key'] ?? '').', or its service is not answering.';
            } elseif (in_array('date', $grain, true) && ($dataset['dated'] ?? true) === false) {
                $errors["datasets.{$i}.key"][] = "{$dataset['label']} is a snapshot of the current state and has no date; take date out of the grain.";
            } elseif (($missing = array_diff($grain, $dataset['grain'])) !== []) {
                $errors["datasets.{$i}.key"][] = "{$dataset['label']} cannot be reported by ".implode(', ', $missing).'.';
            } else {
                $aliases[$alias] = $dataset;
            }
        }

        $ids = $grain;
        $formulas = [];
        // alias => true, for each dataset with a breakdown column outside the grain
        $breakdowns = [];
        $columns = $definition['columns'] ?? [];
        if ($columns === []) {
            $errors['columns'][] = 'A report has at least one column.';
        }
        foreach ($columns as $i => $column) {
            $id = (string) ($column['id'] ?? '');
            if (! preg_match('/^c[0-9]+$/', $id) || in_array($id, $ids, true)) {
                $errors["columns.{$i}.id"][] = 'Each column needs its own id (c1, c2, ...).';
                continue;
            }
            $ids[] = $id;

            if (($column['kind'] ?? 'dataset') === 'formula') {
                try {
                    $formulas[$id] = (new Parser())->parse((string) ($column['expr'] ?? ''));
                } catch (InvalidArgumentException $e) {
                    $errors["columns.{$i}.expr"][] = "{$id}: {$e->getMessage()} In: ".($column['expr'] ?? '');
                }
            } elseif (($message = $this->datasetColumn($column, $aliases)) !== null) {
                $errors["columns.{$i}.column"][] = "{$id}: {$message}";
            } elseif (($column['agg'] ?? null) === null && ! in_array($column['column'], $grain, true)
                && $this->catalogColumn($aliases[$column['alias']], (string) $column['column'])['derived'] === null) {
                $breakdowns[$column['alias']] = true;
            }
        }
        if (count($breakdowns) > 1) {
            $errors['columns'][] = 'Only one dataset can add breakdown columns; the others must match the report\'s grain.';
        }

        foreach ($columns as $i => $column) {
            $id = $column['id'] ?? null;
            foreach (isset($formulas[$id]) ? Parser::references($formulas[$id]) : [] as $reference) {
                if (! in_array($reference, $ids, true)) {
                    $errors["columns.{$i}.expr"][] = "{$id} refers to unknown column {$reference} in: {$column['expr']}";
                }
            }
        }

        $order = $this->order($formulas, $columns, $errors);

        foreach ($definition['filters'] ?? [] as $i => $filter) {
            if (($message = $this->filter($filter, $aliases, $ids)) !== null) {
                $errors["filters.{$i}"][] = $message;
            }
        }

        foreach ($definition['sort'] ?? [] as $i => $sort) {
            if (! in_array($sort['id'] ?? null, $ids, true) || ! in_array($sort['dir'] ?? 'asc', ['asc', 'desc'], true)) {
                $errors["sort.{$i}"][] = 'Sort on a column of the report, asc or desc.';
            }
        }

        // Any length of period. A snapshot dataset ignores it; the run still records it.
        try {
            (new PeriodResolver())->resolve($definition['period'] ?? []);
        } catch (InvalidArgumentException $e) {
            $errors['period'][] = $e->getMessage();
        }

        $stores = $definition['stores'] ?? [];
        $codes = $stores['codes'] ?? [];
        if (! in_array($stores['mode'] ?? null, ['all', 'mine', 'list'], true)) {
            $errors['stores'][] = 'Stores are all, mine or a list.';
        } elseif ($stores['mode'] === 'list' && (! is_array($codes) || $codes === [])) {
            $errors['stores'][] = 'A store list has at least one store code.';
        }

        // No limit unless the definition sets one.
        $limit = $definition['limit'] ?? null;
        if ($limit !== null && (! is_int($limit) || $limit < 1)) {
            $errors['limit'][] = 'The row limit is a whole number of at least 1.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return array_combine($order, array_map(fn (string $id) => $formulas[$id], $order));
    }

    private function datasetColumn(array $column, array $aliases): ?string
    {
        $dataset = $aliases[$column['alias'] ?? ''] ?? null;
        if ($dataset === null) {
            return 'Unknown dataset alias '.($column['alias'] ?? '').'.';
        }

        $offered = $this->catalogColumn($dataset, (string) ($column['column'] ?? ''));
        $agg = $column['agg'] ?? null;

        return match (true) {
            $offered === null => "{$dataset['label']} has no column ".($column['column'] ?? '').'.',
            $offered['derived'] !== null => $agg === null ? null : "{$offered['key']} is computed and takes no aggregation.",
            // A breakdown column: the dataset is grouped by it.
            $agg === null && ($offered['groupable'] ?? false) => null,
            ! in_array($agg, $offered['aggregations'], true) => "{$offered['key']} offers ".(implode(', ', $offered['aggregations']) ?: 'no aggregation').'.',
            default => null,
        };
    }

    private function filter(array $filter, array $aliases, array $ids): ?string
    {
        $op = $filter['op'] ?? null;
        if (! in_array($op, ReportDatasetQueryRequest::OPS, true)) {
            return 'Unknown filter op '.($op ?? '').'.';
        }

        // Only in, not_in and between take a list; the rest take one scalar (is_null and not_null none).
        $value = $filter['value'] ?? null;
        $scalars = is_array($value) && array_is_list($value) && array_filter($value, fn ($v) => ! is_scalar($v)) === [];
        if (in_array($op, ['in', 'not_in'], true) && (! $scalars || $value === [])) {
            return 'Send a list of one or more values.';
        }
        if ($op === 'between' && (! $scalars || count($value) !== 2)) {
            return 'Send exactly two values.';
        }
        if (! in_array($op, ['in', 'not_in', 'between', 'is_null', 'not_null'], true) && ! is_scalar($value)) {
            return 'Send one value.';
        }

        if (isset($filter['id'])) {
            return in_array($filter['id'], $ids, true) ? null : "Unknown column {$filter['id']}.";
        }

        $dataset = $aliases[$filter['alias'] ?? ''] ?? null;
        $offered = $dataset === null ? null : $this->catalogColumn($dataset, (string) ($filter['column'] ?? ''));

        return match (true) {
            $dataset === null => 'Unknown dataset alias '.($filter['alias'] ?? '').'.',
            $offered === null || ! $offered['filterable'] => "{$dataset['label']} cannot be filtered on ".($filter['column'] ?? '').'.',
            default => null,
        };
    }

    /**
     * Formula ids so that each comes after the formulas it reads; a cycle is an error.
     *
     * @return list<string>
     */
    private function order(array $formulas, array $columns, array &$errors): array
    {
        $order = [];
        $state = [];
        $visit = function (string $id, array $path) use (&$visit, &$order, &$state, $formulas, &$errors, $columns): void {
            if (($state[$id] ?? null) === 'done') {
                return;
            }
            if (($state[$id] ?? null) === 'open') {
                $cycle = [...array_slice($path, (int) array_search($id, $path, true)), $id];
                $expr = collect($columns)->firstWhere('id', $id)['expr'] ?? '';
                $errors['columns'][] = 'Formulas refer to each other in a cycle: '.implode(' → ', $cycle)." (in: {$expr})";

                return;
            }
            $state[$id] = 'open';
            foreach (Parser::references($formulas[$id]) as $reference) {
                if (isset($formulas[$reference])) {
                    $visit($reference, [...$path, $id]);
                }
            }
            $state[$id] = 'done';
            $order[] = $id;
        };

        foreach (array_keys($formulas) as $id) {
            $visit($id, []);
        }

        return $order;
    }

    private function catalogColumn(array $dataset, string $key): ?array
    {
        foreach ($dataset['columns'] as $column) {
            if ($column['key'] === $key) {
                return $column;
            }
        }

        return null;
    }
}
