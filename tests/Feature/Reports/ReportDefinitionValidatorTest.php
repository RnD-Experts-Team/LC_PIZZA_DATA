<?php

namespace Tests\Feature\Reports;

use App\Reports\Datasets\DailySalesDataset;
use App\Reports\Engine\ReportDefinitionValidator;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Needs no database, so it skips Tests\TestCase and its MySQL connections.
 */
class ReportDefinitionValidatorTest extends TestCase
{
    private function datasets(): array
    {
        return [
            'data.daily_sales' => [...(new DailySalesDataset())->toArray(), 'service' => 'data'],
            'hiring.employees' => [
                'key' => 'hiring.employees', 'label' => 'Employees', 'service' => 'hiring',
                'path' => '/report-datasets/hiring.employees/query',
                'grain' => ['store', 'employee'], 'heavy' => false, 'dated' => true,
                'columns' => [
                    ['key' => 'store', 'label' => 'Store', 'type' => 'string', 'filterable' => true, 'groupable' => true, 'aggregations' => [], 'derived' => null],
                    ['key' => 'employee', 'label' => 'Employee', 'type' => 'integer', 'filterable' => true, 'groupable' => true, 'aggregations' => ['count_distinct'], 'derived' => null],
                    ['key' => 'position', 'label' => 'Position', 'type' => 'string', 'filterable' => true, 'groupable' => true, 'aggregations' => [], 'derived' => null],
                ],
            ],
            // A snapshot: current stock by store and part, no date.
            'inventory.stock' => [
                'key' => 'inventory.stock', 'label' => 'Stock on hand', 'service' => 'inventory',
                'grain' => ['store'], 'heavy' => false, 'dated' => false,
                'columns' => [
                    ['key' => 'store', 'label' => 'Store', 'type' => 'string', 'filterable' => true, 'groupable' => true, 'aggregations' => [], 'derived' => null],
                    ['key' => 'part', 'label' => 'Part', 'type' => 'string', 'filterable' => true, 'groupable' => true, 'aggregations' => [], 'derived' => null],
                    ['key' => 'qty', 'label' => 'Quantity', 'type' => 'number', 'filterable' => true, 'groupable' => false, 'aggregations' => ['sum'], 'derived' => null],
                ],
            ],
        ];
    }

