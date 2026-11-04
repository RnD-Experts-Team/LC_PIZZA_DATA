<?php

namespace Tests\Feature\Reports;

use App\Http\Requests\ReportDatasetQueryRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\FakesAuthServer;

/**
 * The contract routes end to end, through the real auth middleware with
 * pizzasys faked. The aggregation connection is pointed at in-memory sqlite.
 */
class ReportDatasetQueryTest extends TestCase
{
    use FakesAuthServer;

    private const URL = '/api/report-datasets/data.daily_sales/query';

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
        Schema::create('employee_debrief_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->timestamps();
        });

        config(['database.connections.aggregation' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('aggregation');
        Schema::connection('aggregation')->create('daily_store_summary', function (Blueprint $table) {
            $table->id();
            $table->string('franchise_store', 20);
            $table->date('business_date');
            $table->decimal('net_sales', 15, 2)->default(0);
            $table->integer('total_orders')->default(0);
        });
        DB::connection('aggregation')->table('daily_store_summary')->insert([
            ['franchise_store' => '03795-00044', 'business_date' => '2026-10-21', 'net_sales' => 1200.50, 'total_orders' => 80],
            ['franchise_store' => '03795-00044', 'business_date' => '2026-10-22', 'net_sales' => 980.25, 'total_orders' => 61],
            ['franchise_store' => '03795-00042', 'business_date' => '2026-10-21', 'net_sales' => 700.00, 'total_orders' => 40],
            ['franchise_store' => '03795-00044', 'business_date' => '2026-10-30', 'net_sales' => 5.00, 'total_orders' => 1],
        ]);

        $this->fakeAuthServer();
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'stores' => ['03795-00044'],
            'date_from' => '2026-10-21',
            'date_to' => '2026-10-27',
            'columns' => ['store', 'date'],
            'group_by' => ['store', 'date'],
            'aggregates' => [['column' => 'net_sales', 'fn' => 'sum', 'alias' => 'net_sales']],
            'limit' => 50,
        ], $overrides);
    }

    public function test_it_returns_the_requested_stores_rows_positionally(): void
    {
        $this->postJson(self::URL, $this->body(), $this->headers())
            ->assertOk()
            ->assertJson([
                'columns' => [['key' => 'store', 'type' => 'string'], ['key' => 'date', 'type' => 'date'], ['key' => 'net_sales', 'type' => 'money']],
                'rows' => [['03795-00044', '2026-10-21', 1200.5], ['03795-00044', '2026-10-22', 980.25]],
                'next_cursor' => null,
                'truncated' => false,
                'meta' => ['row_count' => 2, 'contract_version' => 1],
            ]);

        // pizzasys judged this exact route, with the store codes of the body.
        Http::assertSent(fn (Request $request) => $request['path'] === self::URL
            && $request['method'] === 'POST'
            && $request['store_context']['body']['stores'] === ['03795-00044']
            && $request['store_context']['path']['dataset'] === 'data.daily_sales');
    }

    public function test_stream_mode_sends_ndjson_columns_rows_and_the_done_line(): void
    {
        $response = $this->postJson(self::URL, $this->body(['stream' => true, 'limit' => null]), $this->headers())->assertOk();

        $this->assertSame('application/x-ndjson', $response->headers->get('Content-Type'));
        $lines = array_map(fn (string $line) => json_decode($line, true), explode("\n", rtrim($response->streamedContent(), "\n")));
        $this->assertSame(['columns' => [['key' => 'store', 'type' => 'string'], ['key' => 'date', 'type' => 'date'], ['key' => 'net_sales', 'type' => 'money']]], $lines[0]);
        $this->assertEqualsCanonicalizing([['03795-00044', '2026-10-21', 1200.5], ['03795-00044', '2026-10-22', 980.25]], array_slice($lines, 1, -1));
        $this->assertSame(['done' => true, 'row_count' => 2], end($lines));
    }

    public function test_stream_mode_ends_with_the_error_line_when_the_query_fails(): void
    {
        $response = $this->postJson(self::URL, $this->body(['stream' => true, 'limit' => null]), $this->headers())->assertOk();
        Schema::connection('aggregation')->drop('daily_store_summary');

        $this->assertSame(
            '{"columns":[{"key":"store","type":"string"},{"key":"date","type":"date"},{"key":"net_sales","type":"money"}]}'."\n"
            .'{"error":"The dataset query failed before it sent every row."}'."\n",
            $response->streamedContent(),
        );
    }

    public function test_a_bad_stream_query_is_still_a_422(): void
    {
        $this->postJson(self::URL, $this->body(['stream' => true]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.limit', ['A stream sends every row; it takes no limit.']);
    }

    public function test_no_stores_means_every_store(): void
    {
        $this->postJson(self::URL, $this->body(['stores' => [], 'columns' => [], 'group_by' => ['store']]), $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00042', 700], ['03795-00044', 2180.75]]);
    }

    public function test_a_store_pizzasys_refuses_is_a_403(): void
    {
        $this->tokenAuthorized = false;

        $this->postJson(self::URL, $this->body(['stores' => ['03795-00042']]), $this->headers())->assertForbidden();
    }

    public function test_unknown_or_derived_columns_are_a_422(): void
    {
        $this->postJson(self::URL, $this->body(['columns' => ['store', 'royalty_secret']]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors.columns', ['Unknown column royalty_secret.']);

        $this->postJson(self::URL, $this->body(['aggregates' => [['column' => 'avg_order_value', 'fn' => 'sum']]]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['aggregates.0.column' => ['avg_order_value is derived; the report engine computes it.']]);

        $this->postJson(self::URL, $this->body(['date_to' => '2026-10-01']), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_to');
    }

    public function test_store_codes_must_have_the_code_format(): void
    {
        foreach (['3795', '0379500044', '03795-0004', 'abcde-12345', '03795-000445'] as $code) {
            $this->postJson(self::URL, $this->body(['stores' => [$code]]), $this->headers())
                ->assertUnprocessable()
                ->assertJsonValidationErrors('stores.0');
        }
    }

    public function test_a_caller_cannot_set_the_statement_timeout(): void
    {
        $this->assertArrayNotHasKey('timeout_ms', (new ReportDatasetQueryRequest())->rules());

        // Not an error any more, and not read either.
        $this->postJson(self::URL, $this->body(['timeout_ms' => 999999]), $this->headers())->assertOk();
    }

    public function test_the_engines_derived_input_aliases_are_accepted(): void
    {
        $this->postJson(self::URL, $this->body(['aggregates' => [['column' => 'net_sales', 'fn' => 'sum', 'alias' => '_d_net_sales']]]), $this->headers())
            ->assertOk()
            ->assertJsonPath('columns.2.key', '_d_net_sales');

        $this->postJson(self::URL, $this->body(['aggregates' => [['column' => 'net_sales', 'fn' => 'sum', 'alias' => '__x']]]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('aggregates.0.alias');
    }

    public function test_a_preview_with_a_derived_column_filters_a_dataset_column_per_row(): void
    {
        // A second row on the 21st: 300 net sales, 10 orders.
        DB::connection('aggregation')->table('daily_store_summary')->insert([
            ['franchise_store' => '03795-00044', 'business_date' => '2026-10-21', 'net_sales' => 300.00, 'total_orders' => 10],
        ]);

        // Data answers its own contract routes, through the real app.
        config(['services.report_datasets.urls' => ['data' => 'http://data.test/api']]);
        Http::fake([
            'data.test/api/report-datasets' => fn () => Http::response($this->getJson('/api/report-datasets', $this->headers())->json()),
            'data.test/api/report-datasets/*' => function (Request $request) {
                $response = $this->postJson((string) parse_url($request->url(), PHP_URL_PATH), $request->data(), $this->headers());

                return Http::response($response->json(), $response->status());
            },
        ]);

        $response = $this->postJson('/api/report-builder/preview', ['definition' => [
            'v' => 1,
            'datasets' => [['alias' => 's', 'key' => 'data.daily_sales']],
            'grain' => ['store', 'date'],
            'columns' => [
                ['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum'],
                ['id' => 'c2', 'alias' => 's', 'column' => 'avg_order_value'],
            ],
            'filters' => [['alias' => 's', 'column' => 'net_sales', 'op' => 'gt', 'value' => 1000]],
            'period' => ['type' => 'fixed', 'from' => '2026-10-21', 'to' => '2026-10-22'],
            'stores' => ['mode' => 'list', 'codes' => ['03795-00044']],
        ]], $this->headers())->assertOk();

        // Per row: only the 1200.50 row passes 1000; per group (the bug) 1500.50 / 90 orders would.
        $this->assertCount(1, $response->json('rows'));
        $this->assertSame(['03795-00044', '2026-10-21'], array_slice($response->json('rows.0'), 0, 2));
        $this->assertEqualsWithDelta(1200.5, $response->json('rows.0.2'), 0.0001);
        $this->assertEqualsWithDelta(1200.5 / 80, $response->json('rows.0.3'), 0.0001);
    }

    public function test_the_catalog_service_is_the_pizzasys_service_name(): void
    {
        config(['services.auth_server.service_name' => 'Data']);

        $this->getJson('/api/report-datasets', $this->headers())->assertOk()->assertJsonPath('service', 'Data');
    }

    public function test_the_catalog_lists_every_dataset_with_the_contract_version(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())
            ->assertOk()
            ->assertJsonPath('service', config('services.auth_server.service_name'))
            ->assertJsonPath('contract_version', 1)
            ->assertJsonPath('datasets.0.key', 'data.daily_sales')
            ->assertJsonPath('datasets.0.path', '/report-datasets/data.daily_sales/query')
            ->assertJsonPath('datasets.0.grain', ['store', 'date']);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $response->json('contract_sha1'));
        $keys = array_column($response->json('datasets'), 'key');
        foreach (['data.daily_sales', 'data.employee_debriefs', 'data.key_values'] as $key) {
            $this->assertContains($key, $keys);
        }
        $this->assertTrue($response->json('datasets.0.dated'));
        $this->assertArrayNotHasKey('max_days', $response->json('datasets.0'));
        $this->assertContains(
            ['key' => 'avg_order_value', 'derived' => 'net_sales / total_orders'],
            array_map(fn (array $c) => ['key' => $c['key'], 'derived' => $c['derived']], $response->json('datasets.0.columns')),
        );
    }
}
