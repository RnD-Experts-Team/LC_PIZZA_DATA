<?php

namespace Tests\Feature\Reports;

use App\Reports\Contract\DatasetQueryCompiler;
use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\Support\WarehouseDataset;
use App\Reports\ReportDatasets;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesAuthServer;

/**
 * The datasets that expose what the Data service holds. Every table is created
 * from the real migration files (their column names, not their SQL), so a
 * column a dataset reads that the migrations do not have fails here. The
 * aggregation database and the app tables sit on in-memory sqlite. For the raw
 * warehouse the hot tables live in an attached in-memory database named
 * ":memory:" (the name the operational connection reports as its database) and
 * the archive tables in one attached as "archive_db", so the dataset's real
 * UNION ALL of two fully qualified tables runs unchanged on sqlite.
 */
class DataDatasetsTest extends TestCase
{
    use FakesAuthServer;

    private const NEW_KEYS = [
        'data.daily_items', 'data.hourly_sales', 'data.hourly_items',
        'data.orders', 'data.order_lines', 'data.summary_items', 'data.summary_sales', 'data.summary_transactions',
        'data.waste', 'data.cash', 'data.financials', 'data.transactions',
        'data.alta_cogs', 'data.alta_ingredient_orders', 'data.alta_ingredient_usage', 'data.alta_waste',
        'data.goals', 'data.non_negotiables', 'data.go_to_calls', 'data.transfers', 'data.inventory_orders',
        'data.cleaning_reviews', 'data.customer_service', 'data.hnr_plus',
    ];

    private const WAREHOUSE = [
        'detail_orders', 'order_line', 'summary_sales', 'summary_items', 'summary_transactions', 'waste', 'cash_management',
        'financial_views', 'alta_inventory_cogs', 'alta_inventory_ingredient_orders', 'alta_inventory_ingredient_usage',
        'alta_inventory_waste', 'detail_transactions',
    ];

    private const AGGREGATION = ['daily_store_summary', 'hourly_store_summary', 'daily_item_summary', 'hourly_item_summary'];

