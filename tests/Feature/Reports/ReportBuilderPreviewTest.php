<?php

namespace Tests\Feature\Reports;

use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\DailySalesDataset;
use App\Reports\Datasets\KeyValuesDataset;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FakesAuthServer;

/**
 * The engine end to end: catalog fan-out, one grouped query per dataset with
 * the cursor loop, the outer merge, derived and formula columns, sort, limit.
 * The dataset services are faked; each fake answers from a fixture the way
 * the contract does (group keys, then aggregates by alias).
 */
class ReportBuilderPreviewTest extends TestCase
{
    use FakesAuthServer;

    private const SALES = [
        ['store' => '03795-00001', 'date' => '2026-10-21', 'net_sales' => 100, 'total_orders' => 4],
        ['store' => '03795-00002', 'date' => '2026-10-21', 'net_sales' => 50, 'total_orders' => 0],
    ];

    private const VALUES = [
        ['store' => '03795-00001', 'date' => '2026-10-21', 'value' => 25],
        ['store' => '03795-00003', 'date' => '2026-10-21', 'value' => 3],
    ];

    /** Answers the daily sales query in place of the fixture one, when a test sets it. */
    private ?Closure $salesAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('image_path')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('user_store_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('store_id')->nullable();
            $table->string('role_name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        config([
            'services.report_datasets.urls' => [
                'data' => 'http://data.test/api',
                'maintenance' => 'http://maintenance.test/api',
                'operations' => 'http://operations.test/api',
                'toolbox' => null,
            ],
        ]);

        $this->fakeAuthServer();

