<?php
// Copied from LC_PIZZA_DATA/app/Reports/Contract/DatasetQueryCompiler.php — contract v1. Edit there, copy everywhere.

declare(strict_types=1);

namespace App\Reports\Contract;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use PDO;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Turns a validated dataset query into SQL. Identifiers come only from the
 * dataset's columns(); every value is bound.
 *
 * The result is always grouped: every plain column (`columns` and `group_by`)
 * is a group key, so a query without aggregates returns distinct rows. That
 * keeps rows unique on their group keys, which is what the keyset cursor
 * pages over (the requested sort first, then the remaining group keys).
 *
 * Stream mode (`stream: true`) sends every row in one answer, as NDJSON:
 * `{"columns":[{"key","type"}...]}`, then one JSON array per row, then
 * `{"done":true,"row_count":N}`. It has no cursor, no limit, no ORDER BY and
 * no statement time limit. A failure part-way ends the answer with
 * `{"error":"..."}` and no done line.
 */
final class DatasetQueryCompiler
{
    public const MAX_LIMIT = 5000;

    public const DEFAULT_TIMEOUT_MS = 15000;

    /** Rows between two flushes of a stream. */
    private const FLUSH_EVERY = 1000;

    /** All a failed stream says; what went wrong goes to the log. */
    private const STREAM_ERROR = 'The dataset query failed before it sent every row.';

    /** Domain separation: app.key signs other things too. */
    private const CURSOR_KEY = 'report-cursor|';

    /** @param  int  $timeoutMs  MySQL max_execution_time of the statement; callers outside the request cannot raise it */
    public function run(ReportDataset $dataset, array $q, int $timeoutMs = self::DEFAULT_TIMEOUT_MS): array
    {
        $started = microtime(true);
        [$query, $select, $order, $fingerprint] = $this->build($dataset, $q, false);

        $limit = min((int) ($q['limit'] ?? self::MAX_LIMIT), self::MAX_LIMIT);
        $records = $this->fetch($query->limit($limit + 1), $timeoutMs);
        $more = count($records) > $limit;
        $records = array_slice($records, 0, $limit);

        $next = null;
        if ($more) {
            $positions = array_flip(array_keys($select));
            $last = end($records);
            $next = $this->encodeCursor($fingerprint, array_map(fn ($key) => $last[$positions[$key]], array_keys($order)));
        }

        $types = array_column($select, 'type');

        return [
            'columns' => $this->columns($select),
            'rows' => array_map(fn (array $record) => $this->cast($record, $types), $records),
            'next_cursor' => $next,
            'truncated' => $more,
            'meta' => [
                'row_count' => count($records),
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'contract_version' => ReportDataset::CONTRACT_VERSION,
            ],
        ];
    }

