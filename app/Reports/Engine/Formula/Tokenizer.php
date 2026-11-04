<?php

declare(strict_types=1);

namespace App\Reports\Engine\Formula;

use InvalidArgumentException;

/**
 * Formula text to tokens: ['type' => num|str|id|op|end, 'value' => ..., 'pos' => offset].
 * Words (`null`, `and`, `or`, `not`, column ids, function names) are `id`; the parser tells them apart.
 */
final class Tokenizer
{
    private const OPERATORS = ['<=', '>=', '!=', '+', '-', '*', '/', '%', '(', ')', ',', '=', '<', '>'];

    /** @return list<array{type: string, value: mixed, pos: int}> */
    public function tokenize(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $pos = 0;
        while ($pos < $length) {
            $char = $source[$pos];

            if (ctype_space($char)) {
                $pos++;
            } elseif (preg_match('/\G(\d+(\.\d+)?|\.\d+)/', $source, $m, 0, $pos)) {
                // int, or float when it has a point or is too big for an int
                $tokens[] = ['type' => 'num', 'value' => $m[0] + 0, 'pos' => $pos];
                $pos += strlen($m[0]);
            } elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $source, $m, 0, $pos)) {
                $tokens[] = ['type' => 'id', 'value' => $m[0], 'pos' => $pos];
                $pos += strlen($m[0]);
            } elseif ($char === '"' || $char === "'") {
                [$value, $end] = $this->string($source, $pos);
                $tokens[] = ['type' => 'str', 'value' => $value, 'pos' => $pos];
                $pos = $end;
            } elseif (($op = $this->operator($source, $pos)) !== null) {
                $tokens[] = ['type' => 'op', 'value' => $op, 'pos' => $pos];
                $pos += strlen($op);
            } else {
                preg_match('/\G./su', $source, $m, 0, $pos);

                throw new InvalidArgumentException("Unexpected character '".($m[0] ?? $char)."' at position ".($pos + 1).'.');
            }
        }
        $tokens[] = ['type' => 'end', 'value' => null, 'pos' => $length];

        return $tokens;
    }

    /** @return array{0: string, 1: int} the text and the offset after the closing quote */
    private function string(string $source, int $start): array
    {
        $quote = $source[$start];
        $value = '';
        for ($pos = $start + 1; $pos < strlen($source); $pos++) {
            if ($source[$pos] === '\\' && $pos + 1 < strlen($source)) {
                $value .= $source[++$pos];
            } elseif ($source[$pos] === $quote) {
                return [$value, $pos + 1];
            } else {
                $value .= $source[$pos];
            }
        }

        throw new InvalidArgumentException('Unclosed text starting at position '.($start + 1).'.');
    }

    private function operator(string $source, int $pos): ?string
    {
        foreach (self::OPERATORS as $op) {
            if (substr($source, $pos, strlen($op)) === $op) {
                return $op;
            }
        }

        return null;
    }
}
