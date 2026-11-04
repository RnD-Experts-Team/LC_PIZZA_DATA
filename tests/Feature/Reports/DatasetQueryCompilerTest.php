<?php

namespace Tests\Feature\Reports;

use App\Reports\Contract\Column;
use App\Reports\Contract\DatasetQueryCompiler;
use App\Reports\Contract\ReportDataset;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The compiler against a table on the in-memory sqlite connection, through a
 * dataset that offers some of its columns and hides `secret`.
 */
class DatasetQueryCompilerTest extends TestCase
{
    private ReportDataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('sales_test', function (Blueprint $table) {
            $table->id();
            $table->string('store');
            $table->date('day');
            $table->integer('orders');
            $table->string('note')->nullable();
            $table->integer('secret')->default(0);
        });

        $this->dataset = new SalesTestDataset();
    }

    private function seedStores(): void
    {
        $rows = [];
        foreach (['03795-00001', '03795-00002', '03795-00003', '03795-00004', '03795-00005'] as $s => $store) {
            foreach (['2026-10-21', '2026-10-22'] as $d => $day) {
                $rows[] = ['store' => $store, 'day' => $day, 'orders' => 10 * ($s + 1) + $d, 'note' => null];
                $rows[] = ['store' => $store, 'day' => $day, 'orders' => 1, 'note' => null];
            }
        }
        DB::table('sales_test')->insert($rows);
    }

    private function ask(array $query, ?ReportDataset $dataset = null): array
    {
        return (new DatasetQueryCompiler())->run($dataset ?? $this->dataset, $query + [
            'stores' => [],
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
        ]);
    }

    private function errors(array $query, ?ReportDataset $dataset = null): array
    {
        try {
            $this->ask($query, $dataset);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('The query was accepted.');
    }

    public function test_it_groups_aggregates_and_returns_positional_rows(): void
    {
        $this->seedStores();

        $result = $this->ask([
            'stores' => ['03795-00002', '03795-00004'],
            'columns' => ['store', 'date'],
            'group_by' => ['store', 'date'],
            'aggregates' => [['column' => 'orders', 'fn' => 'sum', 'alias' => 'orders']],
            'sort' => [['column' => 'orders', 'dir' => 'desc']],
        ]);

        $this->assertSame([['key' => 'store', 'type' => 'string'], ['key' => 'date', 'type' => 'date'], ['key' => 'orders', 'type' => 'integer']], $result['columns']);
        $this->assertSame([
            ['03795-00004', '2026-10-22', 42],
            ['03795-00004', '2026-10-21', 41],
            ['03795-00002', '2026-10-22', 22],
            ['03795-00002', '2026-10-21', 21],
        ], $result['rows']);
        $this->assertNull($result['next_cursor']);
        $this->assertSame(1, $result['meta']['contract_version']);
    }

    public function test_only_offered_columns_ops_and_functions_reach_the_sql(): void
    {
        $this->assertSame(['Unknown column secret.'], $this->errors(['columns' => ['store', 'secret']])['columns']);
        $this->assertSame(['orders cannot be grouped; ask for it through an aggregate.'], $this->errors(['columns' => ['orders']])['columns']);
        $this->assertSame(['avg is not offered for orders.'], $this->errors([
            'aggregates' => [['column' => 'orders', 'fn' => 'avg']],
        ])['aggregates.0.fn']);
        $this->assertSame(['Unknown column secret.'], $this->errors([
            'columns' => ['store'],
            'filters' => [['column' => 'secret', 'op' => 'eq', 'value' => 1]],
        ])['filters.0.column']);
        $this->assertArrayHasKey('sort.0.column', $this->errors([
            'columns' => ['store'],
            'sort' => [['column' => 'date', 'dir' => 'asc']],
        ]));
        $this->assertSame(['contains works on text columns only.'], $this->errors([
            'columns' => ['store'],
            'filters' => [['column' => 'orders', 'op' => 'contains', 'value' => '1']],
        ])['filters.0.value']);
        $this->assertSame(['Send a list of one or more values.'], $this->errors([
            'columns' => ['store'],
            'filters' => [['column' => 'store', 'op' => 'in', 'value' => []]],
        ])['filters.0.value']);
        $this->assertSame(['Send the period (date_from and date_to).'], $this->errors([
            'columns' => ['store'],
            'date_from' => null,
            'date_to' => null,
        ])['date_from']);
    }

    public function test_a_column_that_offers_an_average_also_gives_its_sum_and_count(): void
    {
        $this->seedStores();

        $result = $this->ask([
            'stores' => ['03795-00001'],
            'columns' => ['store'],
            'group_by' => ['store'],
            'aggregates' => [
                ['column' => 'spread', 'fn' => 'sum', 'alias' => 's'],
                ['column' => 'spread', 'fn' => 'count', 'alias' => 'n'],
                ['column' => 'spread', 'fn' => 'avg', 'alias' => 'a'],
            ],
        ]);

        // Store 1: 10, 1, 11, 1 -> sum 23 over 4 rows.
        $this->assertSame(['03795-00001', 23, 4], array_slice($result['rows'][0], 0, 3));
        $this->assertEqualsWithDelta(5.75, $result['rows'][0][3], 0.0001);

        // A column without an average still only gives what it lists.
        $this->assertSame(['count is not offered for orders.'], $this->errors([
            'aggregates' => [['column' => 'orders', 'fn' => 'count']],
        ])['aggregates.0.fn']);
    }

    public function test_any_period_and_any_in_list_are_answered(): void
    {
        $this->seedStores();

        $result = $this->ask([
            'columns' => ['store'],
            'filters' => [['column' => 'store', 'op' => 'in', 'value' => [...array_map(fn (int $i) => sprintf('x-%05d', $i), range(1, 5000)), '03795-00001']]],
            'date_from' => '2016-01-01',
            'date_to' => '2036-12-31',
        ]);

        $this->assertSame([['03795-00001']], $result['rows']);
    }

    public function test_a_snapshot_dataset_ignores_the_period(): void
    {
        $this->seedStores();

        $result = $this->ask(['columns' => ['store'], 'date_from' => null, 'date_to' => null, 'stores' => ['03795-00002']], new SnapshotTestDataset());
        $this->assertSame([['03795-00002']], $result['rows']);

        $result = $this->ask(['columns' => ['store'], 'date_from' => '2020-01-01', 'date_to' => '2020-01-01', 'stores' => ['03795-00002']], new SnapshotTestDataset());
        $this->assertSame([['03795-00002']], $result['rows']);
        $this->assertFalse((new SnapshotTestDataset())->toArray()['dated']);
        $this->assertTrue($this->dataset->toArray()['dated']);
    }

    /** The NDJSON lines of a stream, decoded. */
    private function streamed(array $query): array
    {
        return $this->lines((new DatasetQueryCompiler())->stream($this->dataset, $query + ['stores' => [], 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']));
    }

    /** The stream flushes as it goes, so what it sends is gathered at each flush. */
    private function lines(StreamedResponse $response): array
    {
        $sent = '';
        ob_start(function (string $buffer) use (&$sent) {
            $sent .= $buffer;

            return '';
        });
        $response->sendContent();
        ob_end_clean();

        return array_map(fn (string $line) => json_decode($line, true), explode("\n", rtrim($sent, "\n")));
    }

    public function test_a_stream_sends_the_columns_every_row_unordered_and_the_done_line(): void
    {
        foreach (array_chunk(range(1, 6001), 500) as $chunk) {
            DB::table('sales_test')->insert(array_map(fn (int $i) => ['store' => '03795-00001', 'day' => '2026-10-21', 'orders' => $i], $chunk));
        }

        $response = (new DatasetQueryCompiler())->stream($this->dataset, [
            'columns' => ['ref'],
            'aggregates' => [['column' => 'orders', 'fn' => 'max', 'alias' => 'orders']],
            'sort' => [['column' => 'orders', 'dir' => 'desc']],
            'stores' => [],
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
        ]);
        $this->assertSame('application/x-ndjson', $response->headers->get('Content-Type'));
        $lines = $this->lines($response);

        // Past the 5000 a page holds, in one answer.
        $this->assertCount(6003, $lines);
        $this->assertSame(['columns' => [['key' => 'ref', 'type' => 'integer'], ['key' => 'orders', 'type' => 'integer']]], $lines[0]);
        $this->assertSame(['done' => true, 'row_count' => 6001], end($lines));
        $this->assertEqualsCanonicalizing(array_map(fn (int $i) => [$i, $i], range(1, 6001)), array_slice($lines, 1, -1));
    }

    public function test_a_stream_takes_no_cursor_and_no_limit_but_is_checked_like_a_page(): void
    {
        $errors = function (array $query) {
            try {
                (new DatasetQueryCompiler())->stream($this->dataset, $query + ['stores' => [], 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
            } catch (ValidationException $e) {
                return $e->errors();
            }
            $this->fail('The stream was accepted.');
        };

        $this->assertSame(['A stream sends every row; it takes no cursor.'], $errors(['columns' => ['store'], 'cursor' => 'abc'])['cursor']);
        $this->assertSame(['A stream sends every row; it takes no limit.'], $errors(['columns' => ['store'], 'limit' => 10])['limit']);
        $this->assertSame(['Unknown column secret.'], $errors(['columns' => ['secret']])['columns']);
    }

    public function test_a_stream_that_fails_part_way_ends_with_the_error_line_and_no_done_line(): void
    {
        DB::table('sales_test')->insert(['store' => '03795-00001', 'day' => '2026-10-21', 'orders' => 1]);
        $response = (new DatasetQueryCompiler())->stream($this->dataset, ['columns' => ['store'], 'stores' => [], 'date_from' => '2026-10-01', 'date_to' => '2026-10-31']);
        // The table goes away between the answer's start and its query.
        Schema::drop('sales_test');

        $this->assertSame([
            ['columns' => [['key' => 'store', 'type' => 'string']]],
            ['error' => 'The dataset query failed before it sent every row.'],
        ], $this->lines($response));
    }

    public function test_the_cursor_pages_through_every_group_exactly_once(): void
    {
        $this->seedStores();
        $query = [
            'columns' => ['store', 'date'],
            'aggregates' => [['column' => 'orders', 'fn' => 'sum', 'alias' => 'orders']],
            'sort' => [['column' => 'orders', 'dir' => 'desc']],
        ];

        $all = $this->ask($query)['rows'];
        $paged = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->ask($query + ['limit' => 3, 'cursor' => $cursor]);
            $paged = [...$paged, ...$page['rows']];
            $cursor = $page['next_cursor'];
            $this->assertSame($cursor !== null, $page['truncated']);
            $pages++;
        } while ($cursor !== null);

        $this->assertCount(10, $all);
        $this->assertSame($all, $paged);
        $this->assertSame(4, $pages);
    }

    public function test_a_tampered_or_borrowed_cursor_is_refused(): void
    {
        $this->seedStores();
        $query = ['columns' => ['store', 'date'], 'limit' => 2];
        $cursor = $this->ask($query)['next_cursor'];

        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors($query + ['cursor' => 'x'.$cursor])['cursor']);
        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors([
            'columns' => ['store', 'date'],
            'stores' => ['03795-00001'],
            'cursor' => $cursor,
        ])['cursor']);
    }

    public function test_a_cursor_of_another_dataset_is_refused(): void
    {
        $this->seedStores();
        $query = ['columns' => ['store', 'date'], 'limit' => 2];
        $cursor = $this->ask($query)['next_cursor'];

        // The same query and the same table, only the dataset key differs.
        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors(
            $query + ['cursor' => $cursor],
            new OtherSalesTestDataset(),
        )['cursor']);
        $this->assertCount(2, $this->ask($query + ['cursor' => $cursor])['rows']);
    }

    public function test_a_cursor_with_a_bad_signature_is_refused_even_when_its_payload_is_intact(): void
    {
        $this->seedStores();
        $query = ['columns' => ['store', 'date'], 'limit' => 2];
        [$payload] = explode('.', $this->ask($query)['next_cursor']);

        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors(
            $query + ['cursor' => $payload.'.'.str_repeat('0', 64)],
        )['cursor']);
        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors(
            $query + ['cursor' => $payload],
        )['cursor']);
    }

    public function test_a_cursor_signed_with_the_bare_app_key_is_refused(): void
    {
        $this->seedStores();
        $query = ['columns' => ['store', 'date'], 'limit' => 2];
        [$payload] = explode('.', $this->ask($query)['next_cursor']);

        // The cursor key is app.key behind a prefix, so a signature made elsewhere with app.key is no good.
        $this->assertSame(['The cursor is invalid or belongs to another query.'], $this->errors(
            $query + ['cursor' => $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'))],
        )['cursor']);
        $this->assertSame(
            $payload.'.'.hash_hmac('sha256', $payload, 'report-cursor|'.config('app.key')),
            $this->ask($query)['next_cursor'],
        );
    }

    public function test_a_filter_on_a_column_name_filters_rows_even_when_an_aggregate_has_that_alias(): void
    {
        $this->seedStores();

        // Store 2 has rows of 20 and 1 on the 21st, 21 and 1 on the 22nd. As a row filter only
        // the 21 survives; as HAVING on the sums (21 and 22) both days would.
        $result = $this->ask([
            'stores' => ['03795-00002'],
            'group_by' => ['date'],
            'aggregates' => [['column' => 'orders', 'fn' => 'sum', 'alias' => 'orders']],
            'filters' => [['column' => 'orders', 'op' => 'gt', 'value' => 20]],
        ]);

        $this->assertSame([['2026-10-22', 21]], $result['rows']);
    }

    public function test_a_filter_on_an_aggregate_alias_filters_the_groups(): void
    {
        $this->seedStores();

        $result = $this->ask([
            'stores' => ['03795-00002'],
            'group_by' => ['date'],
            'aggregates' => [['column' => 'orders', 'fn' => 'sum', 'alias' => 'total']],
            'filters' => [['column' => 'total', 'op' => 'gt', 'value' => 21]],
        ]);

        $this->assertSame([['2026-10-22', 22]], $result['rows']);
    }

    public function test_the_limit_is_capped_at_5000(): void
    {
        foreach (array_chunk(range(1, 5003), 500) as $chunk) {
            DB::table('sales_test')->insert(array_map(fn () => ['store' => '03795-00001', 'day' => '2026-10-21', 'orders' => 1], $chunk));
        }

        $result = $this->ask(['columns' => ['ref'], 'limit' => 99999]);

        $this->assertCount(5000, $result['rows']);
        $this->assertTrue($result['truncated']);
        $this->assertNotNull($result['next_cursor']);
    }

    public function test_contains_matches_wildcards_literally(): void
    {
        DB::table('sales_test')->insert([
            ['store' => '03795-00001', 'day' => '2026-10-21', 'orders' => 1, 'note' => '50% off'],
            ['store' => '03795-00002', 'day' => '2026-10-21', 'orders' => 1, 'note' => '50 off'],
            ['store' => '03795-00003', 'day' => '2026-10-21', 'orders' => 1, 'note' => 'a_b'],
        ]);

        $notes = fn (string $text) => array_column($this->ask([
            'columns' => ['note'],
            'filters' => [['column' => 'note', 'op' => 'contains', 'value' => $text]],
        ])['rows'], 0);

        $this->assertSame(['50% off'], $notes('%'));
        $this->assertSame(['a_b'], $notes('_'));
        $this->assertSame(['50 off', '50% off'], $notes('50'));
    }
}

