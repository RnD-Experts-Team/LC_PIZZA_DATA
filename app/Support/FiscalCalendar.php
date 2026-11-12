<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The business calendar every summary is built on: Tuesday–Monday weeks, a
 * fiscal year that starts on the Tuesday on/before January 1, 4-week periods
 * (13 a year; a rare 53rd week folds into period 13) and 3-3-3-4 quarters of
 * periods. The same rules Sales History uses (ReportsController::fiscalWeekInfo).
 */
final class FiscalCalendar
{
    public const PERIOD_WEEKS = 4;

    public const PERIODS = 13;

    private const DAYS_IN_WEEK = 7;

    /** Each quarter's first period (3-3-3-4). */
    private const QUARTER_FIRST_PERIOD = [1 => 1, 2 => 4, 3 => 7, 4 => 10];

    /** The Tuesday that starts the business week of $date. */
    public static function weekStart(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString())->startOfWeek(CarbonInterface::TUESDAY);
    }

    public static function yearStart(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, 1, 1)->startOfWeek(CarbonInterface::TUESDAY);
    }

    public static function yearOf(CarbonInterface $date): int
    {
        $day = CarbonImmutable::parse($date->toDateString());
        $year = $day->year;

        if ($day->lt(self::yearStart($year))) {
            return $year - 1;
        }

        return $day->gte(self::yearStart($year + 1)) ? $year + 1 : $year;
    }

    /**
     * Fiscal year, week (1–53), period (1–13) and quarter (1–4) of $date.
     *
     * @return array{year: int, week: int, period: int, quarter: int}
     */
    public static function of(CarbonInterface $date): array
    {
        $start = self::weekStart($date);
        $year = self::yearOf($start);
        $weekIndex = intdiv((int) round(abs(self::yearStart($year)->diffInDays($start))), self::DAYS_IN_WEEK);
        $period = min(self::PERIODS, intdiv($weekIndex, self::PERIOD_WEEKS) + 1);
        $quarter = 1;
        foreach (self::QUARTER_FIRST_PERIOD as $q => $first) {
            if ($period >= $first) {
                $quarter = $q;
            }
        }

        return ['year' => $year, 'week' => $weekIndex + 1, 'period' => $period, 'quarter' => $quarter];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} first and last day */
    public static function weekRange(CarbonInterface $date): array
    {
        $start = self::weekStart($date);

        return [$start, $start->addDays(self::DAYS_IN_WEEK - 1)];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function periodRange(int $year, int $period): array
    {
        $start = self::yearStart($year)->addWeeks(($period - 1) * self::PERIOD_WEEKS);
        $end = $period >= self::PERIODS
            ? self::yearStart($year + 1)->subDay()
            : $start->addWeeks(self::PERIOD_WEEKS)->subDay();

        return [$start, $end];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function quarterRange(int $year, int $quarter): array
    {
        $first = self::QUARTER_FIRST_PERIOD[$quarter];
        $last = $quarter === 4 ? self::PERIODS : self::QUARTER_FIRST_PERIOD[$quarter + 1] - 1;

        return [self::periodRange($year, $first)[0], self::periodRange($year, $last)[1]];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function yearRange(int $year): array
    {
        return [self::yearStart($year), self::yearStart($year + 1)->subDay()];
    }
}
