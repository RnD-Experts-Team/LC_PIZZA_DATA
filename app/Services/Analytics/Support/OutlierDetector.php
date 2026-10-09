<?php

declare(strict_types=1);

namespace App\Services\Analytics\Support;

/**
 * Summarises the same metric across the weeks of a window (one sample per
 * week) and flags the weeks that look odd.
 *
 * Nothing is configured: what counts as odd comes from the data itself.
 *
 * Each sample is compared with the MEDIAN OF THE OTHER samples (a mean or
 * standard deviation would be dragged by the very week being judged). It is
 * flagged when its distance from that median is large compared with
 *
 *  - how much the other weeks normally move (a steady hour flags on a small
 *    change, a naturally jumpy one needs a big change), with a floor of 15% of
 *    the usual level so a perfectly flat series cannot flag on noise; and
 *  - the scale of what is being looked at (`$scale`, e.g. the store's busiest
 *    hour), so a $12 hour next to a $25 hour is never called odd in a store
 *    that does $400 an hour.
 *
 * If more weeks would be flagged than a minority, the weeks simply differ:
 * nothing is flagged and `volatile` is set.
 *
 * `avg` always includes every sample; `typical` leaves the flagged ones out.
 */
final class OutlierDetector
{
    /** Distances this many spreads from the usual are odd. */
    private const SPREAD_MULTIPLE = 3.0;

    /** Spread never drops below this share of the usual level. */
    private const SPREAD_FLOOR = 0.15;

    /** A difference smaller than this share of the scale is never odd. */
    private const SCALE_SHARE = 0.10;

    /** Fewer weeks than this cannot say what is usual. */
    private const MIN_SAMPLES = 3;

    /**
     * @param array<string, float|int> $samples date (Y-m-d) => value
     * @param float $scale magnitude of the thing measured; 0 means use this series' own median
     * @return array{
     *   avg: float, typical: float, samples: int, typical_samples: int,
     *   low: float, high: float, volatile: bool,
     *   anomalies: list<array{date: string, value: float, usual: float, ratio: float, kind: string}>
     * }
     */
    public static function analyze(array $samples, float $scale = 0.0): array
    {
        $n = count($samples);

        if ($n === 0) {
            return [
                'avg' => 0.0, 'typical' => 0.0, 'samples' => 0, 'typical_samples' => 0,
                'low' => 0.0, 'high' => 0.0, 'volatile' => false, 'anomalies' => [],
            ];
        }

        $values = array_map('floatval', array_values($samples));
        $avg = array_sum($values) / $n;

        if ($scale <= 0) {
            $scale = self::median($values);
        }

        $flagged = [];
        $volatile = false;

        if ($n >= self::MIN_SAMPLES) {
            foreach ($samples as $date => $value) {
                $others = [];
                foreach ($samples as $otherDate => $otherValue) {
                    if ($otherDate !== $date) {
                        $others[] = (float) $otherValue;
                    }
                }

                $usual = self::median($others);
                $value = (float) $value;
                $distance = abs($value - $usual);

                $spread = max(1.4826 * self::mad($others, $usual), self::SPREAD_FLOOR * $usual);

                if ($distance < self::SPREAD_MULTIPLE * $spread || $distance < self::SCALE_SHARE * $scale) {
                    continue;
                }

                $flagged[(string) $date] = [
                    'date' => (string) $date,
                    'value' => round($value, 2),
                    'usual' => round($usual, 2),
                    'ratio' => $usual > 0 ? round($value / $usual, 2) : 0.0,
                    'kind' => $value > $usual ? 'spike' : 'dip',
                ];
            }

            if (count($flagged) > intdiv($n - 1, 2)) {
                $flagged = [];
                $volatile = true;
            }
        }

        $kept = array_values(array_diff_key($samples, $flagged));
        $typical = $kept === []
            ? $avg
            : array_sum(array_map('floatval', $kept)) / count($kept);

        return [
            'avg' => round($avg, 2),
            'typical' => round($typical, 2),
            'samples' => $n,
            'typical_samples' => count($kept),
            'low' => round(min($values), 2),
            'high' => round(max($values), 2),
            'volatile' => $volatile,
            'anomalies' => array_values($flagged),
        ];
    }

    /** @param list<float> $values */
    public static function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        sort($values);
        $mid = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Median absolute deviation from `$center`. @param list<float> $values */
    private static function mad(array $values, float $center): float
    {
        return self::median(array_map(static fn (float $v) => abs($v - $center), $values));
    }
}