/** A table offering some of its columns and hiding secret. */
class SalesTestDataset extends ReportDataset
{
    public const KEY = 'test.sales';

    public function label(): string
    {
        return 'Sales';
    }

    public function grain(): array
    {
        return ['store', 'date'];
    }

    public function columns(): array
    {
        return [
            new Column('ref', 'Row', 'integer', 'id', groupable: true),
            new Column('store', 'Store', 'string', 'store', groupable: true),
            new Column('date', 'Date', 'date', 'day', groupable: true),
            new Column('orders', 'Orders', 'integer', 'orders', measure: true, aggregations: ['sum', 'max']),
            new Column('spread', 'Orders (spread)', 'integer', 'orders', measure: true, aggregations: ['avg', 'min', 'max']),
            new Column('note', 'Note', 'string', 'note', groupable: true),
        ];
    }

    public function baseQuery(): Builder
    {
        return DB::table('sales_test');
    }

    public function storeColumn(): string
    {
        return 'store';
    }

    public function dateColumn(): ?string
    {
        return 'day';
    }

    public function storeKeys(array $codes): array
    {
        return $codes;
    }
}

/** Same table under another key: a cursor of one is no good for the other. */
class OtherSalesTestDataset extends SalesTestDataset
{
    public const KEY = 'test.other';
}

/** Current state: no period, no date in its grain. */
class SnapshotTestDataset extends SalesTestDataset
{
    public const KEY = 'test.snapshot';

    public function grain(): array
    {
        return ['store'];
    }

    public function dateColumn(): ?string
    {
        return null;
    }
}
