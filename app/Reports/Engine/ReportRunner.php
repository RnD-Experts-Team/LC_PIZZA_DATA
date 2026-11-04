<?php

declare(strict_types=1);

namespace App\Reports\Engine;

use App\Models\UserStoreRole;
use App\Reports\Contract\Column;
use App\Reports\Engine\Formula\Evaluator;
use App\Reports\Engine\Formula\Parser;
use Generator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use SQLite3;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Runs a report definition: one query per dataset grouped to the report's
 * grain (plus the breakdown columns the user picked), a full outer merge of
 * the results on the grain tuple, then derived columns, formula columns (in
 * dependency order), filters on report columns, sort and the definition's own
 * limit, if it has one.
 *
 * Every dataset's rows are staged in SQLite: a per-run file for a run
 * (export: streamed, held nowhere in memory), an in-memory database for the
 * preview (run: paged, capped and within a deadline). Each staging table is
 * keyed by the grain tuple and the breakdown values; the merge reads them all
 * in key order at once, a row of each at a time, and writes the report rows to
 * a `result` table, indexed on the definition's order before the first row goes
 * in, so it is read back in that order without a sort.
 *
 * When "mine" is refused as a whole and each store is asked on its own, and the
 * store is not part of a dataset's key, two stores' rows would share a key: the
 * dataset is then asked grouped by store as well, its rows staged per store and
 * re-aggregated to the report's key in SQLite (see split()).
 *
 * At most one dataset adds breakdown columns (the validator sees to it): it may
 * hold several rows per grain tuple, and every other dataset's row for that
 * tuple repeats on each of them. A tuple that dataset lacks is one row, its
 * breakdown cells empty.
 */
final class ReportRunner
{
    /**
     * Inputs of a derived column are asked for under this prefix, so their
     * aliases never equal a dataset column name (the dataset would filter
     * such a name per row, not on the aggregate).
     */
    private const DERIVED_PREFIX = '_d_';

    /** Rows per staging transaction, and between two heartbeats. */
    private const BATCH = 10000;

