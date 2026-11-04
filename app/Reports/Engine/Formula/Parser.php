<?php

declare(strict_types=1);

namespace App\Reports\Engine\Formula;

use InvalidArgumentException;

/**
 * Recursive descent, lowest precedence first:
 *   or → and → not → comparison (= != < <= > >=) → + - → * / % → unary - + → primary
 * primary: number, 'text', null, column id, fn(args), ( expr ).
 *
 * Keywords (and, or, not, null) and function names are case-insensitive,
 * column ids are not. Depth counts nested brackets and function calls together;
 * MAX_DEPTH keeps the recursion well within PHP's stack, however long the formula.
 *
 * The AST is plain arrays: ['num', v] ['str', v] ['null'] ['ref', id]
 * ['neg', x] ['pos', x] ['not', x] ['bin', op, left, right] ['call', fn, [args]].
 */
final class Parser
{
    public const MAX_DEPTH = 64;

    /** name => [min args, max args or null] */
    public const FUNCTIONS = [
        'round' => [1, 2], 'abs' => [1, 1], 'min' => [1, null], 'max' => [1, null],
        'sum' => [1, null], 'avg' => [1, null], 'coalesce' => [1, null], 'if' => [3, 3],
        'concat' => [1, null], 'len' => [1, 1], 'upper' => [1, 1], 'lower' => [1, 1],
        'percent' => [2, 2],
    ];

    private const COMPARISONS = ['=', '!=', '<', '<=', '>', '>='];

    private array $tokens = [];

    private int $at = 0;

    private int $depth = 0;

    public function parse(string $source): array
    {
        $this->tokens = (new Tokenizer())->tokenize($source);
        $this->at = 0;
        $this->depth = 0;

        if ($this->peek()['type'] === 'end') {
            throw new InvalidArgumentException('The formula is empty.');
        }

        $ast = $this->or();
        if ($this->peek()['type'] !== 'end') {
            $this->fail($this->peek());
        }

        return $ast;
    }

    /** Column ids the AST reads, each once. */
    public static function references(array $ast): array
    {
        return match ($ast[0]) {
            'ref' => [$ast[1]],
            'neg', 'pos', 'not' => self::references($ast[1]),
            'bin' => array_values(array_unique([...self::references($ast[2]), ...self::references($ast[3])])),
            'call' => array_values(array_unique(array_merge([], ...array_map(self::references(...), $ast[2])))),
            default => [],
        };
    }

    private function or(): array
    {
        $left = $this->and();
        while ($this->word('or')) {
            $left = ['bin', 'or', $left, $this->and()];
        }

        return $left;
    }

    private function and(): array
    {
        $left = $this->not();
        while ($this->word('and')) {
            $left = ['bin', 'and', $left, $this->not()];
        }

        return $left;
    }

    private function not(): array
    {
        return $this->word('not') ? ['not', $this->not()] : $this->comparison();
    }

    private function comparison(): array
    {
        $left = $this->additive();
        $token = $this->peek();
        if ($token['type'] === 'op' && in_array($token['value'], self::COMPARISONS, true)) {
            $this->at++;

            return ['bin', $token['value'], $left, $this->additive()];
        }

        return $left;
    }

    private function additive(): array
    {
        $left = $this->multiplicative();
        while (in_array($this->operator(), ['+', '-'], true)) {
            $left = ['bin', $this->tokens[$this->at++]['value'], $left, $this->multiplicative()];
        }

        return $left;
    }

    private function multiplicative(): array
    {
        $left = $this->unary();
        while (in_array($this->operator(), ['*', '/', '%'], true)) {
            $left = ['bin', $this->tokens[$this->at++]['value'], $left, $this->unary()];
        }

        return $left;
    }

    private function unary(): array
    {
        if (in_array($this->operator(), ['-', '+'], true)) {
            $sign = $this->tokens[$this->at++]['value'];

            return [$sign === '-' ? 'neg' : 'pos', $this->unary()];
        }

        return $this->primary();
    }

    private function primary(): array
    {
        $token = $this->tokens[$this->at++];

        if ($token['type'] === 'num') {
            return ['num', $token['value']];
        }
        if ($token['type'] === 'str') {
            return ['str', $token['value']];
        }
        if ($token['type'] === 'op' && $token['value'] === '(') {
            $this->enter($token);
            $inner = $this->or();
            $this->expect(')');
            $this->depth--;

            return $inner;
        }
        if ($token['type'] !== 'id' || in_array(strtolower($token['value']), ['and', 'or', 'not'], true)) {
            $this->fail($token);
        }

        $name = strtolower($token['value']);
        if ($name === 'null') {
            return ['null'];
        }
        if ($this->operator() !== '(') {
            return ['ref', $token['value']];
        }

        if (! isset(self::FUNCTIONS[$name])) {
            throw new InvalidArgumentException("Unknown function {$token['value']} at position ".($token['pos'] + 1).'.');
        }
        $this->at++;
        $this->enter($token);
        $args = [];
        if ($this->operator() !== ')') {
            $args[] = $this->or();
            while ($this->operator() === ',') {
                $this->at++;
                $args[] = $this->or();
            }
        }
        $this->expect(')');
        $this->depth--;

        [$min, $max] = self::FUNCTIONS[$name];
        if (count($args) < $min || ($max !== null && count($args) > $max)) {
            $wanted = $max === null ? "at least {$min}" : ($min === $max ? (string) $min : "{$min} to {$max}");
            throw new InvalidArgumentException("{$name}() takes {$wanted} argument(s), at position ".($token['pos'] + 1).'.');
        }

        return ['call', $name, $args];
    }

    private function enter(array $token): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Nesting deeper than '.self::MAX_DEPTH.' levels at position '.($token['pos'] + 1).'.');
        }
    }

    private function expect(string $op): void
    {
        $token = $this->tokens[$this->at];
        if ($token['type'] !== 'op' || $token['value'] !== $op) {
            $this->fail($token, "Expected '{$op}' but found");
        }
        $this->at++;
    }

    private function word(string $word): bool
    {
        $token = $this->peek();
        if ($token['type'] === 'id' && strtolower($token['value']) === $word) {
            $this->at++;

            return true;
        }

        return false;
    }

    private function operator(): ?string
    {
        $token = $this->peek();

        return $token['type'] === 'op' ? $token['value'] : null;
    }

    private function peek(): array
    {
        return $this->tokens[$this->at];
    }

    private function fail(array $token, string $what = 'Unexpected'): never
    {
        $found = $token['type'] === 'end' ? 'end of formula' : "'{$token['value']}'";

        throw new InvalidArgumentException("{$what} {$found} at position ".($token['pos'] + 1).'.');
    }
}
