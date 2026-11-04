<?php

namespace Tests\Feature\Reports;

use App\Jobs\RunReportJob;
use App\Models\ReportRun;
use App\Models\ReportTemplate;
use App\Reports\Contract\ReportDataset;
use App\Reports\Engine\ReportFileWriter;
use App\Reports\Engine\ReportRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use SQLite3;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AnswersReportStreams;

/**
 * How the engine merges datasets, on both of its paths (the preview's paged,
 * in-memory one and a run's streamed, file-staged one): breakdown columns, an
 * empty grain, snapshot datasets and datasets with only part of the grain;
 * "mine" asked store by store; the result read in index order. The datasets
 * are made up; the fake service groups their fixtures the way the contract does.
 */
class ReportMergeTest extends TestCase
{
    use AnswersReportStreams;

    private const FIXTURES = [
        'x.tickets' => [
            ['store' => 'A', 'date' => '2026-10-01', 'status' => 'open', 'cost' => 10],
            ['store' => 'A', 'date' => '2026-10-01', 'status' => 'closed', 'cost' => 5],
            ['store' => 'A', 'date' => '2026-10-01', 'status' => 'open', 'cost' => 1],
            ['store' => 'B', 'date' => '2026-10-02', 'status' => 'open', 'cost' => 7],
        ],
        'x.sales' => [
            ['store' => 'A', 'date' => '2026-10-01', 'net' => 100],
            ['store' => 'C', 'date' => '2026-10-01', 'net' => 30],
            ['store' => 'B', 'date' => '2026-10-02', 'net' => 50],
        ],
        'x.parts' => [['store' => 'A', 'part' => 'oven', 'qty' => 1]],
        'x.stock' => [['part' => 'cheese', 'qty' => 4], ['part' => 'dough', 'qty' => 6], ['part' => 'cheese', 'qty' => 1]],
        'x.weather' => [['date' => '2026-10-01', 'temp' => 20], ['date' => '2026-10-03', 'temp' => 18]],
        // Three stores, sharing dates (and kinds) so their rows would share a date-only key.
        'x.visits' => [
            ['store' => 'A', 'date' => '2026-10-01', 'kind' => 'dine', 'amount' => 10, 'visits' => 1, 'guest' => 'g1'],
            ['store' => 'A', 'date' => '2026-10-01', 'kind' => 'take', 'amount' => 4, 'visits' => 1, 'guest' => 'g2'],
            ['store' => 'B', 'date' => '2026-10-01', 'kind' => 'dine', 'amount' => 7, 'visits' => 2, 'guest' => 'g1'],
            ['store' => 'B', 'date' => '2026-10-02', 'kind' => 'dine', 'amount' => 3, 'visits' => 1, 'guest' => 'g3'],
            ['store' => 'C', 'date' => '2026-10-01', 'kind' => 'take', 'amount' => 8, 'visits' => null, 'guest' => 'g4'],
            ['store' => 'C', 'date' => '2026-10-02', 'kind' => 'dine', 'amount' => 1, 'visits' => 1, 'guest' => 'g3'],
            ['store' => 'C', 'date' => '2026-10-02', 'kind' => 'take', 'amount' => 6, 'visits' => 3, 'guest' => 'g5'],
        ],
    ];

    /** When set, a request for more than one store is refused (403), as "mine" can be. */
    private bool $refuseTogether = false;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('user_store_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('store_id')->nullable();
            $table->string('role_name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        DB::table('user_store_roles')->insert(array_map(
            fn (int $i, string $store) => ['id' => $i + 1, 'user_id' => 9, 'store_id' => $store, 'role_name' => 'Store Manager', 'active' => true],
            [0, 1, 2],
            ['A', 'B', 'C'],
        ));

