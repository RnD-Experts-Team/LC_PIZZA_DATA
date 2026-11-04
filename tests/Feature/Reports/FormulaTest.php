<?php

namespace Tests\Feature\Reports;

use App\Reports\Engine\Formula\Evaluator;
use App\Reports\Engine\Formula\Parser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The report formula language: no framework needed, the parser and the
 * evaluator are plain PHP.
 */
class FormulaTest extends TestCase
{
    private function evaluate(string $expr, array $row = []): mixed
    {
        return (new Evaluator())->evaluate((new Parser())->parse($expr), $row);
    }

    public static function precedence(): array
    {
        return [
            'times before plus' => ['1 + 2 * 3', 7],
            'parentheses first' => ['(1 + 2) * 3', 9],
            'minus is left associative' => ['10 - 4 - 3', 3],
            'unary minus' => ['-2 * 3', -6],
            'modulo with times' => ['2 * 3 % 4', 2],
            'division gives decimals' => ['7 / 2', 3.5],
            'comparison after arithmetic' => ['1 + 2 > 2', true],
            'not before and' => ['not 0 = 1 and 1', true],
            'and before or' => ['1 or 0 and 0', true],
            'text equality' => ["'a' = \"a\"", true],
        ];
    }

    #[DataProvider('precedence')]
    public function test_operator_precedence(string $expr, mixed $expected): void
    {
        $this->assertSame($expected, $this->evaluate($expr));
    }

    public function test_if_and_coalesce(): void
    {
        $expr = 'if(c1 > 0, c2 / c1, 0)';

        $this->assertSame(2.5, $this->evaluate($expr, ['c1' => 4, 'c2' => 10]));
        $this->assertSame(0, $this->evaluate($expr, ['c1' => 0, 'c2' => 10]));
        // null > 0 is null, which is false.
        $this->assertSame(0, $this->evaluate($expr, ['c1' => null, 'c2' => 10]));

        $this->assertSame(3, $this->evaluate('coalesce(c1, c2, 5)', ['c1' => null, 'c2' => 3]));
        $this->assertSame(5, $this->evaluate('coalesce(c1, c2, 5)'));
    }

    public function test_null_and_division_by_zero_give_null(): void
    {
        $this->assertNull($this->evaluate('c1 / 0', ['c1' => 10]));
        $this->assertNull($this->evaluate('c1 % 0', ['c1' => 10]));
        $this->assertNull($this->evaluate('c1 / c2', ['c1' => 10, 'c2' => null]));
        $this->assertNull($this->evaluate('c1 + null', ['c1' => 1]));
        $this->assertNull($this->evaluate('-c9'));
        $this->assertNull($this->evaluate('percent(c1, 0)', ['c1' => 3]));
        $this->assertNull($this->evaluate('round(c1 / c2, 2)', ['c1' => 1, 'c2' => 0]));
        $this->assertNull($this->evaluate("'x' * 2"));
    }

    public function test_functions(): void
    {
        $this->assertSame(2.1, $this->evaluate('round(c2 / 60, 1)', ['c2' => 125]));
        $this->assertSame(25.0, $this->evaluate('percent(1, 4)'));
        $this->assertSame(4, $this->evaluate('abs(-4)'));
        $this->assertSame(1, $this->evaluate('min(3, null, 1)'));
        $this->assertSame(3, $this->evaluate('max(3, null, 1)'));
        $this->assertSame(4, $this->evaluate('sum(3, null, 1)'));
        $this->assertSame(2, $this->evaluate('avg(3, null, 1)'));
        $this->assertNull($this->evaluate('sum(null, c9)'));
        $this->assertSame('03795-00044 / 12', $this->evaluate("concat(store, ' / ', c1, c9)", ['store' => '03795-00044', 'c1' => 12]));
        $this->assertSame('ABC', $this->evaluate("upper('abc')"));
        $this->assertSame('abc', $this->evaluate("lower('ABC')"));
        $this->assertSame(3, $this->evaluate("len('abc')"));
        $this->assertNull($this->evaluate('len(c1)'));
    }

    public function test_references_are_listed_once(): void
    {
        $ast = (new Parser())->parse('if(c1 > 0, c3 * 15 / c1 * 100, null)');

        $this->assertSame(['c1', 'c3'], Parser::references($ast));
    }

