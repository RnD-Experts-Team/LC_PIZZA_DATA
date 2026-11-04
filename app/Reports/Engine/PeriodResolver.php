<?php

declare(strict_types=1);

namespace App\Reports\Engine;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A definition's period to [from, to] dates, read in the given timezone at the
 * moment of the run. The business week is Tuesday to Monday.
 */
final class PeriodResolver
{
    public const TOKENS = ['today', 'yesterday', 'last_7_days', 'week_to_date', 'last_week', 'month_to_date', 'last_month'];

    /** @return array{0: string, 1: string} */
    public function resolve(array $period, string $timezone = 'America/New_York', ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->setTimezone($timezone)->startOfDay();

        if (($period['type'] ?? null) === 'fixed') {
            $from = $this->date($period['from'] ?? null);
            $to = $this->date($period['to'] ?? null);
            if ($from > $to) {
                throw new InvalidArgumentException('The period starts after it ends.');
            }

            return [$from, $to];
        }

        if (($period['type'] ?? null) !== 'relative') {
            throw new InvalidArgumentException('The period type must be relative or fixed.');
        }

        $weekStart = $today->startOfWeek(CarbonInterface::TUESDAY);

        [$from, $to] = match ($period['token'] ?? null) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_7_days' => [$today->subDays(7), $today->subDay()],
            'week_to_date' => [$weekStart, $today],
            'last_week' => [$weekStart->subWeek(), $weekStart->subDay()],
            'month_to_date' => [$today->startOfMonth(), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            default => throw new InvalidArgumentException('Unknown period token; use one of '.implode(', ', self::TOKENS).'.'),
        };

        return [$from->toDateString(), $to->toDateString()];
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new InvalidArgumentException('A fixed period needs from and to as YYYY-MM-DD.');
        }

        return $value;
    }
}
