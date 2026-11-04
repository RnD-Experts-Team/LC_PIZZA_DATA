<?php

declare(strict_types=1);

namespace App\Reports\Datasets\Support;

use App\Reports\Contract\Column;

/**
 * Short constructors for the report columns the Data datasets declare, so each
 * dataset reads as a list of labelled columns. Dimensions are groupable;
 * measures carry the aggregations that make sense for them.
 */
final class Cols
{
    /** Totals, averages and extremes: counts and amounts. */
    public const SUMS = ['sum', 'avg', 'min', 'max'];

    /** Unit prices and rates: adding them up means nothing. */
    public const SPREAD = ['avg', 'min', 'max'];

    private const YES_NO = [['value' => 'Yes', 'label' => 'Yes'], ['value' => 'No', 'label' => 'No']];

    public static function store(string $expr = 'franchise_store'): Column
    {
        return new Column('store', 'Store', 'string', $expr, groupable: true);
    }

    public static function date(string $expr = 'business_date', string $label = 'Business date'): Column
    {
        return new Column('date', $label, 'date', $expr, groupable: true);
    }

    public static function text(string $key, string $label, ?string $expr = null): Column
    {
        return new Column($key, $label, 'string', $expr, groupable: true);
    }

    /** A column with a fixed set of values. */
    public static function pick(string $key, string $label, array $values, ?string $expr = null): Column
    {
        return new Column(
            $key,
            $label,
            'enum',
            $expr,
            options: array_map(fn (string $value) => ['value' => $value, 'label' => $value], $values),
            groupable: true,
        );
    }

    /** A Yes/No flag as the POS exports it. */
    public static function yesNo(string $key, string $label, ?string $expr = null): Column
    {
        return new Column($key, $label, 'enum', $expr, options: self::YES_NO, groupable: true);
    }

    public static function flag(string $key, string $label, ?string $expr = null): Column
    {
        return new Column($key, $label, 'boolean', $expr, groupable: true);
    }

    public static function day(string $key, string $label, ?string $expr = null): Column
    {
        return new Column($key, $label, 'date', $expr, groupable: true);
    }

    public static function stamp(string $key, string $label, ?string $expr = null): Column
    {
        return new Column($key, $label, 'datetime', $expr, groupable: true);
    }

    /** A whole number you slice by (an hour of the day). */
    public static function level(string $key, string $label, ?string $expr = null): Column
    {
        return new Column($key, $label, 'integer', $expr, groupable: true);
    }

    public static function whole(string $key, string $label, ?string $expr = null, array $aggregations = self::SUMS): Column
    {
        return new Column($key, $label, 'integer', $expr, measure: true, aggregations: $aggregations);
    }

    public static function money(string $key, string $label, ?string $expr = null, array $aggregations = self::SUMS): Column
    {
        return new Column($key, $label, 'money', $expr, measure: true, aggregations: $aggregations);
    }

    public static function amount(string $key, string $label, ?string $expr = null, array $aggregations = self::SUMS): Column
    {
        return new Column($key, $label, 'number', $expr, measure: true, aggregations: $aggregations);
    }

    /** How many different values: orders in a table of order lines. */
    public static function distinct(string $key, string $label, string $expr): Column
    {
        return new Column($key, $label, 'integer', $expr, filterable: false, measure: true, aggregations: ['count_distinct']);
    }

    /** The number of rows of the table, counted through its id. */
    public static function rows(string $label, string $expr): Column
    {
        return new Column('count', $label, 'integer', $expr, filterable: false, measure: true, aggregations: ['count']);
    }

    /** Computed by the report engine from other columns, each summed; never added up itself. */
    public static function ratio(string $key, string $label, string $type, string $formula): Column
    {
        return new Column($key, $label, $type, measure: true, derived: $formula);
    }
}
