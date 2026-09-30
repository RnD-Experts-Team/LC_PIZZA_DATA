<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Services\Analytics\SchedulingInsightsService;
use App\Services\Analytics\Support\OutlierDetector;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for the scheduling-insights maths: no database, no framework.
 */
class SchedulingInsightsMathTest extends TestCase
{
    private static function series(array $values): array
    {
        $out = [];
        foreach ($values as $i => $v) {
            $out['2026-09-' . str_pad((string) ($i * 7 + 1), 2, '0', STR_PAD_LEFT)] = $v;
        }

        return $out;
    }

    public static function outlierCases(): array
    {
        return [
            'spike' => [[400, 410, 395, 820], ['spike'], false],
            'dip' => [[2400, 2350, 1200, 2450], ['dip'], false],
            'under the absolute gap' => [[12, 25, 20, 18], [], false],
            'weeks that just differ' => [[100, 300, 100, 300], [], true],
            'too few samples' => [[400, 900], [], false],
            'steady' => [[400, 410, 395, 405], [], false],
        ];
    }

    #[DataProvider('outlierCases')]
    public function test_flags_odd_weeks(array $values, array $kinds, bool $volatile): void
    {
        $r = OutlierDetector::analyze(self::series($values));

        $this->assertSame($kinds, array_column($r['anomalies'], 'kind'));
        $this->assertSame($volatile, $r['volatile']);
    }

    public function test_typical_leaves_the_odd_week_out_and_avg_keeps_it(): void
    {
        $r = OutlierDetector::analyze(self::series([400, 410, 395, 820]));

        $this->assertEqualsWithDelta(506.25, $r['avg'], 0.01);
        $this->assertEqualsWithDelta(401.67, $r['typical'], 0.01);
        $this->assertSame(4, $r['samples']);
        $this->assertSame(3, $r['typical_samples']);
        $this->assertSame(820.0, $r['high']);
        $this->assertSame(395.0, $r['low']);
        $this->assertSame(2.0, round($r['anomalies'][0]['ratio']));
    }

    public function test_typical_equals_avg_when_nothing_is_flagged(): void
    {
        $r = OutlierDetector::analyze(self::series([400, 410, 395, 405]));

        $this->assertSame($r['avg'], $r['typical']);
        $this->assertSame([], $r['anomalies']);
    }

    public function test_empty_series(): void
    {
        $r = OutlierDetector::analyze([]);

        $this->assertSame(0, $r['samples']);
        $this->assertSame([], $r['anomalies']);
    }

    private function row(string $date, int $hour, float $sales, float $orders = 1): array
    {
        return [
            'business_date' => $date, 'hour' => $hour, 'royalty_obligation' => $sales,
            'total_orders' => $orders, 'customer_count' => $orders,
            'delivery_sales' => 0.0, 'carryout_sales' => 0.0, 'drive_thru_sales' => 0.0, 'digital_sales' => 0.0,
        ];
    }

    private function tuesday(array $insights): array
    {
        foreach ($insights['weekdays'] as $w) {
            if ($w['weekday'] === 2) {
                return $w;
            }
        }
        $this->fail('no tuesday');
    }

    public function test_closed_day_is_left_out_of_the_denominator(): void
    {
        // Four Tuesdays: Sep 1, 8, 15, 22. Sep 8 has no data at all.
        $rows = [
            $this->row('2026-09-01', 18, 400),
            $this->row('2026-09-15', 18, 500),
            $this->row('2026-09-22', 18, 600),
        ];

        $r = (new SchedulingInsightsService())->buildFromRows(
            '03795-00001',
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-28'),
            $rows
        );

        $tue = $this->tuesday($r);
        $this->assertSame(3, $tue['days_sampled']);
        $this->assertSame(['2026-09-08'], $tue['skipped_dates']);
        $this->assertSame(500.0, $tue['hours'][0]['sales']['avg']);
    }

    public function test_missing_hour_on_a_counted_day_is_a_zero_sample(): void
    {
        // Hour 19 has sales on one of the two counted Tuesdays only.
        $rows = [
            $this->row('2026-09-01', 18, 400),
            $this->row('2026-09-15', 18, 400),
            $this->row('2026-09-15', 19, 200),
        ];

        $r = (new SchedulingInsightsService())->buildFromRows(
            '03795-00001',
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-28'),
            $rows
        );

        $tue = $this->tuesday($r);
        $this->assertSame(2, $tue['days_sampled']);
        $hour19 = $tue['hours'][1];
        $this->assertSame(19, $hour19['hour']);
        $this->assertSame(100.0, $hour19['sales']['avg']);
        $this->assertSame(0.0, $hour19['sales']['low']);
    }

    public function test_weekdays_are_ordered_tuesday_first_and_odd_day_is_reported(): void
    {
        $rows = [];
        foreach (['2026-09-01' => 2400, '2026-09-08' => 2350, '2026-09-15' => 1200, '2026-09-22' => 2450] as $date => $sales) {
            $rows[] = $this->row($date, 12, $sales, 60);
        }

        $r = (new SchedulingInsightsService())->buildFromRows(
            '03795-00001',
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-28'),
            $rows
        );

        $this->assertSame([2, 3, 4, 5, 6, 0, 1], array_column($r['weekdays'], 'weekday'));

        $daily = array_values(array_filter($r['anomalies'], fn ($a) => $a['metric'] === 'daily_sales'));
        $this->assertCount(1, $daily);
        $this->assertSame('2026-09-15', $daily[0]['date']);
        $this->assertSame('dip', $daily[0]['kind']);

        // The 12 o'clock hour of that day is the same dip showing through, so it
        // is not listed a second time.
        $hourly = array_filter($r['anomalies'], fn ($a) => $a['metric'] === 'sales');
        $this->assertCount(0, $hourly);
    }
}