    public static function badFormulas(): array
    {
        return [
            'stray character' => ['c1 + # 2', "Unexpected character '#' at position 6."],
            'missing argument' => ['round(c1,', 'Unexpected end of formula at position 10.'],
            'missing parenthesis' => ['(c1 + 2', "Expected ')' but found end of formula at position 8."],
            'two values in a row' => ['c1 c2', "Unexpected 'c2' at position 4."],
            'chained comparison' => ['1 < 2 < 3', "Unexpected '<' at position 7."],
            'unknown function' => ['foo(1)', 'Unknown function foo at position 1.'],
            'wrong argument count' => ['if(1, 2)', 'if() takes 3 argument(s), at position 1.'],
            'unclosed text' => ["concat('ab", 'Unclosed text starting at position 8.'],
            'empty' => ['  ', 'The formula is empty.'],
            'too deep' => [str_repeat('(', 65).'1'.str_repeat(')', 65), 'Nesting deeper than 64 levels at position 65.'],
            'too deep with calls' => [str_repeat('abs(', 65).'1'.str_repeat(')', 65), 'Nesting deeper than 64 levels at position 257.'],
            'too deep with brackets and calls' => [str_repeat('(', 33).str_repeat('abs(', 32).'1'.str_repeat(')', 65), 'Nesting deeper than 64 levels at position 158.'],
        ];
    }