    /**
     * Every row of the query as NDJSON (see the class comment). The query is
     * checked first, so a bad one is still a 422; once the columns line is out
     * the answer is a 200, and only the error line tells a failure.
     */
    public function stream(ReportDataset $dataset, array $q): StreamedResponse
    {
        [$query, $select] = $this->build($dataset, $q, true);
        $columns = $this->columns($select);
        $types = array_column($select, 'type');

        return new StreamedResponse(function () use ($query, $columns, $types) {
            set_time_limit(0);
            $this->send(['columns' => $columns]);

            $connection = $query->getConnection();
            $pdo = $connection->getReadPdo();
            $mysql = $connection->getDriverName() === 'mysql';
            $buffered = $mysql ? $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : null;
            $statement = null;
            $count = 0;

            try {
                if ($mysql) {
                    // No time limit, rows handed on as MySQL finds them (unbuffered),
                    // and no disconnect while the reader is slow to take them.
                    $pdo->exec('SET SESSION max_execution_time = 0');
                    $pdo->exec('SET SESSION net_write_timeout = 31536000');
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                }

                $statement = $pdo->prepare($query->toSql());
                $connection->bindValues($statement, $connection->prepareBindings($query->getBindings()));
                $statement->execute();
                while (($record = $statement->fetch(PDO::FETCH_NUM)) !== false) {
                    echo json_encode($this->cast($record, $types), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                    if (++$count % self::FLUSH_EVERY === 0) {
                        $this->flush();
                    }
                }
                $this->send(['done' => true, 'row_count' => $count]);
            } catch (Throwable $e) {
                report($e);
                $this->send(['error' => self::STREAM_ERROR]);
            } finally {
                $statement?->closeCursor();
                if ($mysql) {
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                    $pdo->exec('SET SESSION max_execution_time = DEFAULT');
                    $pdo->exec('SET SESSION net_write_timeout = DEFAULT');
                }
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Cache-Control' => 'no-cache',
            // Not held back by a buffering proxy (nginx) in front.
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * The checked query: the builder (grouped, ordered unless it is a stream, no
     * limit), the output by key, the order and the fingerprint a cursor is tied to.
     *
     * @return array{0: Builder, 1: array<string, array>, 2: array<string, string>, 3: string}
     */
    private function build(ReportDataset $dataset, array $q, bool $stream): array
    {
        $errors = [];

        $columns = [];
        foreach ($dataset->columns() as $column) {
            $columns[$column->key] = $column;
        }

        // Output: group keys, then aggregates, each ['sql', 'type', 'group'].
        $select = [];
        foreach (array_unique([...$q['columns'] ?? [], ...$q['group_by'] ?? []]) as $key) {
            $column = $this->find($columns, $key, 'columns', $errors);
            if ($column !== null && ! $column->groupable) {
                $errors['columns'][] = "{$key} cannot be grouped; ask for it through an aggregate.";
            } elseif ($column !== null) {
                $select[$key] = ['sql' => $this->sql($column), 'type' => $column->type, 'group' => true];
            }
        }

        foreach ($q['aggregates'] ?? [] as $i => $aggregate) {
            $column = $this->find($columns, $aggregate['column'], "aggregates.{$i}.column", $errors);
            $alias = $aggregate['alias'] ?? "{$aggregate['column']}_{$aggregate['fn']}";
            if ($column === null) {
                continue;
            }
            // A column that offers an average also gives the sum and the count it is
            // built from, so the engine can total an average over several requests.
            $offered = in_array('avg', $column->aggregations, true)
                ? [...$column->aggregations, 'sum', 'count'] : $column->aggregations;
            if (! in_array($aggregate['fn'], $offered, true)) {
                $errors["aggregates.{$i}.fn"][] = "{$aggregate['fn']} is not offered for {$column->key}.";
            } elseif (isset($select[$alias])) {
                $errors["aggregates.{$i}.alias"][] = "{$alias} is used twice.";
            } else {
                $select[$alias] = [
                    'sql' => $this->aggregate($aggregate['fn'], $this->sql($column)),
                    'type' => Column::aggregateType($aggregate['fn'], $column->type),
                    'group' => false,
                ];
            }
        }

        if ($select === [] && $errors === []) {
            $errors['columns'][] = 'Ask for at least one column or aggregate.';
        }

        // Any period is answered. A snapshot dataset (no date column) ignores it.
        $from = isset($q['date_from']) ? CarbonImmutable::parse($q['date_from'])->toDateString() : null;
        $to = isset($q['date_to']) ? CarbonImmutable::parse($q['date_to'])->toDateString() : null;
        if ($dataset->dateColumn() !== null && ($from === null || $to === null)) {
            $errors['date_from'][] = 'Send the period (date_from and date_to).';
        }

        // Filters on an aggregate alias go to HAVING, the rest to WHERE. A dataset
        // column is always a row filter, even when an aggregate is aliased to its name.
        $where = [];
        $having = [];
        foreach ($q['filters'] ?? [] as $i => $filter) {
            $target = $select[$filter['column']] ?? null;
            $aggregated = $target !== null && ! $target['group'] && ! isset($columns[$filter['column']]);
            if ($aggregated) {
                [$sql, $type] = [$target['sql'], $target['type']];
            } else {
                $column = $this->find($columns, $filter['column'], "filters.{$i}.column", $errors);
                if ($column === null) {
                    continue;
                }
                if (! $column->filterable) {
                    $errors["filters.{$i}.column"][] = "{$column->key} cannot be filtered.";
                    continue;
                }
                [$sql, $type] = [$this->sql($column), $column->type];
            }

            $condition = [$sql, $filter['op'], $filter['value'] ?? null];
            $message = $this->invalidValue($filter['op'], $condition[2], $type);
            if ($message !== null) {
                $errors["filters.{$i}.value"][] = $message;
            } elseif ($aggregated) {
                $having[] = $condition;
            } else {
                $where[] = $condition;
            }
        }

        $order = [];
        foreach ($q['sort'] ?? [] as $i => $sort) {
            $target = $select[$sort['column']] ?? null;
            if ($target === null || ($target['group'] && ! $columns[$sort['column']]->sortable)) {
                $errors["sort.{$i}.column"][] = "Sort on a column or aggregate this query returns; {$sort['column']} is not one.";
            } else {
                $order[$sort['column']] = ($sort['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
            }
        }
        foreach ($select as $key => $item) {
            if ($item['group'] && ! isset($order[$key])) {
                $order[$key] = 'asc';
            }
        }

        $stores = array_values($q['stores'] ?? []);
        $fingerprint = sha1((string) json_encode([$dataset::KEY, $select, $where, $having, $order, $stores, $from, $to]));
        $after = null;
        if ($stream && ($q['cursor'] ?? null) !== null) {
            $errors['cursor'][] = 'A stream sends every row; it takes no cursor.';
        } elseif ($stream && ($q['limit'] ?? null) !== null) {
            $errors['limit'][] = 'A stream sends every row; it takes no limit.';
        } elseif (($q['cursor'] ?? null) !== null) {
            $after = $this->decodeCursor((string) $q['cursor'], $fingerprint);
            if ($after === null || count($after) !== count($order)) {
                $errors['cursor'][] = 'The cursor is invalid or belongs to another query.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $query = $dataset->baseQuery();
        if ($dataset->dateColumn() !== null) {
            $query->whereRaw("{$dataset->dateColumn()} BETWEEN ? AND ?", [$from, $to]);
        }
        if ($stores !== []) {
            $query->whereRaw(...$this->condition($dataset->storeColumn(), 'in', $dataset->storeKeys($stores)));
        }
        foreach ($where as [$sql, $op, $value]) {
            $query->whereRaw(...$this->condition($sql, $op, $value));
        }

        $i = 0;
        foreach ($select as $item) {
            $query->selectRaw("{$item['sql']} as c".$i++);
            if ($item['group']) {
                $query->groupByRaw($item['sql']);
            }
        }
        foreach ($having as [$sql, $op, $value]) {
            $query->havingRaw(...$this->condition($sql, $op, $value));
        }
        if ($after !== null) {
            $query->havingRaw(...$this->keyset($order, $select, $after));
        }
        foreach ($stream ? [] : $order as $key => $dir) {
            $query->orderByRaw("{$select[$key]['sql']} {$dir}");
        }

        return [$query, $select, $order, $fingerprint];
    }

    /** @return list<array{key: string, type: string}> */
    private function columns(array $select): array
    {
        return array_map(fn ($key, $item) => ['key' => $key, 'type' => $item['type']], array_keys($select), $select);
    }

    /** One NDJSON line, sent at once. */
    private function send(array $line): void
    {
        echo json_encode($line, JSON_THROW_ON_ERROR)."\n";
        $this->flush();
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function find(array $columns, string $key, string $field, array &$errors): ?Column
    {
        $column = $columns[$key] ?? null;
        if ($column === null) {
            $errors[$field][] = "Unknown column {$key}.";
        } elseif ($column->derived !== null) {
            $errors[$field][] = "{$key} is derived; the report engine computes it.";
            $column = null;
        }

        return $column;
    }

    private function sql(Column $column): string
    {
        return $column->expr ?? $column->key;
    }

    private function aggregate(string $fn, string $sql): string
    {
        return match ($fn) {
            'count' => "COUNT({$sql})",
            'count_distinct' => "COUNT(DISTINCT {$sql})",
            default => strtoupper($fn)."({$sql})",
        };
    }

    private function invalidValue(string $op, mixed $value, string $type): ?string
    {
        $scalars = fn () => is_array($value) && array_is_list($value)
            && array_filter($value, fn ($v) => ! is_scalar($v)) === [];

        return match ($op) {
            'is_null', 'not_null' => null,
            'in', 'not_in' => ! $scalars() || $value === [] ? 'Send a list of one or more values.' : null,
            'between' => ! $scalars() || count($value) !== 2 ? 'Send exactly two values.' : null,
            'contains' => ! in_array($type, ['string', 'enum'], true) ? 'contains works on text columns only.'
                : (! is_string($value) || $value === '' ? 'Send the text to look for.' : null),
            default => ! is_scalar($value) ? 'Send one value.' : null,
        };
    }

    /** @return array{0: string, 1: array} */
    private function condition(string $sql, string $op, mixed $value): array
    {
        $marks = fn () => implode(', ', array_fill(0, count($value), '?'));

        return match ($op) {
            'eq' => ["{$sql} = ?", [$value]],
            'neq' => ["{$sql} <> ?", [$value]],
            'gt' => ["{$sql} > ?", [$value]],
            'gte' => ["{$sql} >= ?", [$value]],
            'lt' => ["{$sql} < ?", [$value]],
            'lte' => ["{$sql} <= ?", [$value]],
            'in' => $value === [] ? ['1 = 0', []] : ["{$sql} IN ({$marks()})", array_values($value)],
            'not_in' => ["{$sql} NOT IN ({$marks()})", array_values($value)],
            'between' => ["{$sql} BETWEEN ? AND ?", array_values($value)],
            'contains' => ["{$sql} LIKE ? ESCAPE '!'", ['%'.strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']).'%']],
            'is_null' => ["{$sql} IS NULL", []],
            'not_null' => ["{$sql} IS NOT NULL", []],
        };
    }

    /**
     * Rows after the cursor: (k1 after v1) OR (k1 = v1 AND k2 after v2) OR ...
     *
     * @return array{0: string, 1: array}
     */
    private function keyset(array $order, array $select, array $after): array
    {
        $or = [];
        $bindings = [];
        $equal = [];
        $equalBindings = [];
        foreach (array_keys($order) as $i => $key) {
            $sql = $select[$key]['sql'];
            $value = $after[$i];
            [$next, $nextBindings] = $this->after($sql, $order[$key], $value);
            $or[] = '('.implode(' AND ', [...$equal, $next]).')';
            $bindings = [...$bindings, ...$equalBindings, ...$nextBindings];
            $equal[] = $value === null ? "{$sql} IS NULL" : "{$sql} = ?";
            if ($value !== null) {
                $equalBindings[] = $value;
            }
        }

        return ['('.implode(' OR ', $or).')', $bindings];
    }

    /** NULLs come first ascending and last descending, as MySQL and SQLite sort them. */
    private function after(string $sql, string $dir, mixed $value): array
    {
        if ($dir === 'asc') {
            return $value === null ? ["{$sql} IS NOT NULL", []] : ["{$sql} > ?", [$value]];
        }

        return $value === null ? ['1 = 0', []] : ["({$sql} < ? OR {$sql} IS NULL)", [$value]];
    }

    private function fetch(Builder $query, int $timeoutMs): array
    {
        $connection = $query->getConnection();
        $mysql = $connection->getDriverName() === 'mysql';

        try {
            if ($mysql) {
                $connection->statement('SET SESSION max_execution_time = '.max(1, $timeoutMs));
            }

            return $query->get()->map(fn ($record) => array_values((array) $record))->all();
        } catch (QueryException $e) {
            // 3024: maximum statement execution time exceeded.
            if ((int) ($e->errorInfo[1] ?? 0) === 3024) {
                abort(504, 'The dataset query ran out of time.');
            }
            throw $e;
        } finally {
            if ($mysql) {
                $connection->statement('SET SESSION max_execution_time = DEFAULT');
            }
        }
    }

    private function cast(array $record, array $types): array
    {
        foreach ($record as $i => $value) {
            $record[$i] = $value === null ? null : match ($types[$i]) {
                'integer' => (int) $value,
                'number', 'money' => (float) $value,
                'boolean' => (bool) $value,
                default => (string) $value,
            };
        }

        return $record;
    }

    private function encodeCursor(string $fingerprint, array $values): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode(['f' => $fingerprint, 'v' => $values])), '+/', '-_'), '=');

        return $payload.'.'.hash_hmac('sha256', $payload, self::CURSOR_KEY.config('app.key'));
    }

    private function decodeCursor(string $cursor, string $fingerprint): ?array
    {
        [$payload, $signature] = array_pad(explode('.', $cursor, 2), 2, '');
        if (! hash_equals(hash_hmac('sha256', $payload, self::CURSOR_KEY.config('app.key')), $signature)) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($data) && ($data['f'] ?? null) === $fingerprint && is_array($data['v'] ?? null) ? $data['v'] : null;
    }
}
