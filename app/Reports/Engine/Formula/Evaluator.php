<?php

declare(strict_types=1);

namespace App\Reports\Engine\Formula;

/**
 * Evaluates a Parser AST against one row (column id => value). Mirror of the
 * dashboard's formula.ts, which gives the builder its live feedback. Null-safe:
 * arithmetic with a null or non-numeric operand, and division by zero, give
 * null; ordering comparisons with null give null; `=` and `!=` treat null as
 * a value (`c1 = null` is how an empty cell is tested); and/or/not are
 * three-valued (null in, null out unless the other side decides). An empty
 * cell is null. min/max/sum/avg skip nulls, coalesce returns the first
 * non-null. A result that is not a finite number is null, and so is a text
 * longer than MAX_TEXT characters.
 */
final class Evaluator
{
    /** Longest text a formula produces, the most an XLSX cell holds; a longer one is null. */
    public const MAX_TEXT = 32767;

    /** Digits round() works to, either side of the decimal point. */
    private const MAX_DIGITS = 12;

    public function evaluate(array $ast, array $row): mixed
    {
        $value = $this->node($ast, $row);

        return is_float($value) && ! is_finite($value) ? null : $value;
    }

    private function node(array $ast, array $row): mixed
    {
        return match ($ast[0]) {
            'num', 'str' => $ast[1],
            'null' => null,
            'ref' => $this->cell($row[$ast[1]] ?? null),
            'neg' => ($value = $this->number($this->node($ast[1], $row))) === null ? null : -$value,
            'pos' => $this->number($this->node($ast[1], $row)),
            'not' => ($value = $this->truthy($this->node($ast[1], $row))) === null ? null : ! $value,
            'bin' => $this->binary($ast[1], $ast[2], $ast[3], $row),
            'call' => $this->call($ast[1], $ast[2], $row),
        };
    }

    /** A cell of the row: empty text and values that are not numbers, text or booleans are null. */
    private function cell(mixed $value): mixed
    {
        return match (true) {
            is_int($value), is_bool($value) => $value,
            is_float($value) => is_finite($value) ? $value : null,
            is_string($value) => $value === '' ? null : $value,
            default => null,
        };
    }

    private function truthy(mixed $value): ?bool
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value,
            is_int($value), is_float($value) => $value != 0,
            default => (string) $value !== '',
        };
    }

    private function binary(string $op, array $left, array $right, array $row): mixed
    {
        if ($op === 'and' || $op === 'or') {
            return $this->logical($op, $this->truthy($this->node($left, $row)), fn () => $this->truthy($this->node($right, $row)));
        }

        $a = $this->node($left, $row);
        $b = $this->node($right, $row);

        if ($op === '=' || $op === '!=') {
            return ($op === '=') === $this->equal($a, $b);
        }
        if (in_array($op, ['<', '<=', '>', '>='], true)) {
            if ($a === null || $b === null) {
                return null;
            }
            $cmp = $this->compare($a, $b);

            return match ($op) {
                '<' => $cmp < 0,
                '<=' => $cmp <= 0,
                '>' => $cmp > 0,
                '>=' => $cmp >= 0,
            };
        }

        $x = $this->number($a);
        $y = $this->number($b);
        if ($x === null || $y === null || (($op === '/' || $op === '%') && $y == 0)) {
            return null;
        }

        return match ($op) {
            '+' => $x + $y,
            '-' => $x - $y,
            '*' => $x * $y,
            '/' => $x / $y,
            '%' => is_int($x) && is_int($y) ? $x % $y : fmod($x, $y),
        };
    }

    /** A decisive side (true for or, false for and) wins over null; null wins over the undecisive one. */
    private function logical(string $op, ?bool $left, callable $right): ?bool
    {
        $decisive = $op === 'or';
        if ($left === $decisive) {
            return $decisive;
        }
        $other = $right();
        if ($other === $decisive) {
            return $decisive;
        }

        return $left === null || $other === null ? null : ! $decisive;
    }

    private function call(string $fn, array $args, array $row): mixed
    {
        if ($fn === 'if') {
            return $this->node($args[$this->truthy($this->node($args[0], $row)) === true ? 1 : 2], $row);
        }
        if ($fn === 'coalesce') {
            foreach ($args as $arg) {
                if (($value = $this->node($arg, $row)) !== null) {
                    return $value;
                }
            }

            return null;
        }

        $values = array_map(fn (array $arg) => $this->node($arg, $row), $args);

        if ($fn === 'concat') {
            return $this->bounded(implode('', array_map(fn ($value) => $value === null ? '' : $this->text($value), $values)));
        }
        if (in_array($fn, ['len', 'upper', 'lower'], true)) {
            if ($values[0] === null) {
                return null;
            }
            $text = $this->text($values[0]);

            return match ($fn) {
                'len' => mb_strlen($text),
                'upper' => $this->bounded(mb_strtoupper($text)),
                'lower' => $this->bounded(mb_strtolower($text)),
            };
        }

        $numbers = array_map($this->number(...), $values);
        $present = array_values(array_filter($numbers, fn ($n) => $n !== null));

        return match ($fn) {
            'round' => $this->round($numbers[0], count($numbers) > 1 ? $numbers[1] : 0),
            'abs' => $numbers[0] === null ? null : abs($numbers[0]),
            'percent' => $numbers[0] === null || $numbers[1] === null || $numbers[1] == 0
                ? null : $numbers[0] / $numbers[1] * 100,
            'min' => $present === [] ? null : min($present),
            'max' => $present === [] ? null : max($present),
            'sum' => $present === [] ? null : array_sum($present),
            'avg' => $present === [] ? null : array_sum($present) / count($present),
        };
    }

    /** Half away from zero, to a whole number of digits (negative = tens, hundreds). */
    private function round(int|float|null $value, int|float|null $digits): ?float
    {
        if ($value === null || $digits === null) {
            return null;
        }

        $rounded = round((float) $value, max(-self::MAX_DIGITS, min(self::MAX_DIGITS, (int) $digits)), PHP_ROUND_HALF_UP);

        return $rounded == 0 ? 0.0 : $rounded;
    }

    private function bounded(string $text): ?string
    {
        return mb_strlen($text) > self::MAX_TEXT ? null : $text;
    }

    /** Numbers, booleans and numeric text (CSV cells, money strings) are numbers; anything else is null. Also sorts report rows. */
    public function number(mixed $value): int|float|null
    {
        if (is_string($value)) {
            $text = trim($value);
            $value = preg_match('/^[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?$/', $text) ? $text + 0 : null;
        }

        return match (true) {
            is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : null,
            is_bool($value) => (int) $value,
            default => null,
        };
    }

    private function equal(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $this->compare($a, $b) === 0;
    }

    /** Numbers as numbers, anything else as text; also orders and filters report rows. */
    public function compare(mixed $a, mixed $b): int
    {
        $x = $this->number($a);
        $y = $this->number($b);

        return $x !== null && $y !== null ? $x <=> $y : strcmp($this->text($a), $this->text($b)) <=> 0;
    }

    private function text(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
