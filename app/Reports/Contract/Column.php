<?php
// Copied from LC_PIZZA_DATA/app/Reports/Contract/Column.php — contract v1. Edit there, copy everywhere.

declare(strict_types=1);

namespace App\Reports\Contract;

/**
 * One column a dataset offers. `expr` is the SQL behind it and comes only
 * from code, never from a request; it is not published in the catalog.
 *
 * A `derived` column (e.g. `net_sales / total_orders`) has no SQL: the engine
 * computes it after the merge from the dataset's other columns, each summed
 * with its first aggregation. It is never filtered, grouped or aggregated here.
 */
final class Column
{
    public const TYPES = ['string', 'integer', 'number', 'money', 'date', 'datetime', 'boolean', 'enum'];

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
        public readonly ?string $expr = null,
        public readonly array $options = [],
        public readonly bool $filterable = true,
        public readonly bool $sortable = true,
        public readonly bool $groupable = false,
        public readonly bool $measure = false,
        public readonly array $aggregations = [],
        public readonly ?string $derived = null,
    ) {
    }

    /** Type of `fn` applied to a column of type `type`. */
    public static function aggregateType(string $fn, string $type): string
    {
        return match ($fn) {
            'count', 'count_distinct' => 'integer',
            'avg' => $type === 'money' ? 'money' : 'number',
            default => $type,
        };
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type,
            'options' => $this->options,
            'filterable' => $this->derived === null && $this->filterable,
            'sortable' => $this->sortable,
            'groupable' => $this->derived === null && $this->groupable,
            'measure' => $this->measure,
            'aggregations' => $this->derived === null ? $this->aggregations : [],
            'derived' => $this->derived,
        ];
    }
}