        config(['services.report_datasets.urls' => ['x' => 'http://x.test/api']]);
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake(['x.test/api/report-datasets/*' => fn (Request $request) => $this->answer($request)]);
    }

    private function column(string $key, string $type = 'string', array $aggregations = []): array
    {
        return [
            'key' => $key, 'label' => ucfirst($key), 'type' => $type, 'options' => [], 'filterable' => true, 'sortable' => true,
            'groupable' => $aggregations === [], 'measure' => $aggregations !== [], 'aggregations' => $aggregations, 'derived' => null,
        ];
    }

    private function services(): array
    {
        $dataset = fn (string $key, array $grain, bool $dated, array $columns) => [
            'key' => $key, 'label' => $key, 'path' => "/report-datasets/{$key}/query", 'grain' => $grain, 'heavy' => false, 'dated' => $dated, 'columns' => $columns,
        ];

        return [[
            'service' => 'X', 'origin' => 'x', 'contract_version' => 1,
            'datasets' => [
                $dataset('x.tickets', ['store', 'date'], true, [$this->column('store'), $this->column('date', 'date'), $this->column('status'), $this->column('cost', 'money', ['sum'])]),
                $dataset('x.sales', ['store', 'date'], true, [$this->column('store'), $this->column('date', 'date'), $this->column('net', 'money', ['sum'])]),
                $dataset('x.parts', ['store'], false, [$this->column('store'), $this->column('part'), $this->column('qty', 'number', ['sum'])]),
                $dataset('x.stock', [], false, [$this->column('part'), $this->column('qty', 'number', ['sum'])]),
                $dataset('x.weather', ['date'], true, [$this->column('date', 'date'), $this->column('temp', 'number', ['max'])]),
                $dataset('x.visits', ['store', 'date'], true, [
                    $this->column('store'), $this->column('date', 'date'), $this->column('kind'),
                    $this->column('amount', 'number', ['sum', 'avg', 'min', 'max', 'count']),
                    $this->column('visits', 'integer', ['count']),
                    $this->column('guest', 'integer', ['count_distinct']),
                    // Derived: each input asked with its first aggregation (sum, count).
                    ['key' => 'per_visit', 'label' => 'Per visit', 'type' => 'number', 'options' => [], 'filterable' => false, 'sortable' => true,
                        'groupable' => false, 'measure' => true, 'aggregations' => [], 'derived' => 'amount / visits'],
                ]),
            ],
        ]];
    }

    /**
     * The fixture of the request's stores grouped by its group keys, each aggregate
     * applied as SQL would (nulls left out); paged or streamed as asked.
     */
    private function answer(Request $request)
    {
        if ($this->refuseTogether && count($request['stores']) > 1) {
            return Http::response(['message' => 'Forbidden'], 403);
        }

        $key = explode('/', $request->url())[5];
        $groups = [];
        foreach (self::FIXTURES[$key] as $row) {
            if ($request['stores'] === [] || in_array($row['store'] ?? null, $request['stores'], true)) {
                $groups[json_encode(array_map(fn (string $k) => $row[$k], $request['group_by']))][] = $row;
            }
        }
        ksort($groups);

        $rows = [];
        foreach ($groups as $members) {
            $rows[] = [
                ...array_map(fn (string $k) => $members[0][$k], $request['group_by']),
                ...array_map(function (array $aggregate) use ($members) {
                    $values = array_values(array_filter(array_column($members, $aggregate['column']), fn ($value) => $value !== null));

                    return match ($aggregate['fn']) {
                        'max' => max($values),
                        'min' => min($values),
                        'count' => count($values),
                        'count_distinct' => count(array_unique($values)),
                        'avg' => (float) (array_sum($values) / count($values)),
                        default => array_sum($values),
                    };
                }, $request['aggregates']),
            ];
        }

        if ($request['stream'] ?? false) {
            return $this->ndjson($this->keysOf($request), $rows);
        }

        return Http::response([
            'columns' => array_map(fn (string $k) => ['key' => $k, 'type' => 'string'], $this->keysOf($request)),
            'rows' => $rows,
            'next_cursor' => null,
            'truncated' => false,
            'meta' => ['row_count' => count($rows), 'elapsed_ms' => 1, 'contract_version' => 1],
        ]);
    }

    private function definition(array $datasets, array $grain, array $columns, array $overrides = []): array
    {
        return [
            'v' => 1,
            'datasets' => array_map(fn (string $alias, string $key) => ['alias' => $alias, 'key' => $key], array_keys($datasets), $datasets),
            'grain' => $grain,
            'columns' => $columns,
            'filters' => [],
            'sort' => [],
            'period' => ['type' => 'fixed', 'from' => '2026-10-01', 'to' => '2026-10-31'],
            'stores' => ['mode' => 'all'],
            ...$overrides,
        ];
    }

    /** The rows on both paths, which must agree; returns them once. */
    private function rows(array $definition): array
    {
        $runner = app(ReportRunner::class);
        $preview = $runner->run($definition, $this->services(), '1|token', 9, 15, 200, 200)['rows'];

        $written = null;
        $runner->export($definition, $this->services(), '1|token', 9, 'merge', fn () => null, function (array $columns, iterable $rows) use (&$written) {
            $written = iterator_to_array($rows, false);

            return (new ReportFileWriter())->write('merge', $columns, [], false);
        });

        $this->assertSame($preview, $written, 'The preview and the run disagree.');

        return $preview;
    }

    public function test_a_single_dataset_keeps_every_row_its_breakdown_columns_tell_apart(): void
    {
        $this->assertSame([
            ['A', '2026-10-01', 'closed', 5],
            ['A', '2026-10-01', 'open', 11],
            ['B', '2026-10-02', 'open', 7],
        ], $this->rows($this->definition(['t' => 'x.tickets'], ['store', 'date'], [
            ['id' => 'c1', 'alias' => 't', 'column' => 'status'],
            ['id' => 'c2', 'alias' => 't', 'column' => 'cost', 'agg' => 'sum'],
        ])));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'x.tickets') && $request['group_by'] === ['store', 'date', 'status']);
    }

    public function test_the_other_datasets_repeat_on_each_row_of_the_one_with_a_breakdown(): void
    {
        $this->assertSame([
            ['A', '2026-10-01', 'closed', 5, 100],
            ['A', '2026-10-01', 'open', 11, 100],
            ['B', '2026-10-02', 'open', 7, 50],
            // A tuple the breakdown dataset lacks is one row, its cells empty.
            ['C', '2026-10-01', null, null, 30],
        ], $this->rows($this->definition(['t' => 'x.tickets', 's' => 'x.sales'], ['store', 'date'], [
            ['id' => 'c1', 'alias' => 't', 'column' => 'status'],
            ['id' => 'c2', 'alias' => 't', 'column' => 'cost', 'agg' => 'sum'],
            ['id' => 'c3', 'alias' => 's', 'column' => 'net', 'agg' => 'sum'],
        ])));
    }

    public function test_two_datasets_with_breakdown_columns_are_refused(): void
    {
        try {
            app(ReportRunner::class)->run($this->definition(['t' => 'x.tickets', 'p' => 'x.parts'], ['store'], [
                ['id' => 'c1', 'alias' => 't', 'column' => 'status'],
                ['id' => 'c2', 'alias' => 'p', 'column' => 'part'],
            ]), $this->services(), '1|token', 9, 15, 200, 200);
            $this->fail('The definition was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(['columns' => ["Only one dataset can add breakdown columns; the others must match the report's grain."]], $e->errors());
        }
        Http::assertNothingSent();
    }

    public function test_an_empty_grain_gives_one_row_per_breakdown_or_one_totals_row(): void
    {
        // A global snapshot alone: grouped by its breakdown only.
        $this->assertSame([['cheese', 5], ['dough', 6]], $this->rows($this->definition(['k' => 'x.stock'], [], [
            ['id' => 'c1', 'alias' => 'k', 'column' => 'part'],
            ['id' => 'c2', 'alias' => 'k', 'column' => 'qty', 'agg' => 'sum'],
        ])));

        // With a dated dataset: its total repeats on each breakdown row.
        $this->assertSame([['cheese', 5, 180], ['dough', 6, 180]], $this->rows($this->definition(['k' => 'x.stock', 's' => 'x.sales'], [], [
            ['id' => 'c1', 'alias' => 'k', 'column' => 'part'],
            ['id' => 'c2', 'alias' => 'k', 'column' => 'qty', 'agg' => 'sum'],
            ['id' => 'c3', 'alias' => 's', 'column' => 'net', 'agg' => 'sum'],
        ])));

        // No breakdown at all: one totals row.
        $this->assertSame([[11, 180]], $this->rows($this->definition(['k' => 'x.stock', 's' => 'x.sales'], [], [
            ['id' => 'c1', 'alias' => 'k', 'column' => 'qty', 'agg' => 'sum'],
            ['id' => 'c2', 'alias' => 's', 'column' => 'net', 'agg' => 'sum'],
        ])));

        // The snapshot is asked without a period; the dated dataset with it.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'x.stock') && ! isset($request['date_from']) && $request['group_by'] === []);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'x.stock') && isset($request['date_from']));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'x.sales') && $request['date_from'] === '2026-10-01');
    }

    public function test_a_date_only_dataset_joins_a_store_and_date_one_on_the_date(): void
    {
        $this->assertSame([
            ['2026-10-01', 20, 130],
            ['2026-10-02', null, 50],
            ['2026-10-03', 18, null],
        ], $this->rows($this->definition(['w' => 'x.weather', 's' => 'x.sales'], ['date'], [
            ['id' => 'c1', 'alias' => 'w', 'column' => 'temp', 'agg' => 'max'],
            ['id' => 'c2', 'alias' => 's', 'column' => 'net', 'agg' => 'sum'],
        ])));
    }

    public function test_sorting_puts_empty_cells_last_either_way_and_compares_numbers_as_numbers(): void
    {
        $definition = fn (string $dir) => $this->definition(['t' => 'x.tickets', 's' => 'x.sales'], ['store', 'date'], [
            ['id' => 'c1', 'alias' => 't', 'column' => 'cost', 'agg' => 'sum'],
            ['id' => 'c2', 'alias' => 's', 'column' => 'net', 'agg' => 'sum'],
        ], ['sort' => [['id' => 'c1', 'dir' => $dir]]]);

        $this->assertSame(['B', 'A', 'C'], array_column($this->rows($definition('asc')), 0));
        $this->assertSame(['A', 'B', 'C'], array_column($this->rows($definition('desc')), 0));
    }

    // ── "Mine" asked store by store ─────────────────────────────────────────────────────

    /** The visits columns a date-grain report totals across stores. */
    private function visits(array $grain, array $columns): array
    {
        return $this->definition(['v' => 'x.visits'], $grain, $columns, ['stores' => ['mode' => 'mine']]);
    }

    /** The visits queries sent so far, as their bodies. */
    private function visitQueries(): array
    {
        return array_values(array_map(
            fn (array $pair) => $pair[0]->data(),
            array_filter(Http::recorded()->all(), fn (array $pair) => str_contains($pair[0]->url(), 'x.visits')),
        ));
    }

    public function test_store_by_store_totals_across_stores_exactly_as_one_request_does(): void
    {
        $definition = $this->visits(['date'], [
            ['id' => 'c1', 'alias' => 'v', 'column' => 'amount', 'agg' => 'sum'],
            ['id' => 'c2', 'alias' => 'v', 'column' => 'amount', 'agg' => 'count'],
            ['id' => 'c3', 'alias' => 'v', 'column' => 'amount', 'agg' => 'min'],
            ['id' => 'c4', 'alias' => 'v', 'column' => 'amount', 'agg' => 'max'],
            ['id' => 'c5', 'alias' => 'v', 'column' => 'amount', 'agg' => 'avg'],
            ['id' => 'c6', 'alias' => 'v', 'column' => 'visits', 'agg' => 'count'],
            // Derived from the re-aggregated inputs: summed amount over counted visits.
            ['id' => 'c7', 'alias' => 'v', 'column' => 'per_visit'],
            ['id' => 'c8', 'kind' => 'formula', 'expr' => 'c1 / c2'],
        ]);

        // One request for the three stores: the service totals them.
        $single = $this->rows($definition);
        $sent = $this->visitQueries();
        $this->assertCount(2, $sent);
        foreach ($sent as $body) {
            $this->assertSame(['A', 'B', 'C'], $body['stores']);
            $this->assertSame(['date'], $body['group_by']);
            $this->assertSame(['sum', 'count', 'min', 'max', 'avg', 'count', 'sum', 'count'], array_column($body['aggregates'], 'fn'));
            $this->assertSame(['c1', 'c2', 'c3', 'c4', 'c5', 'c6', '_d_amount', '_d_visits'], array_column($body['aggregates'], 'alias'));
        }

        $this->assertSame([
            ['2026-10-01', 29, 4, 4, 10, 7.25, 3, 29 / 3, 7.25],
            ['2026-10-02', 10, 3, 1, 6, 10 / 3, 3, 10 / 3, 10 / 3],
        ], $single);

        // The stores refused together: one request per store, grouped by store as well, re-aggregated here.
        $seen = count($this->visitQueries());
        $this->refuseTogether = true;
        $this->assertSame($single, $this->rows($definition));

        $perStore = array_values(array_filter(array_slice($this->visitQueries(), $seen), fn (array $body) => count($body['stores']) === 1));
        $this->assertCount(6, $perStore);
        foreach ($perStore as $body) {
            $this->assertSame(['date', 'store'], $body['group_by']);
            // The average as the sum and the count of its column.
            $this->assertSame(['sum', 'count', 'min', 'max', 'sum', 'count', 'count', 'sum', 'count'], array_column($body['aggregates'], 'fn'));
            $this->assertSame(['amount', 'amount', 'amount', 'amount', 'amount', 'amount', 'visits', 'amount', 'visits'], array_column($body['aggregates'], 'column'));
            $this->assertSame(array_map(fn (int $j) => "_a{$j}", range(0, 8)), array_column($body['aggregates'], 'alias'));
        }
        $this->assertSame(['A', 'B', 'C', 'A', 'B', 'C'], array_merge(...array_column($perStore, 'stores')));
    }

    public function test_store_by_store_keeps_breakdown_columns_apart(): void
    {
        $definition = $this->visits(['date'], [
            ['id' => 'c1', 'alias' => 'v', 'column' => 'kind'],
            ['id' => 'c2', 'alias' => 'v', 'column' => 'amount', 'agg' => 'sum'],
            ['id' => 'c3', 'alias' => 'v', 'column' => 'amount', 'agg' => 'avg'],
        ]);

        $single = $this->rows($definition);
        $this->assertSame([
            ['2026-10-01', 'dine', 17, 8.5],
            // A whole average arrives as an integer, as JSON carries it.
            ['2026-10-01', 'take', 12, 6],
            ['2026-10-02', 'dine', 4, 2],
            ['2026-10-02', 'take', 6, 6],
        ], $single);

        $this->refuseTogether = true;
        $this->assertSame($single, $this->rows($definition));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'x.visits') && $request['group_by'] === ['date', 'kind', 'store']);
    }

    public function test_store_by_store_with_the_store_in_the_grain_asks_as_before(): void
    {
        $definition = $this->visits(['store', 'date'], [
            ['id' => 'c1', 'alias' => 'v', 'column' => 'amount', 'agg' => 'avg'],
            // Each row is one store's, so distinct values need no totalling.
            ['id' => 'c2', 'alias' => 'v', 'column' => 'guest', 'agg' => 'count_distinct'],
        ]);
        $single = $this->rows($definition);

        $seen = count($this->visitQueries());
        $this->refuseTogether = true;
        $this->assertSame($single, $this->rows($definition));

        $perStore = array_values(array_filter(array_slice($this->visitQueries(), $seen), fn (array $body) => count($body['stores']) === 1));
        $this->assertCount(6, $perStore);
        foreach ($perStore as $body) {
            $this->assertSame(['store', 'date'], $body['group_by']);
            $this->assertSame([['column' => 'amount', 'fn' => 'avg', 'alias' => 'c1'], ['column' => 'guest', 'fn' => 'count_distinct', 'alias' => 'c2']], $body['aggregates']);
        }
    }

    private const DISTINCT = "Guests counts distinct values; with your store access that needs one request per store, so it cannot be totalled across stores. Add Store to the report's grain or use a different aggregation.";

    /** Distinct guests by date, the stores refused together. */
    private function distinctGuests(): array
    {
        $this->refuseTogether = true;

        return $this->visits(['date'], [
            ['id' => 'c1', 'alias' => 'v', 'column' => 'amount', 'agg' => 'sum'],
            ['id' => 'c2', 'alias' => 'v', 'column' => 'guest', 'agg' => 'count_distinct', 'label' => 'Guests'],
        ]);
    }

    public function test_a_distinct_count_store_by_store_is_a_validation_error_on_its_column_in_the_preview(): void
    {
        try {
            app(ReportRunner::class)->run($this->distinctGuests(), $this->services(), '1|token', 9, 15, 200, 200);
            $this->fail('The preview totalled distinct counts across stores.');
        } catch (ValidationException $e) {
            $this->assertSame(['columns.1.agg' => [self::DISTINCT]], $e->errors());
        }

        // Refused once together; no store was asked on its own.
        $this->assertCount(1, $this->visitQueries());
    }

    public function test_a_distinct_count_store_by_store_fails_the_run_with_the_same_message(): void
    {
        // The engine on its own: a 422 with the message, the staging file gone.
        try {
            app(ReportRunner::class)->export($this->distinctGuests(), $this->services(), '1|token', 9, 'distinct', fn () => null, fn () => $this->fail('Rows were written.'));
            $this->fail('The run totalled distinct counts across stores.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(self::DISTINCT, $e->getMessage());
        }
        $this->assertFalse(Storage::disk('local')->exists(ReportRunner::stagingPath('distinct')));

        // Through the job: the run keeps the message as its error.
        (require base_path('database/migrations/2026_11_02_000001_create_report_templates_table.php'))->up();
        (require base_path('database/migrations/2026_11_02_000002_create_report_runs_table.php'))->up();
        Cache::put('report-builder:catalog:'.ReportDataset::contractSha1(), $this->services(), 600);
        $definition = $this->distinctGuests();
        $template = ReportTemplate::query()->create(['owner_user_id' => 9, 'name' => 'Guests', 'visibility' => 'private', 'definition' => $definition]);
        $run = ReportRun::query()->create([
            'template_id' => $template->id,
            'requested_by_user_id' => 9,
            'definition' => $definition,
            'period_from' => '2026-10-01',
            'period_to' => '2026-10-31',
            'format' => 'csv',
            'status' => 'queued',
            'expires_at' => now()->addDays(7),
        ]);

        app()->call([new RunReportJob($run->id, '1|token'), 'handle']);

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(self::DISTINCT, $run->error);
    }

    // ── Reading the result ──────────────────────────────────────────────────────────────

    public function test_the_result_is_read_in_its_index_order_without_a_sort(): void
    {
        $runner = app(ReportRunner::class);
        $call = fn (string $method, ...$args) => (new ReflectionMethod($runner, $method))->invoke($runner, ...$args);
        $cases = [
            'two sort columns' => [['store', 'date'], [['id' => 'c1', 'dir' => 'desc'], ['id' => 'c2', 'dir' => 'asc']], 'SCAN result USING INDEX result_order'],
            'the grain alone' => [['store', 'date'], [], 'SCAN result USING INDEX result_order'],
            'no grain, no sort' => [[], [], 'SCAN result'],
        ];

        foreach ($cases as $case => [$grain, $sort, $scan]) {
            $report = $call('prepare', $this->definition(['t' => 'x.tickets', 's' => 'x.sales'], $grain, [
                ['id' => 'c1', 'alias' => 't', 'column' => 'cost', 'agg' => 'sum'],
                ['id' => 'c2', 'alias' => 's', 'column' => 'net', 'agg' => 'sum'],
            ], ['sort' => $sort]), $this->services(), 9, 'America/New_York');

            /** @var SQLite3 $db */
            $db = $call('open', ':memory:');
            foreach ($report['plans'] as $i => $plan) {
                $call('stage', $db, $i, $report['grain'], $plan, function (callable $put) {
                    foreach (range(1, 3000) as $n) {
                        // Empty cells, repeated values, numbers to compare as numbers.
                        $put(['store' => 'S'.($n % 40), 'date' => sprintf('2026-10-%02d', $n % 28 + 1), 'c1' => $n % 9 === 0 ? null : $n % 13, 'c2' => $n % 11 === 0 ? null : 3000 - $n]);
                    }
                }, fn () => null);
            }
            $call('merge', $db, $report, fn () => null);

            foreach ([null, 25] as $limit) {
                $sql = $call('orderedSql', $report, $limit);
                $plan = [];
                $explain = $db->query("EXPLAIN QUERY PLAN {$sql}");
                while (($line = $explain->fetchArray(SQLITE3_ASSOC)) !== false) {
                    $plan[] = $line['detail'];
                }
                // No "USE TEMP B-TREE FOR ORDER BY": the index is the order.
                $this->assertSame([$scan], $plan, "{$case}: {$sql}");

                // And it is the order a sort gives, empty cells last.
                $sorted = [];
                $rows = $db->query(str_replace('FROM result', 'FROM result NOT INDEXED', $sql));
                while (($row = $rows->fetchArray(SQLITE3_NUM)) !== false) {
                    $sorted[] = json_decode($row[0], true);
                }
                $this->assertSame($sorted, iterator_to_array($call('ordered', $db, $report, $limit, fn () => null), false), $case);
            }
            $db->close();
        }
    }
}