    private const JSON = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private readonly FederatedDatasetClient $client,
        private readonly ReportDefinitionValidator $validator,
        private readonly PeriodResolver $periods,
        private readonly Evaluator $evaluator,
        private readonly Parser $parser,
    ) {
    }

    /** Where runs stage their rows, on the local disk. */
    public const STAGING_DIR = 'reports/tmp';

    /** The run's staging database; gone once the run ends. */
    public static function stagingPath(string $name): string
    {
        return self::STAGING_DIR."/{$name}.sqlite";
    }

    /**
     * The preview: $timeout seconds of wall-clock for every dataset query and
     * retry together, at most $datasetRows rows from each dataset and $limit in
     * the result.
     *
     * @param  list<array>  $services  the merged catalog, as FederatedDatasetClient::catalogs() gives it
     * @return array{columns: list<array>, rows: list<list<mixed>>, truncated: bool, meta: array}
     */
    public function run(array $definition, array $services, #[\SensitiveParameter] string $bearer, int $userId, int $timeout, int $limit, int $datasetRows, string $timezone = 'America/New_York'): array
    {
        $started = microtime(true);
        $deadline = $started + $timeout;
        $report = $this->prepare($definition, $services, $userId, $timezone);
        $db = $this->open(':memory:');
        $beat = fn () => null;

        $truncated = false;
        $skipped = [];
        foreach ($report['plans'] as $i => $plan) {
            $this->stage($db, $i, $report['grain'], $plan, function (callable $put, callable $perStore) use ($plan, $report, $bearer, $deadline, $datasetRows, &$truncated, &$skipped) {
                $truncated = $this->page($plan, $bearer, $deadline, $datasetRows, $report['mode'], $put, $perStore, $skipped[$plan['alias']]) || $truncated;
            }, $beat);
        }

        $count = $this->merge($db, $report, $beat);
        $limit = min($definition['limit'] ?? $limit, $limit);
        $rows = iterator_to_array($this->ordered($db, $report, $limit, $beat), false);
        $db->close();

        return [
            'columns' => $report['columns'],
            'rows' => $rows,
            'truncated' => $truncated || $count > $limit,
            'meta' => [
                'row_count' => min($count, $limit),
                'period' => ['from' => $report['from'], 'to' => $report['to']],
                'skipped_stores' => array_filter($skipped),
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ],
        ];
    }

    /**
     * A full run: every row of every dataset, streamed into the per-run staging
     * file, merged there and handed in order to $write(columns, rows) — rows a
     * generator, so the result is never held either. No time limit; $beat is
     * called every BATCH rows and at each phase boundary. The staging file is
     * deleted whatever happens.
     *
     * @param  callable(): void  $beat
     * @param  callable(list<array>, iterable<list<mixed>>): array  $write  returns the files
     * @return array{columns: list<array>, files: array, row_count: int, truncated: bool, meta: array}
     */
    public function export(array $definition, array $services, #[\SensitiveParameter] string $bearer, int $userId, string $name, callable $beat, callable $write, string $timezone = 'America/New_York'): array
    {
        $report = $this->prepare($definition, $services, $userId, $timezone);
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::STAGING_DIR);
        $disk->delete(self::stagingPath($name));

        $db = null;
        try {
            $db = $this->open($disk->path(self::stagingPath($name)));

            $skipped = [];
            foreach ($report['plans'] as $i => $plan) {
                $this->stage($db, $i, $report['grain'], $plan, function (callable $put, callable $perStore) use ($plan, $report, $bearer, &$skipped) {
                    $skipped[$plan['alias']] = $this->stream($plan, $bearer, $report['mode'], $put, $perStore);
                }, $beat);
                $beat();
            }

            $count = $this->merge($db, $report, $beat);
            $beat();

            $limit = $definition['limit'] ?? null;
            $files = $write($report['columns'], $this->ordered($db, $report, $limit, $beat));
            $beat();

            return [
                'columns' => $report['columns'],
                'files' => $files,
                'row_count' => $limit === null ? $count : min($count, $limit),
                'truncated' => $limit !== null && $count > $limit,
                'meta' => [
                    'period' => ['from' => $report['from'], 'to' => $report['to']],
                    'skipped_stores' => array_filter($skipped),
                ],
            ];
        } finally {
            $db?->close();
            $disk->delete(self::stagingPath($name));
        }
    }

    /**
     * The checked definition, ready to run: grain, store codes, period, the
     * output columns, the formulas in order, and per dataset what to ask it
     * for and how its rows fill the report's columns.
     */
    private function prepare(array $definition, array $services, int $userId, string $timezone): array
    {
        $datasets = [];
        foreach ($services as $service) {
            foreach ($service['datasets'] ?? [] as $dataset) {
                $datasets[$dataset['key']] = [...$dataset, 'service' => $service['origin']];
            }
        }

        $formulas = $this->validator->validate($definition, $datasets);
        [$from, $to] = $this->periods->resolve($definition['period'], $timezone);
        $grain = array_values(array_unique($definition['grain']));
        $stores = $this->stores($definition['stores'], $userId);

        $plans = [];
        foreach ($stores === null ? [] : $definition['datasets'] as $entry) {
            $dataset = $datasets[$entry['key']];
            [$aggregates, $plain, $dimensions, $derived, $labels] = $this->plan($entry['alias'], $dataset, $definition['columns'], $grain);

            $body = [
                'stores' => $stores,
                // A snapshot dataset (not dated) gets no period.
                ...(($dataset['dated'] ?? true) ? ['date_from' => $from, 'date_to' => $to] : []),
                'group_by' => [...$grain, ...$dimensions],
                'aggregates' => array_values($aggregates),
                'filters' => array_values(array_map(
                    fn (array $filter) => ['column' => $filter['column'], 'op' => $filter['op'], 'value' => $filter['value'] ?? null],
                    array_filter($definition['filters'] ?? [], fn (array $filter) => ($filter['alias'] ?? null) === $entry['alias']),
                )),
            ];
            $plans[] = [
                'alias' => $entry['alias'],
                'dataset' => $dataset,
                'plain' => $plain,
                'dimensions' => $dimensions,
                'derived' => $derived,
                'body' => $body,
                'split' => $this->split($dataset, $body, $grain, $dimensions, $aggregates, $labels),
            ];
        }

        // Empty cells last either way; ties keep the merge's order.
        $sort = [];
        foreach ([...$definition['sort'] ?? [], ...array_map(fn (string $key) => ['id' => $key], $grain)] as $entry) {
            $sort[$entry['id']] ??= ($entry['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }

        return [
            'grain' => $grain,
            'mode' => $definition['stores']['mode'],
            'from' => $from,
            'to' => $to,
            'plans' => $plans,
            // The dataset that adds breakdown columns, if one does.
            'breakdown' => array_key_first(array_filter($plans, fn (array $plan) => $plan['dimensions'] !== [])),
            'formulas' => $formulas,
            'filters' => array_values(array_filter($definition['filters'] ?? [], fn (array $filter) => isset($filter['id']))),
            'sort' => $sort,
            'columns' => $this->columns($definition, $datasets, $grain),
        ];
    }

    /**
     * What to ask one dataset for: the aggregates, the report column ids they
     * and the breakdown columns fill (id => output key), the breakdown columns
     * (dataset keys outside the grain, grouped on) and the derived columns. A
     * derived column is computed from its inputs, each asked for under its key
     * with DERIVED_PREFIX and the first aggregation the input offers. Last, by
     * aggregate alias, its label and the definition field it comes from, for an
     * error the user reads.
     *
     * @return array{0: array<string, array>, 1: array<string, string>, 2: list<string>, 3: array<string, array>, 4: array<string, array{label: string, field: string}>}
     */
    private function plan(string $alias, array $dataset, array $columns, array $grain): array
    {
        $offered = array_column($dataset['columns'], null, 'key');
        $aggregates = [];
        $plain = [];
        $dimensions = [];
        $derived = [];
        $labels = [];
        foreach ($columns as $n => $column) {
            if (($column['kind'] ?? 'dataset') === 'formula' || $column['alias'] !== $alias) {
                continue;
            }

            $expr = $offered[$column['column']]['derived'] ?? null;
            if ($expr !== null) {
                $ast = $this->parser->parse($expr);
                foreach (Parser::references($ast) as $input) {
                    $aggregates[self::DERIVED_PREFIX.$input] = ['column' => $input, 'fn' => $offered[$input]['aggregations'][0], 'alias' => self::DERIVED_PREFIX.$input];
                    $labels[self::DERIVED_PREFIX.$input] = ['label' => $offered[$input]['label'], 'field' => "columns.{$n}.column"];
                }
                $derived[$column['id']] = $ast;
            } elseif (($column['agg'] ?? null) === null) {
                $plain[$column['id']] = $column['column'];
                if (! in_array($column['column'], [...$grain, ...$dimensions], true)) {
                    $dimensions[] = $column['column'];
                }
            } else {
                $aggregates[$column['id']] = ['column' => $column['column'], 'fn' => $column['agg'], 'alias' => $column['id']];
                $plain[$column['id']] = $column['id'];
                $labels[$column['id']] = ['label' => $column['label'] ?? $offered[$column['column']]['label'], 'field' => "columns.{$n}.agg"];
            }
        }

        return [$aggregates, $plain, $dimensions, $derived, $labels];
    }

    /**
     * How to ask a dataset one store at a time without one store's rows taking
     * another's place. Null when they cannot collide: the store is in the grain
     * or is a breakdown column. Otherwise the dataset is grouped by store too,
     * each aggregate is asked under an internal alias (_a0, _a1, ...; an average
     * as the sum and the count of its column) and `select` re-aggregates the
     * stores' rows to the report's key: sums and counts add up, min and max stay
     * min and max, an average is the summed sum over the summed count. Derived
     * columns are computed afterwards, from the re-aggregated inputs. `error`
     * (its definition field and message) when an aggregate cannot be
     * re-aggregated across stores: the preview's and the run's 422.
     *
     * @return ?array{body: array, select: array<string, string>, error: ?array{field: string, message: string}}
     */
    private function split(array $dataset, array $body, array $grain, array $dimensions, array $aggregates, array $labels): ?array
    {
        if (in_array('store', [...$grain, ...$dimensions], true)) {
            return null;
        }

        $why = "; with your store access that needs one request per store, so it cannot be totalled across stores. Add Store to the report's grain or use a different aggregation.";
        if (! in_array('store', $dataset['grain'], true)) {
            return ['body' => $body, 'select' => [], 'error' => ['field' => 'stores', 'message' => "{$dataset['label']} has no store to ask it by, and your store access needs one request per store."]];
        }

        $asked = [];
        $select = [];
        foreach ($aggregates as $alias => $aggregate) {
            $fns = match ($aggregate['fn']) {
                'avg' => ['sum', 'count'],
                'count_distinct' => [],
                default => [$aggregate['fn']],
            };
            if ($fns === []) {
                return ['body' => $body, 'select' => [], 'error' => ['field' => $labels[$alias]['field'], 'message' => "{$labels[$alias]['label']} counts distinct values{$why}"]];
            }

            $parts = [];
            foreach ($fns as $fn) {
                $parts[] = $part = '_a'.count($asked);
                $asked[] = ['column' => $aggregate['column'], 'fn' => $fn, 'alias' => $part];
            }
            $select[$alias] = match ($aggregate['fn']) {
                'avg' => "CAST(SUM({$parts[0]}) AS REAL) / NULLIF(SUM({$parts[1]}), 0)",
                'min' => "MIN({$parts[0]})",
                'max' => "MAX({$parts[0]})",
                // sum, count
                default => "SUM({$parts[0]})",
            };
        }

        return ['body' => [...$body, 'group_by' => [...$body['group_by'], 'store'], 'aggregates' => $asked], 'select' => $select, 'error' => null];
    }

    /** Store codes to send; [] = every store (global check); null = none at all. */
    private function stores(array $scope, int $userId): ?array
    {
        if ($scope['mode'] === 'list') {
            return array_values(array_unique($scope['codes']));
        }
        if ($scope['mode'] === 'all') {
            return [];
        }

        $codes = UserStoreRole::query()->where('user_id', $userId)->where('active', true)
            ->distinct()->pluck('store_id')->all();

        // A role without a store (NULL) is global, like 'all'.
        return in_array('all', $codes, true) || in_array(null, $codes, true) ? [] : ($codes === [] ? null : $codes);
    }

    /**
     * SQLite3 rather than PDO for its close(): it ends every statement too, so
     * the file can be deleted whatever still holds one (an exception's trace).
     */
    private function open(string $path): SQLite3
    {
        $db = new SQLite3($path);
        $db->enableExceptions(true);
        // Scratch data: no journal, no fsync, sorts spill to disk.
        foreach (['journal_mode = OFF', 'synchronous = OFF', 'temp_store = FILE', 'locking_mode = EXCLUSIVE'] as $pragma) {
            $db->exec("PRAGMA {$pragma}");
        }

        return $db;
    }

    /**
     * One dataset's rows into table d{$i}: g the grain tuple, r the breakdown
     * values (JSON), v its report cells by id (derived columns computed here).
     * $fill gets a callback to hand each row to, as [output key => value], and
     * one to call (once) when the dataset is asked store by store with the
     * plan's split body: it returns the callback for those rows instead, which
     * stages them per store in p{$i}; they are re-aggregated into d{$i} at the
     * end. A repeated key keeps its last row.
     */
    private function stage(SQLite3 $db, int $i, array $grain, array $plan, callable $fill, callable $beat): void
    {
        $db->exec("CREATE TABLE d{$i} (g TEXT NOT NULL, r TEXT NOT NULL, v TEXT NOT NULL, PRIMARY KEY (g, r)) WITHOUT ROWID");
        $insert = $db->prepare("INSERT OR REPLACE INTO d{$i} (g, r, v) VALUES (?, ?, ?)");
        $count = 0;
        $batch = function () use ($db, $beat, &$count) {
            if (++$count % self::BATCH === 0) {
                $db->exec('COMMIT');
                $beat();
                $db->exec('BEGIN');
            }
        };

        $stores = null;
        $perStore = function () use ($db, $i, $grain, $plan, $batch, &$stores): callable {
            // Untyped value columns: each value keeps the type it is bound with.
            $parts = array_column($plan['split']['body']['aggregates'], 'alias');
            $db->exec("CREATE TABLE p{$i} (g TEXT NOT NULL, r TEXT NOT NULL, s TEXT NOT NULL, ".implode('', array_map(fn (string $part) => "{$part}, ", $parts)).'PRIMARY KEY (g, r, s)) WITHOUT ROWID');
            $stores = $db->prepare("INSERT OR REPLACE INTO p{$i} (g, r, s".implode('', array_map(fn (string $part) => ", {$part}", $parts)).') VALUES (?, ?, ?'.str_repeat(', ?', count($parts)).')');

            return function (array $row) use ($stores, $grain, $plan, $parts, $batch) {
                $stores->bindValue(1, json_encode(array_map(fn (string $key) => $row[$key] ?? null, $grain), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), SQLITE3_TEXT);
                $stores->bindValue(2, json_encode(array_map(fn (string $key) => $row[$key] ?? null, $plan['dimensions']), self::JSON), SQLITE3_TEXT);
                $stores->bindValue(3, json_encode($row['store'] ?? null, self::JSON), SQLITE3_TEXT);
                foreach ($parts as $j => $part) {
                    $value = $row[$part] ?? null;
                    match (true) {
                        $value === null => $stores->bindValue($j + 4, null, SQLITE3_NULL),
                        is_int($value) || is_bool($value) => $stores->bindValue($j + 4, (int) $value, SQLITE3_INTEGER),
                        is_float($value) => $stores->bindValue($j + 4, $value, SQLITE3_FLOAT),
                        default => $stores->bindValue($j + 4, (string) $value, SQLITE3_TEXT),
                    };
                }
                $stores->execute();
                $batch();
            };
        };

        $put = function (array $row) use ($insert, $grain, $plan, $batch) {
            $values = [];
            foreach ($plan['plain'] as $id => $key) {
                $values[$id] = $row[$key] ?? null;
            }
            foreach ($plan['derived'] as $id => $ast) {
                $values[$id] = $this->evaluator->evaluate($ast, $this->inputs($ast, $row));
            }

            $insert->bindValue(1, json_encode(array_map(fn (string $key) => $row[$key] ?? null, $grain), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), SQLITE3_TEXT);
            $insert->bindValue(2, json_encode(array_map(fn (string $key) => $row[$key] ?? null, $plan['dimensions']), self::JSON), SQLITE3_TEXT);
            $insert->bindValue(3, json_encode($values, self::JSON), SQLITE3_TEXT);
            $insert->execute();
            $batch();
        };

        $db->exec('BEGIN');
        $fill($put, $perStore);
        if ($stores !== null) {
            $this->regroup($db, $i, $grain, $plan, $put);
        }
        $db->exec('COMMIT');
    }

    /**
     * The stores' rows of dataset $i, re-aggregated to the report's key with the
     * split's `select` (in key order: the GROUP BY reads p{$i}'s primary key, no
     * sort), each handed to $put as a row of the dataset would be: its values as
     * the wire carries them (JSON, so a whole float arrives as an integer, as
     * it does from a single request).
     */
    private function regroup(SQLite3 $db, int $i, array $grain, array $plan, callable $put): void
    {
        $select = $plan['split']['select'];
        $aliases = array_keys($select);
        $rows = $db->query('SELECT g, r'.implode('', array_map(fn (string $sql) => ", {$sql}", $select))." FROM p{$i} GROUP BY g, r");
        while (($row = $rows->fetchArray(SQLITE3_NUM)) !== false) {
            $put([
                ...array_combine($grain, json_decode($row[0], true, 512, JSON_THROW_ON_ERROR)),
                ...array_combine($plan['dimensions'], json_decode($row[1], true, 512, JSON_THROW_ON_ERROR)),
                ...array_combine($aliases, json_decode(json_encode(array_slice($row, 2), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), true, 512, JSON_THROW_ON_ERROR)),
            ]);
        }
        $rows->finalize();
        $db->exec("DROP TABLE p{$i}");
    }

    /** Inputs of a derived column from a dataset row, under the names its expression uses. */
    private function inputs(array $ast, array $row): array
    {
        $inputs = [];
        foreach (Parser::references($ast) as $input) {
            $inputs[$input] = $row[self::DERIVED_PREFIX.$input] ?? null;
        }

        return $inputs;
    }

    /**
     * The full outer merge on the grain tuple: every staging table read in key
     * order at once, one row of each in hand. Each tuple present anywhere is one
     * report row per breakdown row (one when there is none); formulas and
     * filters run on it and what is kept goes to `result`, with a sortable copy
     * of each sort column. The table is indexed on the read order before the
     * first row goes in: the index grows with the inserts (heartbeats and all)
     * and ordered() reads it, with no sort of its own. Returns the rows kept.
     */
    private function merge(SQLite3 $db, array $report, callable $beat): int
    {
        $sort = array_keys($report['sort']);
        // Sort columns without a type keep what is bound: numbers, then text, by value.
        $db->exec('CREATE TABLE result (n INTEGER PRIMARY KEY, '.implode('', array_map(fn (int $j) => "s{$j}, ", array_keys($sort))).'row TEXT NOT NULL)');
        if ($sort !== []) {
            $db->exec('CREATE INDEX result_order ON result ('.$this->order($report).')');
        }
        $insert = $db->prepare('INSERT INTO result ('.implode('', array_map(fn (int $j) => "s{$j}, ", array_keys($sort))).'row) VALUES ('.str_repeat('?, ', count($sort)).'?)');
        $ids = array_column($report['columns'], 'id');

        $cursors = [];
        $heads = [];
        foreach (array_keys($report['plans']) as $i) {
            $cursors[$i] = $db->query("SELECT g, v FROM d{$i} ORDER BY g, r");
            $heads[$i] = $cursors[$i]->fetchArray(SQLITE3_NUM);
        }
        $breakdown = $report['breakdown'];

        $count = 0;
        $db->exec('BEGIN');
        $emit = function (array $values) use ($db, $insert, $report, $sort, $ids, $beat, &$count) {
            foreach ($report['formulas'] as $id => $ast) {
                $values[$id] = $this->evaluator->evaluate($ast, $values);
            }
            if (! $this->kept($values, $report['filters'])) {
                return;
            }

            foreach ($sort as $j => $id) {
                $value = $values[$id] ?? null;
                $number = $value === null ? null : $this->evaluator->number($value);
                match (true) {
                    $value === null => $insert->bindValue($j + 1, null, SQLITE3_NULL),
                    is_int($number) => $insert->bindValue($j + 1, $number, SQLITE3_INTEGER),
                    $number !== null => $insert->bindValue($j + 1, $number, SQLITE3_FLOAT),
                    default => $insert->bindValue($j + 1, (string) $value, SQLITE3_TEXT),
                };
            }
            $insert->bindValue(count($sort) + 1, json_encode(array_map(fn (string $id) => $values[$id] ?? null, $ids), self::JSON), SQLITE3_TEXT);
            $insert->execute();

            if (++$count % self::BATCH === 0) {
                $db->exec('COMMIT');
                $beat();
                $db->exec('BEGIN');
            }
        };

        while (($live = array_filter($heads)) !== []) {
            $key = min(array_map(fn (array $head) => $head[0], $live));
            $values = array_combine($report['grain'], json_decode($key, true));
            foreach ($live as $i => $head) {
                if ($i !== $breakdown && $head[0] === $key) {
                    $values = [...$values, ...json_decode($head[1], true)];
                    $heads[$i] = $cursors[$i]->fetchArray(SQLITE3_NUM);
                }
            }

            if ($breakdown === null || ($heads[$breakdown][0] ?? null) !== $key) {
                $emit($values);

                continue;
            }
            while (($heads[$breakdown][0] ?? null) === $key) {
                $emit([...$values, ...json_decode($heads[$breakdown][1], true)]);
                $heads[$breakdown] = $cursors[$breakdown]->fetchArray(SQLITE3_NUM);
            }
        }
        $db->exec('COMMIT');

        return $count;
    }

    /**
     * The report rows in the definition's order, $limit at most (null: all),
     * read one at a time.
     *
     * @return Generator<int, list<mixed>>
     */
    private function ordered(SQLite3 $db, array $report, ?int $limit, callable $beat): Generator
    {
        $rows = $db->query($this->orderedSql($report, $limit));
        $count = 0;
        while (($row = $rows->fetchArray(SQLITE3_NUM)) !== false) {
            yield json_decode($row[0], true, 512, JSON_THROW_ON_ERROR);
            if (++$count % self::BATCH === 0) {
                $beat();
            }
        }
    }

    /** The read of `result` in the definition's order: result_order's own order, so no sort. */
    private function orderedSql(array $report, ?int $limit): string
    {
        return 'SELECT row FROM result ORDER BY '.$this->order($report).($limit === null ? '' : ' LIMIT '.(int) $limit);
    }

    /** Each sort column with its empty cells last, then the merge's order as the tiebreak. */
    private function order(array $report): string
    {
        $order = [];
        foreach (array_values($report['sort']) as $j => $dir) {
            $order[] = "s{$j} IS NULL, s{$j} ".strtoupper($dir);
        }
        $order[] = 'n';

        return implode(', ', $order);
    }

    /**
     * The preview's rows of one dataset, at most $maxRows, each handed to $put;
     * returns whether the cap cut them short. When "mine" covers a store this
     * dataset does not open to the user, the whole request is a 403: ask store
     * by store and report the stores left out.
     */
    private function page(array $plan, #[\SensitiveParameter] string $bearer, float $deadline, int $maxRows, string $mode, callable $put, callable $perStore, ?array &$skipped): bool
    {
        $skipped = [];
        try {
            $result = $this->client->query($plan['dataset'], $plan['body'], $bearer, $deadline, $maxRows);
        } catch (HttpException $e) {
            $this->refusedTogether($e, $plan['body'], $mode) || throw $e;
            $result = null;
        }
        if ($result !== null) {
            $this->hand($result, $put);

            return $result['truncated'];
        }

        [$body, $put] = $this->byStore($plan, $put, $perStore, preview: true);
        $left = $maxRows;
        $truncated = false;
        foreach ($body['stores'] as $store) {
            if ($left <= 0) {
                return true;
            }

            try {
                $part = $this->client->query($plan['dataset'], [...$body, 'stores' => [$store]], $bearer, $deadline, $left);
            } catch (HttpException $e) {
                if ($e->getStatusCode() !== 403) {
                    throw $e;
                }
                $skipped[] = $store;

                continue;
            }
            $this->hand($part, $put);
            $left -= count($part['rows']);
            $truncated = $truncated || $part['truncated'];
        }

        return $truncated;
    }

    /**
     * A run's rows of one dataset, each handed to $put. "Mine" refused as a
     * whole (403) is asked store by store, every store; returns the stores left out.
     */
    private function stream(array $plan, #[\SensitiveParameter] string $bearer, string $mode, callable $put, callable $perStore): array
    {
        try {
            $this->client->stream($plan['dataset'], $plan['body'], $bearer, $put);

            return [];
        } catch (HttpException $e) {
            $this->refusedTogether($e, $plan['body'], $mode) || throw $e;
        }

        [$body, $put] = $this->byStore($plan, $put, $perStore, preview: false);

        return $this->client->streamByStore($plan['dataset'], $body, $body['stores'], $bearer, $put);
    }

    /** "Mine" refused as a whole (403): worth asking store by store. */
    private function refusedTogether(HttpException $e, array $body, string $mode): bool
    {
        return $e->getStatusCode() === 403 && $mode === 'mine' && count($body['stores']) >= 2;
    }

    /**
     * What to ask each store for and where its rows go: the plan's own body and
     * $put when stores cannot collide, else the split body and the per-store
     * staging. When the split cannot re-aggregate the report's columns: for the
     * preview a validation error on the column (the dashboard shows the first
     * field error), for a run a 422 whose message the run keeps as its error.
     *
     * @return array{0: array, 1: callable}
     */
    private function byStore(array $plan, callable $put, callable $perStore, bool $preview): array
    {
        $split = $plan['split'];
        if ($split === null) {
            return [$plan['body'], $put];
        }
        if ($split['error'] !== null) {
            $preview
                ? throw ValidationException::withMessages([$split['error']['field'] => [$split['error']['message']]])
                : abort(422, $split['error']['message']);
        }

        return [$split['body'], $perStore()];
    }

    /** A page of rows to $put, each as [output key => value]. */
    private function hand(array $result, callable $put): void
    {
        $keys = array_column($result['columns'], 'key');
        foreach ($result['rows'] as $row) {
            $put(array_combine($keys, $row));
        }
    }

    /** Filters on report columns (by `id`); dataset filters (by `alias`) already ran in SQL. */
    private function kept(array $values, array $filters): bool
    {
        foreach ($filters as $filter) {
            $value = $values[$filter['id']] ?? null;
            $target = $filter['value'] ?? null;
            $is = fn ($other) => $this->evaluator->compare($value, $other);

            $keep = match ($filter['op']) {
                'is_null' => $value === null,
                'not_null' => $value !== null,
                default => $value !== null && match ($filter['op']) {
                    'eq' => $is($target) === 0,
                    'neq' => $is($target) !== 0,
                    'gt' => $is($target) > 0,
                    'gte' => $is($target) >= 0,
                    'lt' => $is($target) < 0,
                    'lte' => $is($target) <= 0,
                    'in' => array_filter($target, fn ($t) => $is($t) === 0) !== [],
                    'not_in' => array_filter($target, fn ($t) => $is($t) === 0) === [],
                    'between' => $is($target[0]) >= 0 && $is($target[1]) <= 0,
                    'contains' => str_contains(mb_strtolower((string) $value), mb_strtolower((string) $target)),
                },
            };
            if (! $keep) {
                return false;
            }
        }

        return true;
    }

    /** Grain keys first, then the definition's columns, each with its type. */
    private function columns(array $definition, array $datasets, array $grain): array
    {
        $aliases = array_column($definition['datasets'], 'key', 'alias');
        $first = array_column($datasets[$definition['datasets'][0]['key']]['columns'], null, 'key');

        $columns = array_map(fn (string $key) => [
            'id' => $key,
            'label' => $first[$key]['label'] ?? $key,
            'type' => $first[$key]['type'] ?? 'string',
            'format' => null,
        ], $grain);

        foreach ($definition['columns'] as $column) {
            if (($column['kind'] ?? 'dataset') === 'formula') {
                $type = $column['type'] ?? 'number';
            } else {
                $offered = array_column($datasets[$aliases[$column['alias']]]['columns'], null, 'key')[$column['column']];
                $type = $offered['derived'] !== null || ($column['agg'] ?? null) === null ? $offered['type'] : Column::aggregateType($column['agg'], $offered['type']);
            }
            $columns[] = ['id' => $column['id'], 'label' => $column['label'] ?? $column['id'], 'type' => $type, 'format' => $column['format'] ?? null];
        }

        return $columns;
    }
}
