<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Http\Controllers\API\ReportsController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure unit tests for the store-score scoring math.
 *
 * These exercise the private scoring helpers directly (via reflection) with
 * in-memory inputs, so they need neither the database nor a booted Laravel
 * app. The DB-backed orchestration (queries + proration wiring) is covered
 * separately by end-to-end checks against the dashboard endpoint.
 */
class StoreScoreTest extends TestCase
{
    private ReportsController $controller;
    private ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ref = new ReflectionClass(ReportsController::class);
        // No constructor: the pure helpers don't use the injected services.
        $this->controller = $this->ref->newInstanceWithoutConstructor();
    }

    private function call(string $method, array $args)
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->controller, ...$args);
    }

    private function row(array $attrs): object
    {
        return (object) $attrs;
    }

    // ----------------------------------------------------------------- label

    #[DataProvider('labelCases')]
    public function test_label_for_score(float $score, string $expected): void
    {
        $this->assertSame($expected, $this->call('labelForScore', [$score]));
    }

    public static function labelCases(): array
    {
        return [
            [100.0, 'Fantastic +'],
            [80.0, 'Fantastic +'],
            [79.99, 'Fantastic'],
            [70.0, 'Fantastic'],
            [69.99, 'Good'],
            [60.0, 'Good'],
            [59.99, 'Fair'],
            [50.0, 'Fair'],
            [49.99, 'Poor'],
            [0.0, 'Poor'],
        ];
    }

    // ---------------------------------------------------------- normal hours

    #[DataProvider('normalHoursCases')]
    public function test_normal_hours_score(
        float $orig,
        float $upd,
        float $act,
        float $expectedScore,
        int $expectedCase
    ): void {
        [$score, $case] = $this->call('normalHoursScore', [$orig, $upd, $act]);

        $this->assertSame($expectedCase, $case);
        $this->assertEqualsWithDelta($expectedScore, $score, 0.01);
    }

    public static function normalHoursCases(): array
    {
        return [
            // case 1: upd>=orig & act>=upd -> ((act-upd)/upd)*2
            'case1 over raised goal' => [100, 110, 115.5, 25.0, 1],
            // case 2: upd>=orig & act>=orig (act<upd) -> 0 deduction -> full 35
            'case2 in band'          => [100, 110, 105, 35.0, 2],
            // case 3: upd>=orig & act<=orig -> (upd-act)/upd
            'case3 under orig'       => [100, 110, 88, 15.0, 3],
            // case 4: upd<=orig & act>=orig -> ((act-upd)/upd)*3  (4/96*3 = 0.125)
            'case4 over lowered goal'=> [100, 96, 100, 22.5, 4],
            // case 5: upd<=orig & act<=upd -> ((upd-act)/upd)*2
            'case5 under lowered'    => [100, 90, 81, 15.0, 5],
            // case 6: else (upd<act<orig) -> ((act-upd)/upd)*2
            'case6 between'          => [100, 90, 94.5, 25.0, 6],
            // degenerate updated goal -> 0
            'degenerate zero goal'   => [0, 0, 50, 0.0, 0],
            'degenerate negative'    => [100, -5, 50, 0.0, 0],
        ];
    }

    // -------------------------------------------------------- overtime hours

    public function test_overtime_full_when_at_or_below_goal(): void
    {
        $this->assertEqualsWithDelta(15.0, $this->call('overtimeHoursScore', [10.0, 8.0, 100.0]), 0.01);
        $this->assertEqualsWithDelta(15.0, $this->call('overtimeHoursScore', [10.0, 10.0, 100.0]), 0.01);
    }

    public function test_overtime_penalized_above_goal(): void
    {
        // (|10-12|/100)*2 = 0.04 -> (0.15-0.04)*100 = 11
        $this->assertEqualsWithDelta(11.0, $this->call('overtimeHoursScore', [10.0, 12.0, 100.0]), 0.01);
        // big gap floors at 0: (|10-20|/100)*2 = 0.2 -> 0
        $this->assertEqualsWithDelta(0.0, $this->call('overtimeHoursScore', [10.0, 20.0, 100.0]), 0.01);
    }

    public function test_overtime_zero_when_normal_goal_nonpositive(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->call('overtimeHoursScore', [10.0, 12.0, 0.0]), 0.01);
    }

    // ------------------------------------------------ percent vs goal (below)

    #[DataProvider('percentVsGoalCases')]
    public function test_percent_vs_goal_below_only(
        float $sum,
        int $count,
        ?float $goal,
        float $max,
        float $expectedScore,
        ?float $expectedActual
    ): void {
        [$score, $actual] = $this->call('percentVsGoalBelowOnly', [$sum, $count, $goal, $max]);

        $this->assertEqualsWithDelta($expectedScore, $score, 0.01);
        if ($expectedActual === null) {
            $this->assertNull($actual);
        } else {
            $this->assertEqualsWithDelta($expectedActual, $actual, 0.01);
        }
    }

    public static function percentVsGoalCases(): array
    {
        return [
            'no data -> full points'      => [0.0, 0, 90.0, 10.0, 10.0, null],
            'missing goal -> zero'        => [180.0, 2, null, 10.0, 0.0, 90.0],
            'at goal -> full'             => [180.0, 2, 90.0, 10.0, 10.0, 90.0],
            'above goal -> full'          => [190.0, 2, 90.0, 10.0, 10.0, 95.0],
            'below by 3 -> lose 3'        => [174.0, 2, 90.0, 10.0, 7.0, 87.0],
            'below beyond max -> floor 0' => [150.0, 2, 90.0, 10.0, 0.0, 75.0],
        ];
    }

    // -------------------------------------------------------------- portal

    public function test_portal_mean_of_daily_at_goal(): void
    {
        $rows = [
            $this->row([
                'business_date' => '2026-06-16',
                'portal_eligible_orders' => 100, 'portal_used_orders' => 90, 'portal_on_time_orders' => 81,
            ]), // put 90, ontime 90 -> 90
            $this->row([
                'business_date' => '2026-06-17',
                'portal_eligible_orders' => 50, 'portal_used_orders' => 40, 'portal_on_time_orders' => 40,
            ]), // put 80, ontime 100 -> 90
            $this->row([
                'business_date' => '2026-06-18',
                'portal_eligible_orders' => 0, 'portal_used_orders' => 0, 'portal_on_time_orders' => 0,
            ]), // skipped (no eligible)
        ];

        $detail = $this->call('scorePortal', [$rows, 90.0]);

        $this->assertSame('portal', $detail['key']);
        $this->assertSame(2, $detail['days_counted']);
        $this->assertEqualsWithDelta(90.0, $detail['actual_percent'], 0.01);
        $this->assertEqualsWithDelta(10.0, $detail['score'], 0.01);
        $this->assertEqualsWithDelta(90.0, $detail['daily']['2026-06-16'], 0.01);
        $this->assertArrayNotHasKey('2026-06-18', $detail['daily']);
    }

    public function test_portal_below_goal_loses_points(): void
    {
        $rows = [
            $this->row([
                'business_date' => '2026-06-16',
                'portal_eligible_orders' => 100, 'portal_used_orders' => 90, 'portal_on_time_orders' => 81,
            ]),
        ];

        $detail = $this->call('scorePortal', [$rows, 95.0]); // actual 90, below 5

        $this->assertEqualsWithDelta(5.0, $detail['score'], 0.01);
    }

    // ----------------------------------------------------------------- hnr

    public function test_hnr_mean_of_daily(): void
    {
        $rows = [
            $this->row(['business_date' => '2026-06-16', 'hnr_transactions' => 100, 'hnr_broken_promises' => 10]), // 90
            $this->row(['business_date' => '2026-06-17', 'hnr_transactions' => 200, 'hnr_broken_promises' => 20]), // 90
            $this->row(['business_date' => '2026-06-18', 'hnr_transactions' => 0, 'hnr_broken_promises' => 0]),    // skipped
        ];

        $detail = $this->call('scoreHnr', [$rows, 90.0]);

        $this->assertSame('hnr', $detail['key']);
        $this->assertSame(2, $detail['days_counted']);
        $this->assertEqualsWithDelta(90.0, $detail['actual_percent'], 0.01);
        $this->assertEqualsWithDelta(10.0, $detail['score'], 0.01);
    }

    public function test_hnr_no_transactions_awards_full(): void
    {
        $rows = [
            $this->row(['business_date' => '2026-06-16', 'hnr_transactions' => 0, 'hnr_broken_promises' => 0]),
        ];

        $detail = $this->call('scoreHnr', [$rows, 95.0]);

        $this->assertSame(0, $detail['days_counted']);
        $this->assertNull($detail['actual_percent']);
        $this->assertEqualsWithDelta(10.0, $detail['score'], 0.01);
    }

    // ------------------------------------------------------- goal extraction

    public function test_goal_value_from_metrics(): void
    {
        $metrics = [
            ['metric_id' => 9, 'metric_name' => 'Normal Hours', 'goals' => [['goal' => '350.0000']]],
            ['metric_id' => 4, 'metric_name' => 'Sales', 'goals' => [['goal' => '50000.0000']]],
            ['metric_id' => 12, 'metric_name' => 'HnR', 'goals' => []],
        ];

        $this->assertEqualsWithDelta(350.0, $this->call('goalValueFromMetrics', [$metrics, 9]), 0.0001);
        $this->assertEqualsWithDelta(50000.0, $this->call('goalValueFromMetrics', [$metrics, 4]), 0.0001);
        $this->assertNull($this->call('goalValueFromMetrics', [$metrics, 12])); // empty goals
        $this->assertNull($this->call('goalValueFromMetrics', [$metrics, 99])); // absent metric
    }
}
