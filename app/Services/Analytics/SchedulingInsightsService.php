<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Aggregation\HourlyStoreSummary;
use App\Services\Analytics\Support\OutlierDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Hourly sales history for the scheduling screen.
 *
 * For each weekday (Tuesday first, like the business week) it reports, per
 * hour, what a typical day looks like across the window's weeks, so a manager
 * can see how busy each hour usually is while building the schedule.
 *
 * Rules:
 *  - a date with no sales at all (closed, outage) is skipped and does not count
 *    towards that weekday's average;
 *  - on a date that does count, an hour with no row is a real $0 hour;
 *  - weeks that look odd are flagged (see OutlierDetector) and reported next to
 *    both the average with them (`avg`) and without them (`typical`).
 *
 * `hour` is HOUR(date_time_fulfilled) in store-local time and after-midnight
 * hours stay on the previous business_date, so hours are 0-23 of a business day.
 */
class SchedulingInsightsService
{
    /** Business-week order: Tuesday first. Carbon dayOfWeek, Sunday = 0. */
    private const WEEKDAY_ORDER = [2, 3, 4, 5, 6, 0, 1];

    /** The hourly aggregations are rebuilt at 10:30 and 13:30 ET. */
    private const CACHE_SECONDS = 21600;

    private const WEEKDAY_NAMES = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ];

    /** Plain averaged metrics, carried alongside sales. name => column. */
    private const EXTRA_METRICS = [
        'customers' => 'customer_count',
        'delivery_sales' => 'delivery_sales',
        'carryout_sales' => 'carryout_sales',
        'drive_thru_sales' => 'drive_thru_sales',
        'digital_sales' => 'digital_sales',
    ];

    public function getInsights(string $store, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = sprintf('scheduling-insights:%s:%s:%s', $store, $start->toDateString(), $end->toDateString());

        return Cache::remember(
            $key,
            self::CACHE_SECONDS,
            function () use ($store, $start, $end) {
                $rows = HourlyStoreSummary::query()
                    ->where('franchise_store', $store)
                    ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
                    ->get([
                        'business_date', 'hour', 'royalty_obligation', 'total_orders', 'customer_count',
                        'delivery_sales', 'carryout_sales', 'drive_thru_sales', 'digital_sales',
                    ])
                    ->map(static fn ($r) => [
                        'business_date' => $r->business_date instanceof \DateTimeInterface
                            ? $r->business_date->format('Y-m-d')
                            : substr((string) $r->business_date, 0, 10),
                        'hour' => (int) $r->hour,
                        'royalty_obligation' => (float) $r->royalty_obligation,
                        'total_orders' => (float) $r->total_orders,
                        'customer_count' => (float) $r->customer_count,
                        'delivery_sales' => (float) $r->delivery_sales,
                        'carryout_sales' => (float) $r->carryout_sales,
                        'drive_thru_sales' => (float) $r->drive_thru_sales,
                        'digital_sales' => (float) $r->digital_sales,
                    ])
                    ->all();

                return $this->buildFromRows($store, $start, $end, $rows);
            }
        );
    }

    /**
     * Pure: no database, no framework state. Everything a test needs to drive.
     *
     * @param list<array<string, mixed>> $rows one per (business_date, hour)
     */
    public function buildFromRows(
        string $store,
        CarbonImmutable $start,
        CarbonImmutable $end,
        array $rows
    ): array {
        // date => hour => row
        $byDate = [];
        $dayTotals = [];
        foreach ($rows as $row) {
            $date = (string) $row['business_date'];
            $byDate[$date][(int) $row['hour']] = $row;
            $dayTotals[$date] = ($dayTotals[$date] ?? 0.0) + (float) $row['royalty_obligation'];
        }

        // Which dates count, and which weekday each belongs to.
        $counted = [];
        $skipped = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $date = $d->toDateString();
            $weekday = $d->dayOfWeek;

            if (($dayTotals[$date] ?? 0.0) > 0) {
                $counted[$weekday][] = $date;
            } else {
                $skipped[$weekday][] = $date;
            }
        }

        $weekdays = [];
        $anomalies = [];
        $dailyTotals = [];

        foreach (self::WEEKDAY_ORDER as $weekday) {
            $dates = $counted[$weekday] ?? [];
            $skippedDates = $skipped[$weekday] ?? [];

            foreach ($dates as $date) {
                $dailyTotals[] = [
                    'date' => $date,
                    'weekday' => $weekday,
                    'sales' => round($dayTotals[$date], 2),
                    'orders' => round($this->dayOrders($byDate[$date]), 2),
                ];
            }

            [$daily, $dayAnomalies] = $this->dailyBlock($dates, $byDate, $dayTotals);
            $dayFlags = [];
            foreach ($dayAnomalies as $a) {
                $dayFlags[$a['date']] = $a;
                $anomalies[] = $a + ['weekday' => $weekday, 'hour' => null, 'metric' => 'daily_sales'];
            }

            [$hours, $hourAnomalies] = $this->hoursBlock($dates, $byDate);
            foreach ($hourAnomalies as $a) {
                if ($this->isExplainedByDay($a, $dayFlags)) {
                    continue;
                }
                $anomalies[] = $a + ['weekday' => $weekday];
            }

            $weekdays[] = [
                'weekday' => $weekday,
                'name' => self::WEEKDAY_NAMES[$weekday],
                'days_sampled' => count($dates),
                'dates' => $dates,
                'skipped_dates' => $skippedDates,
                'daily' => $daily,
                'hours' => $hours,
            ];
        }

        usort($anomalies, static fn ($a, $b) => [$b['date'], $a['hour'] ?? -1] <=> [$a['date'], $b['hour'] ?? -1]);
        usort($dailyTotals, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        return [
            'filtering' => [
                'store' => $store,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'week_start_dow' => 2,
            ],
            'weekdays' => $weekdays,
            'anomalies' => $anomalies,
            'daily_totals' => $dailyTotals,
        ];
    }


    /**
     * @param list<string> $dates
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function dailyBlock(array $dates, array $byDate, array $dayTotals): array
    {
        $sales = [];
        $orders = [];
        $customers = [];
        foreach ($dates as $date) {
            $sales[$date] = $dayTotals[$date];
            $orders[$date] = $this->dayOrders($byDate[$date]);
            $customers[$date] = (float) array_sum(array_column($byDate[$date], 'customer_count'));
        }

        $salesStats = OutlierDetector::analyze($sales);
        $ordersStats = OutlierDetector::analyze($orders);
        $excluded = array_column($salesStats['anomalies'], 'date');

        return [[
            'sales' => $salesStats,
            'orders' => $ordersStats,
            'customers' => $this->plain($customers, $excluded),
            'avg_ticket' => [
                'avg' => $ordersStats['avg'] > 0 ? round($salesStats['avg'] / $ordersStats['avg'], 2) : 0.0,
                'typical' => $ordersStats['typical'] > 0 ? round($salesStats['typical'] / $ordersStats['typical'], 2) : 0.0,
            ],
        ], $salesStats['anomalies']];
    }

    /**
     * @param list<string> $dates
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function hoursBlock(array $dates, array $byDate): array
    {
        $hourSet = [];
        foreach ($dates as $date) {
            foreach (array_keys($byDate[$date]) as $hour) {
                $hourSet[$hour] = true;
            }
        }
        $hourList = array_keys($hourSet);
        sort($hourList);

        // Every hour's weekly series first, so each hour can be judged against
        // the size of this weekday's busiest hour.
        $allSeries = [];
        $scale = ['sales' => 0.0, 'orders' => 0.0];
        foreach ($hourList as $hour) {
            $series = ['sales' => [], 'orders' => []];
            foreach (array_keys(self::EXTRA_METRICS) as $name) {
                $series[$name] = [];
            }

            foreach ($dates as $date) {
                $row = $byDate[$date][$hour] ?? null;
                $series['sales'][$date] = (float) ($row['royalty_obligation'] ?? 0);
                $series['orders'][$date] = (float) ($row['total_orders'] ?? 0);
                foreach (self::EXTRA_METRICS as $name => $column) {
                    $series[$name][$date] = (float) ($row[$column] ?? 0);
                }
            }

            foreach (['sales', 'orders'] as $metric) {
                $scale[$metric] = max($scale[$metric], OutlierDetector::median(array_values($series[$metric])));
            }
            $allSeries[$hour] = $series;
        }

        $hours = [];
        $anomalies = [];

        foreach ($hourList as $hour) {
            $series = $allSeries[$hour];

            $sales = OutlierDetector::analyze($series['sales'], $scale['sales']);
            $orders = OutlierDetector::analyze($series['orders'], $scale['orders']);
            $excluded = array_column($sales['anomalies'], 'date');

            $entry = ['hour' => $hour, 'sales' => $sales, 'orders' => $orders];
            foreach (array_keys(self::EXTRA_METRICS) as $name) {
                $entry[$name] = $this->plain($series[$name], $excluded);
            }
            $hours[] = $entry;

            foreach ($sales['anomalies'] as $a) {
                $anomalies[] = $a + ['hour' => $hour, 'metric' => 'sales'];
            }
        }

        return [$hours, $anomalies];
    }

    /**
     * An hour flagged on a date whose whole day is flagged the same way is
     * mostly the day showing through; it is reported under the day unless the
     * hour is odd by clearly more than the day was.
     */
    private function isExplainedByDay(array $hourAnomaly, array $dayFlags): bool
    {
        $day = $dayFlags[$hourAnomaly['date']] ?? null;
        if ($day === null || $day['kind'] !== $hourAnomaly['kind']) {
            return false;
        }

        if ($day['ratio'] <= 0 || $hourAnomaly['ratio'] <= 0) {
            return false;
        }

        return abs(log($hourAnomaly['ratio'] / $day['ratio'])) <= log(1.5);
    }

    /**
     * @param array<string, float> $samples
     * @param list<string> $excludeDates
     * @return array{avg: float, typical: float}
     */
    private function plain(array $samples, array $excludeDates): array
    {
        if ($samples === []) {
            return ['avg' => 0.0, 'typical' => 0.0];
        }

        $avg = array_sum($samples) / count($samples);
        $kept = array_diff_key($samples, array_flip($excludeDates));
        $typical = $kept === [] ? $avg : array_sum($kept) / count($kept);

        return ['avg' => round($avg, 2), 'typical' => round($typical, 2)];
    }

    /** @param array<int, array<string, mixed>> $hourRows */
    private function dayOrders(array $hourRows): float
    {
        return (float) array_sum(array_column($hourRows, 'total_orders'));
    }
}