    private const APP = [
        'goal_metrics' => '2026_05_21_000001_create_goal_metrics_table.php',
        'goals' => '2026_05_21_000002_create_goals_table.php',
        'non_negotiable_reports' => '2026_06_12_213616_create_non_negotiable_reports_table.php',
        'go_to_calls' => '2026_06_15_000001_create_go_to_calls_table.php',
        'go_to_call_participants' => '2026_06_15_000002_create_go_to_call_participants_table.php',
        'transfer_in_outs' => '2026_06_16_132130_create_transfer_in_outs_table.php',
        'inventory_orders' => '2026_06_16_133248_create_inventory_orders_table.php',
        'cleaning_reviews' => '2026_07_02_000002_create_cleaning_reviews_table.php',
        'customer_services' => '2026_07_09_000001_create_customer_services_table.php',
        'hnr_plus_items' => '2026_09_02_000001_create_hnr_plus_items_table.php',
    ];

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
        // The debriefs dataset reads its types when the catalog is built.
        Schema::create('employee_debrief_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->timestamps();
        });

        $memory = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false];
        config([
            'database.connections.aggregation' => $memory,
            'database.connections.operational' => $memory,
            // Never connected to: the dataset only reads the name to qualify the archive tables.
            'database.connections.archive' => [...$memory, 'database' => 'archive_db'],
        ]);
        DB::purge('aggregation');
        DB::purge('operational');

        $operational = DB::connection('operational');
        $operational->statement('ATTACH DATABASE \':memory:\' AS ":memory:"');
        $operational->statement('ATTACH DATABASE \':memory:\' AS "archive_db"');

        foreach (self::AGGREGATION as $table) {
            $this->createFromMigrations('aggregation', null, $this->migrations('aggregation', "*_create_{$table}_table.php"));
        }
        foreach (self::WAREHOUSE as $table) {
            $this->createFromMigrations('operational', ':memory:', [
                ...$this->migrations('operational', "*_create_{$table}_hot_table.php"),
                ...$this->migrations('operational', "*_to_{$table}_hot_table.php"),
            ]);
            $this->createFromMigrations('operational', 'archive_db', [
                ...$this->migrations('archive', "*_create_{$table}_archive_table.php"),
                ...$this->migrations('archive', "*_to_{$table}_archive_table.php"),
            ]);
        }
        foreach (self::APP as $file) {
            $this->createFromMigrations(null, null, [base_path("database/migrations/{$file}")]);
        }

        $this->fakeAuthServer();
    }

    // ── Catalog ──────────────────────────────────────────────────────────────

    public function test_the_catalog_lists_every_new_dataset_as_dated_with_labelled_columns(): void
    {
        $response = $this->getJson('/api/report-datasets', $this->headers())->assertOk();

        $datasets = array_column($response->json('datasets'), null, 'key');
        foreach (self::NEW_KEYS as $key) {
            $this->assertArrayHasKey($key, $datasets, $key);
            $this->assertTrue($datasets[$key]['dated'], "{$key} is dated");
            $this->assertSame("/report-datasets/{$key}/query", $datasets[$key]['path']);
            $this->assertContains('store', $datasets[$key]['grain']);
            $this->assertContains('date', $datasets[$key]['grain']);
            $this->assertEmpty(array_diff($datasets[$key]['grain'], ['store', 'date', 'employee']));
        }
        // The three that were there keep their keys.
        foreach (['data.daily_sales', 'data.employee_debriefs', 'data.key_values'] as $key) {
            $this->assertArrayHasKey($key, $datasets);
        }
        $this->assertCount(count(ReportDatasets::DATASETS), $datasets);

        $this->assertSame(
            ['data.order_lines', 'data.orders', 'data.transactions'],
            collect($datasets)->filter(fn (array $d) => $d['heavy'])->keys()->sort()->values()->all(),
        );

        foreach ($datasets as $key => $dataset) {
            $keys = array_column($dataset['columns'], 'key');
            $this->assertSame($keys, array_values(array_unique($keys)), "{$key} repeats a column key");
            foreach ($dataset['columns'] as $column) {
                $this->assertNotSame('', trim($column['label']), "{$key}.{$column['key']} has no label");
                $this->assertNotSame($column['key'], $column['label'], "{$key}.{$column['key']} label is its key");
            }
        }
    }

    public function test_the_daily_sales_dataset_offers_the_whole_daily_store_summary(): void
    {
        $columns = array_column($this->columnsOf('data.daily_sales'), null, 'key');

        $this->assertGreaterThan(80, count($columns));
        foreach (['royalty_obligation', 'refund_amount', 'cancelled_orders', 'pizza_delivery_sales', 'side_items_carryout_quantity', 'sales_tax', 'total_tips', 'over_short', 'hnr_broken_promises', 'digital_sales'] as $key) {
            $this->assertArrayHasKey($key, $columns, $key);
            $this->assertSame(['sum', 'avg', 'min', 'max'], $columns[$key]['aggregations']);
        }
        $this->assertSame('net_sales / total_orders', $columns['avg_order_value']['derived']);
        $this->assertArrayNotHasKey('id', $columns);
        $this->assertArrayNotHasKey('created_at', $columns);
        $this->assertSame(1, count(array_filter($this->columnsOf('data.hourly_sales'), fn (array $c) => $c['key'] === 'hour' && $c['groupable'])));
    }

    public function test_derived_ratios_are_never_aggregatable_groupable_or_filterable_and_read_summable_columns(): void
    {
        $compiler = app(DatasetQueryCompiler::class);

        foreach (ReportDatasets::DATASETS as $class) {
            $dataset = new $class();
            $name = $class::KEY;
            $columns = array_column($dataset->toArray()['columns'], null, 'key');
            $derived = array_filter($columns, fn (array $c) => $c['derived'] !== null);

            foreach ($derived as $key => $column) {
                $this->assertSame([], $column['aggregations'], "{$name}.{$key}");
                $this->assertFalse($column['groupable'], "{$name}.{$key}");
                $this->assertFalse($column['filterable'], "{$name}.{$key}");

                // Every column the formula reads is a plain measure the engine can sum (its first aggregation).
                preg_match_all('/[a-z][a-z0-9_]*/', $column['derived'], $found);
                $inputs = array_values(array_filter($found[0], fn (string $word) => $word !== 'percent'));
                $this->assertNotSame([], $inputs, "{$name}.{$key}");
                foreach ($inputs as $input) {
                    $this->assertArrayHasKey($input, $columns, "{$name}.{$key} reads {$input}");
                    $this->assertNull($columns[$input]['derived'], "{$name}.{$key} reads {$input}");
                    $this->assertNotSame([], $columns[$input]['aggregations'], "{$name}.{$key} reads {$input}");
                }

                try {
                    $compiler->run($dataset, [
                        'stores' => [],
                        'date_from' => '2026-10-01',
                        'date_to' => '2026-10-31',
                        'aggregates' => [['column' => $key, 'fn' => 'sum']],
                    ]);
                    $this->fail("{$name}.{$key} was aggregated");
                } catch (ValidationException $e) {
                    $this->assertSame(["{$key} is derived; the report engine computes it."], $e->errors()['aggregates.0.column']);
                }
            }
        }
    }

    // ── Aggregation warehouse ────────────────────────────────────────────────

    public function test_the_aggregation_datasets_answer_a_grouped_query_with_a_store_filter(): void
    {
        $summary = fn (string $store, string $date, float $net, int $orders, int $gross = 0) => [
            'franchise_store' => $store, 'business_date' => $date, 'net_sales' => $net, 'total_orders' => $orders, 'gross_sales' => $gross,
            'portal_eligible_orders' => 10, 'portal_used_orders' => $orders,
        ];
        DB::connection('aggregation')->table('daily_store_summary')->insert([
            $summary('03795-00044', '2026-10-21', 1200.5, 80, 1300),
            $summary('03795-00044', '2026-10-22', 980.25, 61, 1000),
            $summary('03795-00042', '2026-10-21', 700, 40, 750),
        ]);
        DB::connection('aggregation')->table('hourly_store_summary')->insert([
            [...$summary('03795-00044', '2026-10-21', 100, 5), 'hour' => 12],
            [...$summary('03795-00044', '2026-10-21', 300, 9), 'hour' => 13],
            [...$summary('03795-00042', '2026-10-21', 50, 2), 'hour' => 12],
        ]);
        $item = fn (string $store, string $date, int $quantity, float $gross) => [
            'franchise_store' => $store, 'business_date' => $date, 'item_id' => '1001', 'menu_item_name' => 'Classic Pepperoni',
            'menu_item_account' => 'Pizza', 'quantity_sold' => $quantity, 'gross_sales' => $gross,
        ];
        DB::connection('aggregation')->table('daily_item_summary')->insert([
            $item('03795-00044', '2026-10-21', 30, 270), $item('03795-00044', '2026-10-22', 10, 90), $item('03795-00042', '2026-10-21', 5, 45),
        ]);
        DB::connection('aggregation')->table('hourly_item_summary')->insert([
            [...$item('03795-00044', '2026-10-21', 4, 36), 'hour' => 12], [...$item('03795-00044', '2026-10-21', 6, 54), 'hour' => 13],
            [...$item('03795-00042', '2026-10-21', 1, 9), 'hour' => 12],
        ]);

        $this->query('data.daily_sales', ['columns' => ['store'], 'aggregates' => [['column' => 'gross_sales', 'fn' => 'sum', 'alias' => 'gross'], ['column' => 'portal_used_orders', 'fn' => 'sum', 'alias' => 'used']]])
            ->assertOk()
            ->assertJsonPath('columns', [['key' => 'store', 'type' => 'string'], ['key' => 'gross', 'type' => 'money'], ['key' => 'used', 'type' => 'integer']])
            ->assertJsonPath('rows', [['03795-00044', 2300, 141]]);

        $this->query('data.hourly_sales', ['columns' => ['store', 'hour'], 'aggregates' => [['column' => 'net_sales', 'fn' => 'sum', 'alias' => 'net']]])
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00044', 12, 100], ['03795-00044', 13, 300]]);

        $this->query('data.daily_items', ['columns' => ['store', 'menu_item_name'], 'aggregates' => [['column' => 'quantity_sold', 'fn' => 'sum', 'alias' => 'sold']]])
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00044', 'Classic Pepperoni', 40]]);

        $this->query('data.hourly_items', ['columns' => ['hour'], 'aggregates' => [['column' => 'gross_sales', 'fn' => 'sum', 'alias' => 'gross']]])
            ->assertOk()
            ->assertJsonPath('rows', [[12, 36], [13, 54]]);

        // The engine asks for the inputs of a derived ratio under their own aliases.
        $this->query('data.daily_sales', ['columns' => ['store'], 'aggregates' => [['column' => 'portal_used_orders', 'fn' => 'sum', 'alias' => '_d_portal_used_orders'], ['column' => 'portal_eligible_orders', 'fn' => 'sum', 'alias' => '_d_portal_eligible_orders']]])
            ->assertOk()
            ->assertJsonPath('rows', [['03795-00044', 141, 20]]);

        // pizzasys judged the exact route, with the store codes of the body.
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => ($request['path'] ?? null) === '/api/report-datasets/data.hourly_items/query'
            && $request['store_context']['body']['stores'] === ['03795-00044']);
    }

    // ── Hot + archive ────────────────────────────────────────────────────────

    public function test_the_orders_union_returns_rows_from_the_hot_and_the_archive_tables(): void
    {
        $order = fn (string $store, string $date, string $id, float $royalty, string $method) => [
            'franchise_store' => $store, 'business_date' => $date, 'order_id' => $id, 'transaction_type' => 'Sale',
            'royalty_obligation' => $royalty, 'order_placed_method' => $method, 'refunded' => 'No', 'customer_count' => 1,
        ];
        DB::connection('operational')->table(':memory:.detail_orders_hot')->insert([
            $order('03795-00044', '2026-10-30', 'H1', 25.5, 'Website'),
            $order('03795-00042', '2026-10-30', 'H2', 99, 'Phone'),
        ]);
        DB::connection('operational')->table('archive_db.detail_orders_archive')->insert([
            $order('03795-00044', '2025-12-01', 'A1', 40, 'Phone'),
            $order('03795-00044', '2025-12-01', 'A2', 10, 'Website'),
            $order('03795-00042', '2025-12-01', 'A3', 77, 'Phone'),
        ]);

        // One query across 11 months, so the 90-day boundary is crossed.
        $this->query('data.orders', [
            'date_from' => '2025-11-01',
            'date_to' => '2026-10-31',
            'columns' => ['date', 'order_placed_method'],
            'aggregates' => [['column' => 'orders', 'fn' => 'count_distinct', 'alias' => 'orders'], ['column' => 'royalty_obligation', 'fn' => 'sum', 'alias' => 'royalty']],
        ])
            ->assertOk()
            ->assertJsonPath('rows', [
                ['2025-12-01', 'Phone', 1, 40],
                ['2025-12-01', 'Website', 1, 10],
                ['2026-10-30', 'Website', 1, 25.5],
            ]);

        // The same period without a store filter reads every store of both halves.
        $this->query('data.orders', [
            'stores' => [],
            'date_from' => '2025-11-01',
            'date_to' => '2026-10-31',
            'columns' => [],
            'aggregates' => [['column' => 'royalty_obligation', 'fn' => 'sum', 'alias' => 'royalty']],
        ])->assertOk()->assertJsonPath('rows', [[251.5]]);

        // Only the archive, only the hot table.
        $this->query('data.orders', ['date_from' => '2025-12-01', 'date_to' => '2025-12-31', 'columns' => ['order_id']])
            ->assertOk()->assertJsonPath('rows', [['A1'], ['A2']]);
        $this->query('data.orders', ['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'columns' => ['order_id']])
            ->assertOk()->assertJsonPath('rows', [['H1']]);
    }

    public function test_the_other_warehouse_datasets_union_hot_and_archive_too(): void
    {
        $rows = [
            ['table' => 'order_line', 'dataset' => 'data.order_lines', 'hot' => ['order_id' => 'H1', 'menu_item_account' => 'Pizza', 'net_amount' => 12, 'quantity' => 2], 'archive' => ['order_id' => 'A1', 'menu_item_account' => 'Pizza', 'net_amount' => 8, 'quantity' => 1], 'measure' => 'quantity'],
            ['table' => 'waste', 'dataset' => 'data.waste', 'hot' => ['waste_reason' => 'Dropped', 'item_cost' => 2, 'quantity' => 3], 'archive' => ['waste_reason' => 'Expired', 'item_cost' => 2, 'quantity' => 4], 'measure' => 'waste_cost'],
            ['table' => 'financial_views', 'dataset' => 'data.financials', 'hot' => ['area' => 'Sales', 'sub_account' => 'Total Cash Sales', 'amount' => 100], 'archive' => ['area' => 'Sales', 'sub_account' => 'Total Cash Sales', 'amount' => 50], 'measure' => 'amount'],
            ['table' => 'cash_management', 'dataset' => 'data.cash', 'hot' => ['till' => '1', 'variance' => -2], 'archive' => ['till' => '1', 'variance' => 5], 'measure' => 'variance'],
            ['table' => 'detail_transactions', 'dataset' => 'data.transactions', 'hot' => ['order_id' => 'H1', 'payment_method' => 'Cash', 'tendered_amount' => 20], 'archive' => ['order_id' => 'A1', 'payment_method' => 'Cash', 'tendered_amount' => 15], 'measure' => 'tendered_amount'],
            ['table' => 'alta_inventory_waste', 'dataset' => 'data.alta_waste', 'hot' => ['item_id' => '1', 'unit_food_cost' => 2, 'qty' => 2], 'archive' => ['item_id' => '2', 'unit_food_cost' => 3, 'qty' => 1], 'measure' => 'waste_cost'],
        ];
        $expected = ['order_line' => 3, 'waste' => 6 + 8, 'financial_views' => 150, 'cash_management' => 3, 'detail_transactions' => 35, 'alta_inventory_waste' => 7];

        foreach ($rows as $case) {
            $base = ['franchise_store' => '03795-00044'];
            DB::connection('operational')->table(":memory:.{$case['table']}_hot")->insert([[...$base, 'business_date' => '2026-10-30', ...$case['hot']], [...$case['hot'], 'franchise_store' => '03795-00042', 'business_date' => '2026-10-30']]);
            DB::connection('operational')->table("archive_db.{$case['table']}_archive")->insert([[...$base, 'business_date' => '2025-12-01', ...$case['archive']]]);

            $dataset = ReportDatasets::make($case['dataset']);
            $fn = $dataset->toArray()['columns'];
            $this->assertContains($case['measure'], array_column($fn, 'key'));

            $this->query($case['dataset'], [
                'date_from' => '2025-11-01',
                'date_to' => '2026-10-31',
                'columns' => ['store'],
                'aggregates' => [['column' => $case['measure'], 'fn' => 'sum', 'alias' => 'total']],
            ])->assertOk()->assertJsonPath('rows', [['03795-00044', $expected[$case['table']]]]);
        }
    }

    public function test_every_warehouse_dataset_reads_both_halves_with_the_same_explicit_columns(): void
    {
        foreach (ReportDatasets::DATASETS as $class) {
            $dataset = new $class();
            if (! $dataset instanceof WarehouseDataset) {
                continue;
            }
            $sql = $dataset->baseQuery()->toRawSql();
            $this->assertStringContainsString('union all', $sql, $class);
            $this->assertStringContainsString('"archive_db".', $sql, $class);
            $this->assertStringContainsString('":memory:".', $sql, $class);
        }
    }

    public function test_the_card_digits_and_ids_nobody_needs_are_not_reachable(): void
    {
        $source = (new \App\Reports\Datasets\TransactionsDataset())->sourceColumns();
        $this->assertNotContains('card_last4', $source);
        $this->assertNotContains('card_last4', array_column($this->columnsOf('data.transactions'), 'key'));
        $this->assertNotContains('tax_exemption_id', (new \App\Reports\Datasets\OrdersDataset())->sourceColumns());
    }

    // ── App tables ───────────────────────────────────────────────────────────

    public function test_the_app_table_datasets_answer_grouped_queries(): void
    {
        $store = '03795-00044';
        DB::table('goal_metrics')->insert([['id' => 1, 'name' => 'Net sales'], ['id' => 2, 'name' => 'Orders']]);
        DB::table('goals')->insert([
            ['goal_metric_id' => 1, 'store_id' => $store, 'week_start_date' => '2026-10-21', 'week_end_date' => '2026-10-27', 'goal' => 9000],
            ['goal_metric_id' => 2, 'store_id' => $store, 'week_start_date' => '2026-10-21', 'week_end_date' => '2026-10-27', 'goal' => 600],
            ['goal_metric_id' => 1, 'store_id' => '03795-00042', 'week_start_date' => '2026-10-21', 'week_end_date' => '2026-10-27', 'goal' => 1],
        ]);
        $this->query('data.goals', ['columns' => ['metric'], 'aggregates' => [['column' => 'goal', 'fn' => 'sum', 'alias' => 'goal']]])
            ->assertOk()->assertJsonPath('rows', [['Net sales', 9000], ['Orders', 600]]);

        DB::table('go_to_calls')->insert([
            ['store_number' => $store, 'datetime' => '2026-10-21 12:30:00', 'duration' => 60, 'call_result' => 'Answered', 'from' => '555', 'status' => 'is_store'],
            ['store_number' => $store, 'datetime' => '2026-10-21 18:00:00', 'duration' => 0, 'call_result' => 'Missed', 'from' => '556', 'status' => 'is_missed'],
            ['store_number' => '03795-00042', 'datetime' => '2026-10-21 18:00:00', 'duration' => 5, 'call_result' => 'Answered', 'from' => '557', 'status' => 'is_store'],
        ]);
        DB::table('go_to_call_participants')->insert([['go_to_call_id' => 1, 'participant' => 'Ann'], ['go_to_call_id' => 1, 'participant' => 'Bob']]);
        $this->query('data.go_to_calls', ['columns' => ['date', 'caller'], 'aggregates' => [['column' => 'calls', 'fn' => 'sum', 'alias' => 'calls'], ['column' => 'participants', 'fn' => 'sum', 'alias' => 'people'], ['column' => 'missed_calls', 'fn' => 'sum', 'alias' => 'missed']]])
            ->assertOk()->assertJsonPath('rows', [['2026-10-21', '555', 1, 2, 0], ['2026-10-21', '556', 1, 0, 1]]);

        DB::table('transfer_in_outs')->insert([
            ['major_category' => 'Food', 'date' => '2026-10-22', 'to_store_number' => $store, 'from_store_number' => '03795-00042', 'base_unit_per_report_unit' => 1, 'ing_id' => 'I1', 'ing_des' => 'Cheese', 'quantity' => 10, 'unit' => 'lb', 'total_cost' => 30, 'is_posted' => true],
            ['major_category' => 'Food', 'date' => '2026-10-23', 'to_store_number' => '03795-00041', 'from_store_number' => $store, 'base_unit_per_report_unit' => 1, 'ing_id' => 'I1', 'ing_des' => 'Cheese', 'quantity' => 4, 'unit' => 'lb', 'total_cost' => 12, 'is_posted' => true],
        ]);
        $this->query('data.transfers', ['columns' => ['direction', 'other_store'], 'aggregates' => [['column' => 'total_cost', 'fn' => 'sum', 'alias' => 'cost']]])
            ->assertOk()->assertJsonPath('rows', [['In', '03795-00042', 30], ['Out', '03795-00041', 12]]);

        DB::table('cleaning_reviews')->insert([
            ['store_number' => $store, 'review_place' => 'Kitchen', 'score' => 'Pass', 'date' => '2026-10-21'],
            ['store_number' => $store, 'review_place' => 'Lobby', 'score' => 'Auto Fail', 'date' => '2026-10-21'],
        ]);
        $this->query('data.cleaning_reviews', ['columns' => ['store'], 'aggregates' => [['column' => 'passes', 'fn' => 'sum', 'alias' => '_d_passes'], ['column' => 'reviews', 'fn' => 'sum', 'alias' => '_d_reviews'], ['column' => 'fails', 'fn' => 'sum', 'alias' => 'fails']]])
            ->assertOk()->assertJsonPath('rows', [[$store, 1, 2, 1]]);

        DB::table('inventory_orders')->insert([[
            'delivery_date' => '2026-10-22', 'invoice_no' => 'V1', 'store_number' => $store, 'vendor_number' => '9', 'vendor_name' => 'Acme', 'asn_total' => 100, 'invoice_total' => 105,
            'is_manual_asn' => false, 'is_asn_received' => true, 'is_ordered' => true, 'order_status' => 'Delivered', 'is_posted' => true,
        ]]);
        $this->query('data.inventory_orders', ['columns' => ['vendor_name'], 'aggregates' => [['column' => 'invoice_total', 'fn' => 'sum', 'alias' => 'total']]])
            ->assertOk()->assertJsonPath('rows', [['Acme', 105]]);

        DB::table('non_negotiable_reports')->insert([
            ['store_number' => $store, 'action' => 'Downtime', 'date' => '2026-10-21', 'time' => '10:00:00', 'notes' => 'Oven'],
            ['store_number' => $store, 'action' => 'Other', 'date' => null, 'time' => null, 'notes' => null],
        ]);
        $this->query('data.non_negotiables', ['columns' => ['action'], 'aggregates' => [['column' => 'count', 'fn' => 'count', 'alias' => 'incidents']]])
            ->assertOk()->assertJsonPath('rows', [['Downtime', 1]]);

        DB::table('customer_services')->insert([['store_number' => $store, 'date' => '2026-10-21', 'guest_service' => 4.5, 'lobby_in' => '10:00:00', 'lobby_out' => '10:03:00']]);
        $this->query('data.customer_service', ['columns' => ['date'], 'aggregates' => [['column' => 'guest_service', 'fn' => 'avg', 'alias' => 'score']]])
            ->assertOk()->assertJsonPath('rows', [['2026-10-21', 4.5]]);

        DB::table('hnr_plus_items')->insert([['store_number' => $store, 'week_start' => '2026-10-21', 'week_end' => '2026-10-27', 'item_id' => '7', 'item_name' => 'Wedge', 'made' => 10, 'sold' => 8, 'voided' => 0, 'wasted' => 2, 'variance' => 0, 'no_inventory_available' => 1]]);
        $this->query('data.hnr_plus', ['columns' => ['item_name'], 'aggregates' => [['column' => 'sold', 'fn' => 'sum', 'alias' => 'sold']]])
            ->assertOk()->assertJsonPath('rows', [['Wedge', 8]]);
    }

    // ── Every dataset, every column ──────────────────────────────────────────

    public static function datasets(): array
    {
        // The debriefs and key values tables belong to other tests.
        $skipped = [\App\Reports\Datasets\EmployeeDebriefsDataset::class, \App\Reports\Datasets\KeyValuesDataset::class];

        return array_map(fn (string $class) => [$class], array_values(array_diff(ReportDatasets::DATASETS, $skipped)));
    }

    #[DataProvider('datasets')]
    public function test_every_column_of_every_dataset_compiles_against_the_migrations_tables(string $class): void
    {
        $dataset = new $class();
        $columns = $dataset->toArray()['columns'];

        $aggregates = [];
        foreach ($columns as $column) {
            foreach ($column['aggregations'] as $fn) {
                $aggregates[] = ['column' => $column['key'], 'fn' => $fn, 'alias' => "{$column['key']}_{$fn}"];
            }
        }
        $result = app(DatasetQueryCompiler::class)->run($dataset, [
            'stores' => ['03795-00044'],
            'date_from' => '2020-01-01',
            'date_to' => '2030-12-31',
            'columns' => array_column(array_filter($columns, fn (array $c) => $c['groupable']), 'key'),
            'aggregates' => $aggregates,
            'filters' => array_map(fn (array $c) => ['column' => $c['key'], 'op' => 'not_null'], array_values(array_filter($columns, fn (array $c) => $c['filterable']))),
            'sort' => array_map(fn (array $c) => ['column' => $c['key']], array_values(array_filter($columns, fn (array $c) => $c['sortable'] && $c['groupable']))),
        ]);

        $this->assertSame([], $result['rows']);
        $this->assertGreaterThan(2, count($result['columns']));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function columnsOf(string $key): array
    {
        return ReportDatasets::make($key)->toArray()['columns'];
    }

    private function query(string $key, array $overrides)
    {
        return $this->postJson("/api/report-datasets/{$key}/query", array_replace([
            'stores' => ['03795-00044'],
            'date_from' => '2026-10-21',
            'date_to' => '2026-10-27',
            'limit' => 100,
        ], $overrides), $this->headers());
    }

    private function migrations(string $folder, string $pattern): array
    {
        return glob(base_path("database/migrations/{$folder}/{$pattern}")) ?: [];
    }

    /**
     * CREATE TABLE from the column names a table's migration files declare. Every
     * column is nullable and untyped beyond sqlite's affinity, which is all a
     * dataset's SQL needs.
     */
    private function createFromMigrations(?string $connection, ?string $schema, array $files): void
    {
        $this->assertNotSame([], $files);

        $table = null;
        $columns = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            preg_match('/(?:->|::)(?:create|table)\(\'(\w+)\'/', $source, $name);
            $table ??= $name[1];

            if (str_contains($source, '$table->id()')) {
                $columns['id'] = 'INTEGER';
            }
            preg_match_all('/\$table->(\w+)\(\'(\w+)\'/', $source, $found, PREG_SET_ORDER);
            foreach ($found as [, $method, $column]) {
                if (in_array($method, ['index', 'unique', 'primary', 'foreign', 'fullText', 'dropColumn', 'dropIndex'], true)) {
                    continue;
                }
                $columns[$column] = match ($method) {
                    'decimal', 'float', 'double' => 'NUMERIC',
                    'integer', 'unsignedInteger', 'unsignedTinyInteger', 'unsignedBigInteger', 'bigInteger', 'tinyInteger', 'boolean', 'foreignId' => 'INTEGER',
                    default => 'TEXT',
                };
            }
            // The generated flag columns of order_line.
            preg_match_all('/ADD COLUMN (\w+) BOOLEAN/', $source, $generated);
            foreach ($generated[1] as $column) {
                $columns[$column] = 'INTEGER';
            }
        }

        $qualified = ($schema === null ? '' : "\"{$schema}\".").'"'.$table.'"';
        $columns['id'] = 'INTEGER PRIMARY KEY';
        $definitions = implode(', ', array_map(fn (string $column, string $type) => "\"{$column}\" {$type}", array_keys($columns), $columns));
        DB::connection($connection)->statement("CREATE TABLE {$qualified} ({$definitions})");
    }
}