    #[DataProvider('badFormulas')]
    public function test_bad_formulas_name_the_position(string $expr, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new Parser())->parse($expr);
    }

    public function test_sixty_four_levels_of_nesting_are_allowed(): void
    {
        $this->assertSame(1, $this->evaluate(str_repeat('(', 64).'1'.str_repeat(')', 64)));
        $this->assertSame(1, $this->evaluate(str_repeat('abs(', 64).'1'.str_repeat(')', 64)));
        // Brackets and calls count together: 32 + 32.
        $this->assertSame(1, $this->evaluate(str_repeat('(', 32).str_repeat('abs(', 32).'1'.str_repeat(')', 64)));
    }

    public function test_a_formula_of_any_length_is_parsed(): void
    {
        $this->assertSame(5001, $this->evaluate(str_repeat('1+', 5000).'1'));
        $this->assertSame(20000, mb_strlen($this->evaluate("concat('".str_repeat('x', 20000)."')")));
    }

    public static function caseInsensitive(): array
    {
        return [
            'upper keywords' => ['1 AND 0 OR NOT 0', true],
            'mixed keywords' => ['Not 0 And 1', true],
            'null keyword' => ['NULL = Null', true],
            'upper if' => ["IF(1 > 0, 'y', 'n')", 'y'],
            'mixed function names' => ["Concat(UPPER('a'), Lower('B'), Len('cc'))", 'Ab2'],
            'coalesce and null' => ['COALESCE(NULL, 7)', 7],
            'round' => ['ROUND(2.5)', 3.0],
        ];
    }

    #[DataProvider('caseInsensitive')]
    public function test_keywords_and_function_names_ignore_case(string $expr, mixed $expected): void
    {
        $this->assertSame($expected, $this->evaluate($expr));
    }

    public function test_column_ids_are_case_sensitive(): void
    {
        $this->assertSame(5, $this->evaluate('c1', ['c1' => 5]));
        $this->assertNull($this->evaluate('C1', ['c1' => 5]));
    }

    public function test_equality_treats_null_as_a_value(): void
    {
        $this->assertTrue($this->evaluate('c1 = null'));
        $this->assertTrue($this->evaluate('c1 = null', ['c1' => null]));
        // An empty cell is null.
        $this->assertTrue($this->evaluate('c1 = null', ['c1' => '']));
        $this->assertFalse($this->evaluate('c1 != null', ['c1' => null]));
        $this->assertFalse($this->evaluate('c1 != null', ['c1' => '']));
        $this->assertFalse($this->evaluate('c1 = null', ['c1' => 0]));
        $this->assertTrue($this->evaluate('c1 != null', ['c1' => 0]));
        $this->assertTrue($this->evaluate('null = null'));
        $this->assertFalse($this->evaluate('c1 = 0', ['c1' => null]));
        $this->assertTrue($this->evaluate('c1 != 0', ['c1' => null]));
        // Ordering with null is still null.
        $this->assertNull($this->evaluate('c1 < 1'));
        $this->assertNull($this->evaluate('c1 >= null', ['c1' => 1]));
    }

    public static function rounding(): array
    {
        return [
            'half up' => ['round(2.5)', 3.0],
            'half away from zero below zero' => ['round(-2.5)', -3.0],
            'one half' => ['round(0.5)', 1.0],
            'minus one half' => ['round(-0.5)', -1.0],
            'digits' => ['round(1.25, 1)', 1.3],
            'digits below zero' => ['round(-1.25, 1)', -1.3],
            'tens' => ['round(15, -1)', 20.0],
            'tens below zero' => ['round(-15, -1)', -20.0],
            'no negative zero' => ['round(-0.4)', 0.0],
            'text number' => ["round('2.5')", 3.0],
            'empty' => ['round(c9)', null],
            'digits that are not a number' => ['round(5, c9)', null],
        ];
    }

    #[DataProvider('rounding')]
    public function test_round_goes_half_away_from_zero(string $expr, mixed $expected): void
    {
        $this->assertSame($expected, $this->evaluate($expr));
    }

    public function test_and_or_not_are_three_valued(): void
    {
        $this->assertFalse($this->evaluate('null and 0'));
        $this->assertFalse($this->evaluate('0 and null'));
        $this->assertNull($this->evaluate('null and 1'));
        $this->assertNull($this->evaluate('1 and null'));
        $this->assertTrue($this->evaluate('null or 1'));
        $this->assertTrue($this->evaluate('1 or null'));
        $this->assertNull($this->evaluate('null or 0'));
        $this->assertNull($this->evaluate('not null'));
        $this->assertFalse($this->evaluate('not 1'));
        // A null condition takes the else branch.
        $this->assertSame(2, $this->evaluate('if(null, 1, 2)'));
        $this->assertSame(2, $this->evaluate('if(c1 > 0 and c9, 1, 2)', ['c1' => 5]));
    }

    public function test_unary_plus_and_numbers_without_a_leading_digit(): void
    {
        $this->assertSame(5, $this->evaluate('+5'));
        $this->assertSame(-5, $this->evaluate('-+5'));
        $this->assertSame(3, $this->evaluate("+'3'"));
        $this->assertNull($this->evaluate("+'x'"));
        $this->assertSame(0.75, $this->evaluate('.5 + .25'));
        $this->assertSame(['c1'], Parser::references((new Parser())->parse('+c1 + -c1')));
    }

    public function test_an_unexpected_multibyte_character_is_named_whole(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unexpected character 'é' at position 6.");

        (new Parser())->parse('c1 + é');
    }

    public function test_text_longer_than_an_xlsx_cell_is_null(): void
    {
        $this->assertSame(32767, Evaluator::MAX_TEXT);
        $row = ['c1' => str_repeat('a', 16000), 'c2' => str_repeat('b', 767)];

        $this->assertSame(32767, mb_strlen($this->evaluate('concat(c1, c1, c2)', $row)));
        $this->assertNull($this->evaluate("concat(c1, c1, c2, 'x')", $row));
        // Upper-casing can lengthen text too.
        $this->assertNull($this->evaluate('upper(c1)', ['c1' => str_repeat('ß', 20000)]));
        $this->assertSame(20000, mb_strlen($this->evaluate('lower(c1)', ['c1' => str_repeat('A', 20000)])));
    }

    public function test_a_concat_chain_across_formula_columns_stops_growing_at_the_cap(): void
    {
        $evaluator = new Evaluator();
        $parser = new Parser();
        $row = ['c1' => str_repeat('a', 2000)];

        // The way the runner does it: each formula sees the ones before it.
        foreach (['c2' => 'concat(c1, c1, c1)', 'c3' => 'concat(c2, c2, c2)', 'c4' => 'concat(c3, c3, c3)', 'c5' => "concat(c4, 'tail')"] as $id => $expr) {
            $row[$id] = $evaluator->evaluate($parser->parse($expr), $row);
        }

        $this->assertSame(6000, mb_strlen($row['c2']));
        $this->assertSame(18000, mb_strlen($row['c3']));
        $this->assertNull($row['c4']);
        $this->assertSame('tail', $row['c5']);
    }
}
