<?php

namespace Tests\Unit\Support;

use App\Support\FiscalCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class FiscalCalendarTest extends TestCase
{
    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_weeks_run_tuesday_to_monday(): void
    {
        [$start, $end] = FiscalCalendar::weekRange($this->day('2026-11-09')); // a Monday

        $this->assertSame(['2026-11-03', '2026-11-09'], [$start->toDateString(), $end->toDateString()]);
    }

    public function test_the_year_starts_on_the_tuesday_on_or_before_january_first(): void
    {
        // 2026-01-01 is a Thursday: fiscal 2026 starts Tuesday 2025-12-30.
        $this->assertSame('2025-12-30', FiscalCalendar::yearStart(2026)->toDateString());
        $this->assertSame(2026, FiscalCalendar::yearOf($this->day('2025-12-31')));
        $this->assertSame(2025, FiscalCalendar::yearOf($this->day('2025-12-29')));
        // The days that broke the old ISO-week build land in week 1 of the new year.
        $this->assertSame(['year' => 2026, 'week' => 1, 'period' => 1, 'quarter' => 1], FiscalCalendar::of($this->day('2025-12-31')));
    }

    public function test_periods_are_four_weeks_and_quarters_three_three_three_four(): void
    {
        $this->assertSame(['year' => 2026, 'week' => 5, 'period' => 2, 'quarter' => 1], FiscalCalendar::of($this->day('2026-01-27')));
        $this->assertSame(['year' => 2026, 'week' => 37, 'period' => 10, 'quarter' => 4], FiscalCalendar::of($this->day('2026-09-08')));

        [$p2Start, $p2End] = FiscalCalendar::periodRange(2026, 2);
        $this->assertSame(['2026-01-27', '2026-02-23'], [$p2Start->toDateString(), $p2End->toDateString()]);

        // Q4 = periods 10–13, up to the day before the next fiscal year.
        [$q4Start, $q4End] = FiscalCalendar::quarterRange(2026, 4);
        $this->assertSame(['2026-09-08', '2026-12-28'], [$q4Start->toDateString(), $q4End->toDateString()]);
        $this->assertSame('2026-12-29', FiscalCalendar::yearStart(2027)->toDateString());
    }

    public function test_a_53rd_week_folds_into_period_13(): void
    {
        // Fiscal 2024 runs 2023-12-26 .. 2024-12-30: 53 weeks.
        $this->assertSame(['year' => 2024, 'week' => 53, 'period' => 13, 'quarter' => 4], FiscalCalendar::of($this->day('2024-12-30')));
        [, $p13End] = FiscalCalendar::periodRange(2024, 13);
        $this->assertSame('2024-12-30', $p13End->toDateString());
    }

    public function test_every_day_of_a_year_is_in_exactly_one_period(): void
    {
        [$yearStart, $yearEnd] = FiscalCalendar::yearRange(2026);
        $days = 0;
        foreach (range(1, FiscalCalendar::PERIODS) as $period) {
            [$from, $to] = FiscalCalendar::periodRange(2026, $period);
            $days += (int) round(abs($from->diffInDays($to))) + 1;
        }

        $this->assertSame((int) round(abs($yearStart->diffInDays($yearEnd))) + 1, $days);
    }
}