        Http::fake([
            'data.test/api/report-datasets' => Http::response([
                'service' => 'Data',
                'contract_version' => 1,
                'contract_sha1' => ReportDataset::contractSha1(),
                'datasets' => [(new DailySalesDataset())->toArray(), (new KeyValuesDataset())->toArray()],
            ]),
            'data.test/api/report-datasets/data.daily_sales/query' => fn (Request $request) => $this->salesAnswer !== null
                ? ($this->salesAnswer)($request)
                : $this->answer($request, self::SALES),
            'data.test/api/report-datasets/data.key_values/query' => fn (Request $request) => $this->answer($request, self::VALUES),
            'maintenance.test/*' => Http::response(['service' => 'maintenance', 'contract_version' => 2, 'datasets' => []]),
            'operations.test/*' => Http::failedConnection(),
        ]);
    }

    /** The contract's answer, one row per page so the cursor loop runs; a store "B" is refused. */
    private function answer(Request $request, array $fixture)
    {
        if (in_array('03795-00002', $request['stores'], true) && $request['group_by'] === ['store']) {
            return Http::response(['message' => 'Forbidden'], 403);
        }

        $rows = [];
        foreach ($fixture as $row) {
            if ($request['stores'] === [] || in_array($row['store'], $request['stores'], true)) {
                $rows[] = [
                    ...array_map(fn (string $key) => $row[$key], $request['group_by']),
                    ...array_map(fn (array $aggregate) => $row[$aggregate['column']] ?? null, $request['aggregates']),
                ];
            }
        }
        $page = $request['cursor'] === null ? 0 : 1;

        return Http::response([
            'columns' => array_map(fn (string $key) => ['key' => $key, 'type' => 'number'], [...$request['group_by'], ...array_column($request['aggregates'], 'alias')]),
            'rows' => $page === 0 ? array_slice($rows, 0, 1) : array_slice($rows, 1),
            'next_cursor' => $page === 0 && count($rows) > 1 ? 'page-2' : null,
            'truncated' => false,
            'meta' => ['row_count' => 1, 'elapsed_ms' => 1, 'contract_version' => 1],
        ]);
    }

    private function definition(array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            'datasets' => [['alias' => 's', 'key' => 'data.daily_sales'], ['alias' => 'k', 'key' => 'data.key_values']],
            'grain' => ['store', 'date'],
            'columns' => [
                ['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum', 'label' => 'Net sales'],
                ['id' => 'c2', 'alias' => 's', 'column' => 'avg_order_value', 'label' => 'AOV'],
                ['id' => 'c3', 'alias' => 'k', 'column' => 'value', 'agg' => 'sum', 'label' => 'Labor'],
                ['id' => 'c5', 'kind' => 'formula', 'expr' => 'round(c4, 1)', 'label' => 'Labor % (1dp)'],
                ['id' => 'c4', 'kind' => 'formula', 'expr' => 'c3 / c1 * 100', 'label' => 'Labor %', 'type' => 'number', 'format' => 'percent'],
            ],
            'filters' => [['alias' => 'k', 'column' => 'key', 'op' => 'eq', 'value' => 'Labor cost']],
            'sort' => [['id' => 'c1', 'dir' => 'desc']],
            'period' => ['type' => 'fixed', 'from' => '2026-10-21', 'to' => '2026-10-21'],
            'stores' => ['mode' => 'list', 'codes' => ['03795-00001', '03795-00002', '03795-00003']],
        ], $overrides);
    }

    public function test_it_merges_the_datasets_on_the_grain_and_computes_the_formulas(): void
    {
        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->definition()], $this->headers())->assertOk();

        $this->assertSame(['store', 'date', 'c1', 'c2', 'c3', 'c5', 'c4'], array_column($response->json('columns'), 'id'));
        $this->assertSame(['string', 'date', 'money', 'money', 'number', 'number', 'number'], array_column($response->json('columns'), 'type'));
        $this->assertSame('percent', $response->json('columns.6.format'));

        // Outer merge: store 2 has no labor, store 3 no sales; empty cells sort last.
        $this->assertSame([
            ['03795-00001', '2026-10-21', 100, 25, 25, 25, 25],
            ['03795-00002', '2026-10-21', 50, null, null, null, null],
            ['03795-00003', '2026-10-21', null, null, 3, null, null],
        ], $response->json('rows'));
        $this->assertFalse($response->json('truncated'));
        $this->assertSame(['from' => '2026-10-21', 'to' => '2026-10-21'], $response->json('meta.period'));

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'data.key_values/query')
            && $request->hasHeader('Authorization', 'Bearer 1|test-token')
            && $request['group_by'] === ['store', 'date']
            && $request['aggregates'] === [['column' => 'value', 'fn' => 'sum', 'alias' => 'c3']]
            && $request['filters'] === [['column' => 'key', 'op' => 'eq', 'value' => 'Labor cost']]
            && $request['limit'] === 200
            && ! isset($request['timeout_ms']));
        // The derived AOV is asked for through its inputs, under a prefix no dataset column has.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'data.daily_sales/query')
            && $request['aggregates'] === [
                ['column' => 'net_sales', 'fn' => 'sum', 'alias' => 'c1'],
                ['column' => 'net_sales', 'fn' => 'sum', 'alias' => '_d_net_sales'],
                ['column' => 'total_orders', 'fn' => 'sum', 'alias' => '_d_total_orders'],
            ]);
    }

    private function salesPage(array $stores, bool $more): PromiseInterface
    {
        return Http::response([
            'columns' => [['key' => 'store', 'type' => 'string'], ['key' => 'c1', 'type' => 'money']],
            'rows' => array_map(fn (string $store) => [$store, 5], $stores),
            'next_cursor' => $more ? 'more' : null,
            'truncated' => $more,
            'meta' => ['row_count' => count($stores), 'elapsed_ms' => 1, 'contract_version' => 1],
        ]);
    }

    private function salesOnly(array $overrides = []): array
    {
        return $this->definition($overrides + [
            'datasets' => [['alias' => 's', 'key' => 'data.daily_sales']],
            'grain' => ['store'],
            'columns' => [['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum']],
            'filters' => [],
            'sort' => [],
        ]);
    }

    private function salesQueries(): Collection
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), 'data.daily_sales/query'))->map(fn (array $pair) => $pair[0]);
    }

    public function test_the_preview_takes_at_most_200_rows_of_a_dataset_and_says_it_was_cut(): void
    {
        // A service with endless rows: every page is full and has a next one.
        $this->salesAnswer = fn (Request $request) => $this->salesPage(
            array_map(fn (int $i) => sprintf('03795-%05d', $i), range(1, $request['limit'])),
            true,
        );

        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
            'stores' => ['mode' => 'list', 'codes' => ['03795-00001']],
        ])], $this->headers())->assertOk();

        $this->assertCount(200, $response->json('rows'));
        $this->assertTrue($response->json('truncated'));
        $this->assertCount(1, $this->salesQueries());
        $this->assertSame(200, $this->salesQueries()->first()['limit']);
    }

    public function test_the_row_cap_also_covers_the_store_by_store_fallback(): void
    {
        DB::table('user_store_roles')->insert([
            ['id' => 1, 'user_id' => 9, 'store_id' => '03795-00001', 'role_name' => 'Store Manager', 'active' => true],
            ['id' => 2, 'user_id' => 9, 'store_id' => '03795-00002', 'role_name' => 'Store Manager', 'active' => true],
        ]);
        // Both stores together are refused; 150 rows for the first, endless for the second.
        $this->salesAnswer = fn (Request $request) => match (true) {
            count($request['stores']) > 1 => Http::response(['message' => 'Forbidden'], 403),
            $request['stores'] === ['03795-00001'] => $this->salesPage(array_map(fn (int $i) => sprintf('10000-%05d', $i), range(1, 150)), false),
            default => $this->salesPage(array_map(fn (int $i) => sprintf('20000-%05d', $i), range(1, $request['limit'])), true),
        };

        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
            'stores' => ['mode' => 'mine'],
        ])], $this->headers())->assertOk();

        $this->assertCount(200, $response->json('rows'));
        $this->assertTrue($response->json('truncated'));
        $this->assertSame(50, $this->salesQueries()->last()['limit']);
    }

    public function test_the_preview_gives_up_after_its_deadline_across_the_pages_of_a_dataset(): void
    {
        config(['services.report_datasets.preview_timeout' => 1]);
        $this->salesAnswer = function () {
            usleep(1_100_000);

            return $this->salesPage(['03795-00001'], true);
        };

        $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
            'stores' => ['mode' => 'list', 'codes' => ['03795-00001']],
        ])], $this->headers())
            ->assertStatus(504)
            ->assertJsonPath('message', 'The report ran out of time.');

        $this->assertCount(1, $this->salesQueries());
    }

    public function test_the_preview_deadline_is_shared_by_every_dataset(): void
    {
        config(['services.report_datasets.preview_timeout' => 1]);
        $this->salesAnswer = function () {
            usleep(1_100_000);

            return $this->salesPage(['03795-00001'], false);
        };

        $this->postJson('/api/report-builder/preview', ['definition' => $this->definition([
            'columns' => [
                ['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum'],
                ['id' => 'c3', 'alias' => 'k', 'column' => 'value', 'agg' => 'sum'],
            ],
            'grain' => ['store'],
            'sort' => [],
        ])], $this->headers())->assertStatus(504);

        // The first dataset answered, in time for nothing: the second is never asked.
        $this->assertCount(1, $this->salesQueries());
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), 'data.key_values/query'));
    }

    public function test_a_mine_role_without_a_store_means_every_store(): void
    {
        DB::table('user_store_roles')->insert([
            ['id' => 1, 'user_id' => 9, 'store_id' => null, 'role_name' => 'Admin', 'active' => true],
            ['id' => 2, 'user_id' => 9, 'store_id' => '03795-00001', 'role_name' => 'Store Manager', 'active' => true],
        ]);

        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
            'stores' => ['mode' => 'mine'],
        ])], $this->headers())->assertOk();

        $this->assertSame([['03795-00001', 100], ['03795-00002', 50]], $response->json('rows'));
        $this->assertSame([], $this->salesQueries()->first()['stores']);
    }

    public function test_mine_without_any_store_role_is_an_empty_report_and_asks_nobody(): void
    {
        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
            'stores' => ['mode' => 'mine'],
        ])], $this->headers())->assertOk();

        $this->assertSame([], $response->json('rows'));
        $this->assertSame(['store', 'c1'], array_column($response->json('columns'), 'id'));
        $this->assertFalse($response->json('truncated'));
        $this->assertCount(0, $this->salesQueries());
    }

    public function test_a_store_code_must_have_the_code_format(): void
    {
        foreach (['03795', '0379500001', 3795, '03795-0001'] as $code) {
            $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
                'stores' => ['mode' => 'list', 'codes' => [$code]],
            ])], $this->headers())
                ->assertUnprocessable()
                ->assertJsonValidationErrors('definition.stores.codes.0');
        }
    }

    public function test_the_request_takes_any_number_of_columns_stores_filters_and_sorts_and_any_period(): void
    {
        $columns = array_map(fn (int $i) => ['id' => "c{$i}", 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum'], range(1, 60));
        $columns[] = ['id' => 'c61', 'kind' => 'formula', 'expr' => str_repeat('c1 + ', 300).'0'];

        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
            'columns' => $columns,
            'filters' => array_map(fn (int $i) => ['id' => 'c1', 'op' => 'not_in', 'value' => range(1000, 1700)], range(1, 60)),
            'sort' => array_map(fn (int $i) => ['id' => "c{$i}", 'dir' => 'desc'], range(1, 20)),
            'period' => ['type' => 'fixed', 'from' => '2023-11-01', 'to' => '2026-10-31'],
            'stores' => ['mode' => 'list', 'codes' => ['03795-00001', ...array_map(fn (int $i) => sprintf('04000-%05d', $i), range(1, 999))]],
        ])], $this->headers())->assertOk();

        $this->assertSame([['03795-00001', ...array_fill(0, 60, 100), 30000]], $response->json('rows'));
        $this->assertSame('2023-11-01', $this->salesQueries()->first()['date_from']);
        $this->assertCount(1000, $this->salesQueries()->first()['stores']);
    }

    public function test_a_list_for_a_filter_that_takes_one_value_is_a_422_not_a_500(): void
    {
        foreach (['eq', 'gt', 'contains'] as $op) {
            $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
                'filters' => [['id' => 'c1', 'op' => $op, 'value' => [1, 2]]],
                'stores' => ['mode' => 'list', 'codes' => ['03795-00001']],
            ])], $this->headers())
                ->assertUnprocessable()
                ->assertJsonPath('errors', ['filters.0' => ['Send one value.']]);
        }
    }

    public function test_a_bad_formula_is_reported_in_laravels_validation_body(): void
    {
        $definition = $this->definition();
        $definition['columns'][4]['expr'] = 'c3 / (c1';

        $response = $this->postJson('/api/report-builder/preview', ['definition' => $definition], $this->headers())->assertUnprocessable();

        // {message, errors: {"columns.4.expr": [text]}}, which is what the builder reads.
        $this->assertSame(['message', 'errors'], array_keys($response->json()));
        $this->assertSame(['columns.4.expr'], array_keys($response->json('errors')));
        $this->assertSame(
            ["c4: Expected ')' but found end of formula at position 9. In: c3 / (c1"],
            $response->json('errors')['columns.4.expr'],
        );
        $this->assertStringStartsWith("c4: Expected ')'", $response->json('message'));
    }

    public function test_the_preview_is_throttled_as_a_heavy_route(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/report-builder/preview', 'POST'));

        $this->assertContains('throttle:api-heavy', $route->gatherMiddleware());
        $this->assertContains('throttle:api', $route->gatherMiddleware());
    }

    public function test_a_service_failing_in_its_own_words_is_not_quoted(): void
    {
        $this->salesAnswer = fn () => Http::response(['message' => 'SQLSTATE[HY000] secret internals'], 500);

        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->salesOnly([
            'stores' => ['mode' => 'list', 'codes' => ['03795-00001']],
        ])], $this->headers())->assertStatus(502);

        $this->assertSame('data (data.daily_sales) could not answer.', $response->json('message'));
    }

    public function test_filters_on_report_columns_and_the_limit_run_after_the_merge(): void
    {
        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->definition([
            'filters' => [['id' => 'c3', 'op' => 'not_null']],
            'limit' => 1,
        ])], $this->headers())->assertOk();

        $this->assertSame([['03795-00001', '2026-10-21', 100, 25, 25, 25, 25]], $response->json('rows'));
        $this->assertTrue($response->json('truncated'));
    }

    public function test_mine_asks_store_by_store_after_a_403_and_reports_the_stores_left_out(): void
    {
        DB::table('user_store_roles')->insert([
            ['id' => 1, 'user_id' => 9, 'store_id' => '03795-00001', 'role_name' => 'Store Manager', 'active' => true],
            ['id' => 2, 'user_id' => 9, 'store_id' => '03795-00002', 'role_name' => 'Store Manager', 'active' => true],
        ]);

        $response = $this->postJson('/api/report-builder/preview', ['definition' => $this->definition([
            'datasets' => [['alias' => 's', 'key' => 'data.daily_sales']],
            'grain' => ['store'],
            'columns' => [['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum']],
            'filters' => [],
            'stores' => ['mode' => 'mine'],
        ])], $this->headers())->assertOk();

        $this->assertSame([['03795-00001', 100]], $response->json('rows'));
        $this->assertSame(['s' => ['03795-00002']], $response->json('meta.skipped_stores'));
    }

    public function test_a_definition_the_catalog_does_not_support_is_a_422(): void
    {
        $definition = $this->definition();
        $definition['columns'][4]['expr'] = 'c4 + c9';

        $this->postJson('/api/report-builder/preview', ['definition' => $definition], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors', [
                'columns.4.expr' => ['c4 refers to unknown column c9 in: c4 + c9'],
                'columns' => ['Formulas refer to each other in a cycle: c4 → c4 (in: c4 + c9)'],
            ]);
    }

    public function test_the_catalog_lists_services_that_fail_with_their_error(): void
    {
        $response = $this->getJson('/api/report-builder/catalog', $this->headers())->assertOk();

        $this->assertSame(1, $response->json('contract_version'));
        // A service is named as it reports itself (the name canAccessRoute takes); origin is the key it was asked under.
        $this->assertSame(['Data', 'maintenance', 'operations'], array_column($response->json('services'), 'service'));
        $this->assertSame(['data', 'maintenance', 'operations'], array_column($response->json('services'), 'origin'));
        $this->assertSame(['data.daily_sales', 'data.key_values'], array_column($response->json('services.0.datasets'), 'key'));
        $this->assertArrayNotHasKey('warning', $response->json('services.0'));
        $this->assertSame('maintenance speaks report contract v2, Data speaks v1.', $response->json('services.1.error'));
        $this->assertSame('operations is not answering.', $response->json('services.2.error'));
    }
}