    private function definition(array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            'datasets' => [['alias' => 's', 'key' => 'data.daily_sales']],
            'grain' => ['store', 'date'],
            'columns' => [
                ['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum', 'label' => 'Net sales'],
                ['id' => 'c2', 'alias' => 's', 'column' => 'total_orders', 'agg' => 'sum'],
                ['id' => 'c4', 'kind' => 'formula', 'expr' => 'round(c3, 1)', 'type' => 'number'],
                ['id' => 'c3', 'kind' => 'formula', 'expr' => 'if(c2 > 0, c1 / c2, null)', 'type' => 'money'],
                ['id' => 'c5', 'alias' => 's', 'column' => 'avg_order_value'],
            ],
            'filters' => [['alias' => 's', 'column' => 'net_sales', 'op' => 'gt', 'value' => 0]],
            'sort' => [['id' => 'c1', 'dir' => 'desc']],
            'period' => ['type' => 'relative', 'token' => 'last_week'],
            'stores' => ['mode' => 'mine'],
            'limit' => 1000,
        ], $overrides);
    }

    private function errors(array $definition): array
    {
        try {
            (new ReportDefinitionValidator())->validate($definition, $this->datasets());
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('The definition was accepted.');
    }

    public function test_a_valid_definition_gives_its_formulas_in_dependency_order(): void
    {
        $formulas = (new ReportDefinitionValidator())->validate($this->definition(), $this->datasets());

        $this->assertSame(['c3', 'c4'], array_keys($formulas));
    }

    public function test_an_unknown_column_is_rejected(): void
    {
        $definition = $this->definition();
        $definition['columns'][0]['column'] = 'secret_margin';

        $this->assertSame(['c1: Daily sales has no column secret_margin.'], $this->errors($definition)['columns.0.column']);
    }

    public function test_an_aggregation_the_column_does_not_offer_is_rejected(): void
    {
        $definition = $this->definition();
        $definition['columns'][0]['agg'] = 'count_distinct';

        $this->assertSame(['c1: net_sales offers sum, avg, min, max.'], $this->errors($definition)['columns.0.column']);
    }

    public function test_a_grain_a_dataset_does_not_have_is_rejected(): void
    {
        $errors = $this->errors($this->definition([
            'datasets' => [['alias' => 's', 'key' => 'data.daily_sales'], ['alias' => 'e', 'key' => 'hiring.employees']],
            'grain' => ['store', 'employee'],
        ]));

        $this->assertSame(['Daily sales cannot be reported by employee.'], $errors['datasets.0.key']);
    }

    public function test_a_report_without_a_dataset_is_rejected(): void
    {
        $this->assertSame(['A report uses at least one dataset.'], $this->errors($this->definition(['datasets' => []]))['datasets']);
    }

    public function test_there_is_no_cap_on_datasets_columns_formulas_days_stores_or_list_values(): void
    {
        $datasets = array_map(fn (int $i) => ['alias' => "s{$i}", 'key' => 'data.daily_sales'], range(1, 6));
        $columns = [];
        foreach (range(1, 60) as $i) {
            $columns[] = ['id' => "c{$i}", 'alias' => 's'.(($i - 1) % 6 + 1), 'column' => 'net_sales', 'agg' => 'sum'];
        }
        foreach (range(61, 90) as $i) {
            $columns[] = ['id' => "c{$i}", 'kind' => 'formula', 'expr' => 'c'.($i - 1).' + 1'];
        }
        $codes = array_map(fn (int $i) => sprintf('03795-%05d', $i), range(1, 1200));

        $formulas = (new ReportDefinitionValidator())->validate($this->definition([
            'datasets' => $datasets,
            'columns' => $columns,
            'filters' => [['id' => 'c1', 'op' => 'in', 'value' => range(1, 2000)]],
            'sort' => array_map(fn (int $i) => ['id' => "c{$i}"], range(1, 30)),
            // Three years.
            'period' => ['type' => 'fixed', 'from' => '2023-11-01', 'to' => '2026-10-31'],
            'stores' => ['mode' => 'list', 'codes' => $codes],
            'limit' => null,
        ]), $this->datasets());

        $this->assertSame(array_map(fn (int $i) => "c{$i}", range(61, 90)), array_keys($formulas));
    }

    public function test_a_formula_reading_a_missing_column_names_its_expression(): void
    {
        $definition = $this->definition();
        $definition['columns'][2]['expr'] = 'round(c9 / 60, 1)';

        $this->assertSame(['c4 refers to unknown column c9 in: round(c9 / 60, 1)'], $this->errors($definition)['columns.2.expr']);
    }

    public function test_formula_cycles_are_rejected(): void
    {
        $definition = $this->definition();
        $definition['columns'][3]['expr'] = 'c4 * 2';

        $this->assertStringContainsString('cycle: c4 → c3 → c4', $this->errors($definition)['columns'][0]);

        $definition = $this->definition();
        $definition['columns'][2]['expr'] = 'c4 + 1';

        $this->assertStringContainsString('cycle: c4 → c4 (in: c4 + 1)', $this->errors($definition)['columns'][0]);
    }

    public function test_a_formula_that_does_not_parse_is_rejected_with_its_text(): void
    {
        $definition = $this->definition();
        $definition['columns'][2]['expr'] = 'round(c3,';

        $this->assertSame(['c4: Unexpected end of formula at position 10. In: round(c3,'], $this->errors($definition)['columns.2.expr']);
    }

    public function test_a_derived_column_takes_no_aggregation(): void
    {
        $definition = $this->definition();
        $definition['columns'][4]['agg'] = 'sum';

        $this->assertSame(['c5: avg_order_value is computed and takes no aggregation.'], $this->errors($definition)['columns.4.column']);
    }

    public function test_a_report_without_a_column_is_rejected_and_a_column_id_has_any_number(): void
    {
        $this->assertSame(['A report has at least one column.'], $this->errors($this->definition(['columns' => [], 'sort' => [], 'filters' => []]))['columns']);

        $formulas = (new ReportDefinitionValidator())->validate($this->definition([
            'columns' => [['id' => 'c12345', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum']],
            'sort' => [['id' => 'c12345']],
            'filters' => [],
        ]), $this->datasets());
        $this->assertSame([], $formulas);
    }

    public function test_a_limit_is_optional_and_otherwise_at_least_one(): void
    {
        $definition = $this->definition();
        unset($definition['limit']);
        (new ReportDefinitionValidator())->validate($definition, $this->datasets());
        (new ReportDefinitionValidator())->validate($this->definition(['limit' => 5000000]), $this->datasets());

        $this->assertSame(['The row limit is a whole number of at least 1.'], $this->errors($this->definition(['limit' => 0]))['limit']);
    }

    public function test_a_snapshot_dataset_cannot_be_reported_by_date(): void
    {
        $errors = $this->errors($this->definition([
            'datasets' => [['alias' => 'i', 'key' => 'inventory.stock']],
            'grain' => ['store', 'date'],
            'columns' => [['id' => 'c1', 'alias' => 'i', 'column' => 'qty', 'agg' => 'sum']],
            'filters' => [],
            'sort' => [],
        ]));

        $this->assertSame(['Stock on hand is a snapshot of the current state and has no date; take date out of the grain.'], $errors['datasets.0.key']);
    }

    public function test_an_empty_grain_and_a_snapshot_dataset_by_store_are_valid(): void
    {
        foreach ([[], ['store']] as $grain) {
            $formulas = (new ReportDefinitionValidator())->validate($this->definition([
                'datasets' => [['alias' => 'i', 'key' => 'inventory.stock'], ['alias' => 's', 'key' => 'data.daily_sales']],
                'grain' => $grain,
                'columns' => [
                    ['id' => 'c1', 'alias' => 'i', 'column' => 'qty', 'agg' => 'sum'],
                    ['id' => 'c2', 'alias' => 'i', 'column' => 'part'],
                    ['id' => 'c3', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum'],
                ],
                'filters' => [],
                'sort' => [],
            ]), $this->datasets());

            $this->assertSame([], $formulas);
        }
    }

    public function test_only_one_dataset_may_add_breakdown_columns(): void
    {
        $definition = fn (array $columns) => $this->definition([
            'datasets' => [['alias' => 'i', 'key' => 'inventory.stock'], ['alias' => 'e', 'key' => 'hiring.employees']],
            'grain' => ['store'],
            'columns' => $columns,
            'filters' => [],
            'sort' => [],
        ]);

        // A plain column in the grain is no breakdown; one breakdown dataset is fine.
        (new ReportDefinitionValidator())->validate($definition([
            ['id' => 'c1', 'alias' => 'i', 'column' => 'part'],
            ['id' => 'c2', 'alias' => 'e', 'column' => 'store'],
            ['id' => 'c3', 'alias' => 'e', 'column' => 'employee', 'agg' => 'count_distinct'],
        ]), $this->datasets());

        $errors = $this->errors($definition([
            ['id' => 'c1', 'alias' => 'i', 'column' => 'part'],
            ['id' => 'c2', 'alias' => 'e', 'column' => 'position'],
        ]));
        $this->assertSame(["Only one dataset can add breakdown columns; the others must match the report's grain."], $errors['columns']);

        // A column that cannot be grouped needs its aggregation.
        $this->assertSame(['c1: qty offers sum.'], $this->errors($definition([['id' => 'c1', 'alias' => 'i', 'column' => 'qty']]))['columns.0.column']);
    }

    public static function scalarFilters(): array
    {
        $dataset = ['alias' => 's', 'column' => 'net_sales'];

        return [
            'eq with a list, on a report column' => [['id' => 'c1', 'op' => 'eq', 'value' => [1, 2]]],
            'eq with a list, on a dataset column' => [[...$dataset, 'op' => 'eq', 'value' => [1]]],
            'eq with an object' => [['id' => 'c1', 'op' => 'eq', 'value' => ['a' => 1]]],
            'eq without a value' => [['id' => 'c1', 'op' => 'eq']],
            'gt with a list' => [['id' => 'c1', 'op' => 'gt', 'value' => [1]]],
            'lte with a list' => [['id' => 'c1', 'op' => 'lte', 'value' => [1]]],
            'neq with a list' => [['id' => 'c1', 'op' => 'neq', 'value' => [1]]],
            'contains with a list' => [['id' => 'c1', 'op' => 'contains', 'value' => ['a']]],
        ];
    }

    #[DataProvider('scalarFilters')]
    public function test_a_filter_that_takes_one_value_rejects_a_list(array $filter): void
    {
        $this->assertSame(['Send one value.'], $this->errors($this->definition(['filters' => [$filter]]))['filters.0']);
    }

    public function test_the_list_filters_take_lists_of_scalars_and_the_null_checks_take_nothing(): void
    {
        $formulas = (new ReportDefinitionValidator())->validate($this->definition(['filters' => [
            ['id' => 'c1', 'op' => 'in', 'value' => [1, 2]],
            ['id' => 'c1', 'op' => 'not_in', 'value' => ['a']],
            ['id' => 'c1', 'op' => 'between', 'value' => [1, 5]],
            ['id' => 'c1', 'op' => 'is_null'],
            ['id' => 'c1', 'op' => 'not_null', 'value' => [1]],
            ['id' => 'c1', 'op' => 'contains', 'value' => 'a'],
            ['id' => 'c1', 'op' => 'eq', 'value' => 0],
        ]]), $this->datasets());

        $this->assertSame(['c3', 'c4'], array_keys($formulas));

        $this->assertSame(['Send a list of one or more values.'], $this->errors($this->definition(['filters' => [['id' => 'c1', 'op' => 'in', 'value' => [[1]]]]]))['filters.0']);
        $this->assertSame(['Send a list of one or more values.'], $this->errors($this->definition(['filters' => [['id' => 'c1', 'op' => 'not_in', 'value' => 'a']]]))['filters.0']);
        $this->assertSame(['Send exactly two values.'], $this->errors($this->definition(['filters' => [['id' => 'c1', 'op' => 'between', 'value' => ['lo' => 1, 'hi' => 2]]]]))['filters.0']);
        $this->assertSame(['Send exactly two values.'], $this->errors($this->definition(['filters' => [['id' => 'c1', 'op' => 'between', 'value' => 5]]]))['filters.0']);
    }

    public function test_a_period_that_ends_before_it_starts_is_rejected(): void
    {
        $errors = $this->errors($this->definition(['period' => ['type' => 'fixed', 'from' => '2026-06-30', 'to' => '2025-01-01']]));

        $this->assertSame(['The period starts after it ends.'], $errors['period']);
    }
}
