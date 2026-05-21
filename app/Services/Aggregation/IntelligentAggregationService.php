<?php

namespace App\Services\Aggregation;

use DateInterval;
use DatePeriod;
use DateTime;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use SplPriorityQueue;

use App\Models\Aggregation\{
    HourlyStoreSummary,
    HourlyItemSummary,
    DailyStoreSummary,
    DailyItemSummary,
    WeeklyStoreSummary,
    WeeklyItemSummary,
    MonthlyStoreSummary,
    MonthlyItemSummary,
    QuarterlyStoreSummary,
    QuarterlyItemSummary,
    YearlyStoreSummary,
    YearlyItemSummary
};

/**
 * Intelligent Multi-Granularity Data Aggregation Service (Enterprise Grade)
 *
 * Goals:
 *  - Correctness for ANY query (Top-N, date ranges, store/item)
 *  - Cost-based choice of granularity with overlap subtraction
 *  - Streaming execution/merge (cursor) to reduce memory spikes
 *  - Metadata-safe filtering for weekly/monthly/quarterly/yearly summary tables
 *
 * Important behaviors:
 *  - Aggregates across the requested date range (NOT time series) by default.
 *    If you want a time series, pass 'group_time' => true.
 *  - ORDER BY / LIMIT are applied AFTER merge/subtractions for correctness.
 */
class IntelligentAggregationService
{
    private float $executionStartTime = 0.0;
    private int $debugLevel = 0;

    /**
     * Map granularity + summary type to model class
     */
    private const MODEL_MAP = [
        'hourly_store' => HourlyStoreSummary::class,
        'hourly_item' => HourlyItemSummary::class,

        'daily_store' => DailyStoreSummary::class,
        'daily_item' => DailyItemSummary::class,

        'weekly_store' => WeeklyStoreSummary::class,
        'weekly_item' => WeeklyItemSummary::class,

        'monthly_store' => MonthlyStoreSummary::class,
        'monthly_item' => MonthlyItemSummary::class,

        'quarterly_store' => QuarterlyStoreSummary::class,
        'quarterly_item' => QuarterlyItemSummary::class,

        'yearly_store' => YearlyStoreSummary::class,
        'yearly_item' => YearlyItemSummary::class,
    ];

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    public function fetchAggregatedData(array $input): array
    {
        $this->executionStartTime = microtime(true);

        $request = $this->parseRequest($input);
        $strategy = $this->optimizeStrategy($request);

        $data = $this->executeQueriesStreaming($request, $strategy);

        return [
            'success' => true,
            'data' => $data,
            'metadata' => [
                'query_plan' => $strategy,
                'execution_time_ms' => round((microtime(true) - $this->executionStartTime) * 1000, 2),
                'rows_returned' => count($data),
                'rows_scanned_est' => $this->calculateTotalRows($strategy),
            ]
        ];
    }

    public function explain(array $input): array
    {
        $this->debugLevel = 1;
        $request = $this->parseRequest($input);
        $strategy = $this->optimizeStrategy($request);

        return [
            'request' => $request,
            'strategy' => $strategy,
        ];
    }

    // ========================================================================
    // LAYER 1: REQUEST PARSING
    // ========================================================================

    private function parseRequest(array $input): array
    {
        $startDate = new DateTime($input['start_date'] ?? throw new InvalidArgumentException('start_date required'));
        $endDate = new DateTime($input['end_date'] ?? throw new InvalidArgumentException('end_date required'));

        if ($startDate > $endDate) {
            throw new InvalidArgumentException('start_date must be before end_date');
        }

        $orderBy = $input['order_by'] ?? null;
        $limit = $input['limit'] ?? null;

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'summary_type' => $input['summary_type'] ?? 'store',

            'metrics' => $this->parseMetrics($input['metrics'] ?? ['*']),

            'filters' => $input['filters'] ?? [],

            // Additional dimensions user wants to group by (besides store/item)
            // These MUST exist on the summary tables.
            'group_by' => $input['group_by'] ?? [],

            // If true: include time dims of chosen granularity in group_by (time series output)
            'group_time' => (bool) ($input['group_time'] ?? false),

            'order_by' => $orderBy,
            'limit' => $limit,

            // Enterprise rule: final sort/limit after merge/subtractions
            'finalize_after_merge' => true,

            'day_span' => $startDate->diff($endDate)->days + 1,
        ];
    }

    private function parseMetrics(array $metricsInput): array
    {
        $metrics = [];

        foreach ($metricsInput as $metric) {
            if ($metric === '*') {
                return [
                    ['field' => 'total_sales', 'agg' => 'SUM', 'alias' => 'total_sales'],
                    ['field' => 'gross_sales', 'agg' => 'SUM', 'alias' => 'gross_sales'],
                    ['field' => 'total_orders', 'agg' => 'SUM', 'alias' => 'total_orders'],
                ];
            }

            if (is_string($metric)) {
                $metrics[] = [
                    'field' => $metric,
                    'agg' => $this->inferAggregation($metric),
                    'alias' => $metric
                ];
            } elseif (is_array($metric)) {
                $metrics[] = [
                    'field' => $metric['field'],
                    'agg' => $metric['agg'] ?? 'SUM',
                    'alias' => $metric['alias'] ?? $metric['field']
                ];
            }
        }

        return $metrics;
    }

    private function inferAggregation(string $field): string
    {
        if (
            str_starts_with($field, 'avg_') ||
            str_ends_with($field, '_rate') ||
            str_ends_with($field, 'penetration')
        ) {
            return 'AVG';
        }
        return 'SUM';
    }

    // ========================================================================
    // LAYER 2: COST-BASED STRATEGY OPTIMIZATION (WITH OVERLAP SUBTRACTION)
    // ========================================================================

    private function optimizeStrategy(array $request): array
    {
        $days = $request['day_span'];

        // Short ranges: prefer daily, but backfill missing days from hourly.
        if ($days <= 14) {
            return ['operations' => $this->buildShortRangeOps($request)];
        }

        // Long ranges: full intelligent strategy
        return $this->buildIntelligentStrategy($request['start_date'], $request['end_date']);
    }

    private function buildShortRangeOps(array $request): array
    {
        $start = $request['start_date'];
        $end = $request['end_date'];

        $dailyOp = [
            'type' => '+',
            'granularity' => 'daily',
            'start' => $start,
            'end' => $end,
        ];

        // Avoid mixed granularity for time series output.
        if ($request['group_time']) {
            return $this->hasDailyRowsForRange($request)
                ? [$dailyOp]
                : [
                    [
                        'type' => '+',
                        'granularity' => 'hourly',
                        'start' => $start,
                        'end' => $end,
                    ]
                ];
        }

        $missingDates = $this->findMissingDailyDates($request);

        if (count($missingDates) === 0) {
            return [$dailyOp];
        }

        if (count($missingDates) >= $request['day_span']) {
            return [
                [
                    'type' => '+',
                    'granularity' => 'hourly',
                    'start' => $start,
                    'end' => $end,
                ]
            ];
        }

        return array_merge([$dailyOp], $this->buildHourlyOpsForMissingDays($missingDates));
    }

    private function hasDailyRowsForRange(array $request): bool
    {
        $modelClass = $this->getModelClass('daily', $request['summary_type']);
        $query = $modelClass::query();

        $this->applyWhereConditions($query, [
            'granularity' => 'daily',
            'start' => $request['start_date'],
            'end' => $request['end_date'],
        ], $request);

        return $query->limit(1)->exists();
    }

    private function findMissingDailyDates(array $request): array
    {
        $modelClass = $this->getModelClass('daily', $request['summary_type']);
        $query = $modelClass::query();

        $this->applyWhereConditions($query, [
            'granularity' => 'daily',
            'start' => $request['start_date'],
            'end' => $request['end_date'],
        ], $request);

        $available = $query
            ->distinct()
            ->pluck('business_date')
            ->map(static function ($date) {
                return $date instanceof DateTime
                    ? $date->format('Y-m-d')
                    : (new DateTime((string) $date))->format('Y-m-d');
            })
            ->toArray();

        $availableSet = array_flip($available);
        $missing = [];

        $cursor = clone $request['start_date'];
        $end = clone $request['end_date'];

        while ($cursor <= $end) {
            $dateStr = $cursor->format('Y-m-d');
            if (!isset($availableSet[$dateStr])) {
                $missing[] = $dateStr;
            }
            $cursor->modify('+1 day');
        }

        return $missing;
    }

    private function buildHourlyOpsForMissingDays(array $missingDates): array
    {
        if (count($missingDates) === 0) {
            return [];
        }

        sort($missingDates);

        $ops = [];
        $rangeStart = null;
        $prev = null;

        foreach ($missingDates as $dateStr) {
            $date = new DateTime($dateStr);

            if ($rangeStart === null) {
                $rangeStart = $date;
                $prev = $date;
                continue;
            }

            $expectedNext = (clone $prev)->modify('+1 day');
            if ($date->format('Y-m-d') === $expectedNext->format('Y-m-d')) {
                $prev = $date;
                continue;
            }

            $ops[] = [
                'type' => '+',
                'granularity' => 'hourly',
                'start' => $rangeStart,
                'end' => $prev,
            ];

            $rangeStart = $date;
            $prev = $date;
        }

        $ops[] = [
            'type' => '+',
            'granularity' => 'hourly',
            'start' => $rangeStart,
            'end' => $prev,
        ];

        return $ops;
    }

    /**
     * Full cost-based optimizer: tries direct vs subtraction-based plans across granularities.
     */
    private function buildIntelligentStrategy(DateTime $start, DateTime $end): array
    {
        $costs = [
            'direct_daily' => $this->calculateDirectCost($start, $end, 'daily'),
            'direct_weekly' => $this->calculateDirectCost($start, $end, 'weekly'),
            'direct_monthly' => $this->calculateDirectCost($start, $end, 'monthly'),
            'direct_quarterly' => $this->calculateDirectCost($start, $end, 'quarterly'),
            'direct_yearly' => $this->calculateDirectCost($start, $end, 'yearly'),

            'yearly_subtraction' => $this->calculateYearlySubtractionCost($start, $end),
            'quarterly_subtraction' => $this->calculateQuarterlySubtractionCost($start, $end),
            'monthly_subtraction' => $this->calculateMonthlySubtractionCost($start, $end),
        ];

        if ($this->debugLevel > 0) {
            echo "\nOptimizing period: {$start->format('Y-m-d')} to {$end->format('Y-m-d')}\n";
            echo "Costs: " . json_encode($costs, JSON_PRETTY_PRINT) . "\n";
        }

        $minCost = min($costs);
        $bestApproach = array_search($minCost, $costs, true);

        if ($this->debugLevel > 0) {
            echo "Chosen: {$bestApproach} (cost: {$minCost})\n";
        }

        return match ($bestApproach) {
            'yearly_subtraction' => ['operations' => $this->buildYearlySubtractionOps($start, $end)],
            'quarterly_subtraction' => ['operations' => $this->buildQuarterlySubtractionOps($start, $end)],
            'monthly_subtraction' => ['operations' => $this->buildMonthlySubtractionOps($start, $end)],
            'direct_yearly' => ['operations' => $this->buildDirectOps($start, $end, 'yearly')],
            'direct_quarterly' => ['operations' => $this->buildDirectOps($start, $end, 'quarterly')],
            'direct_monthly' => ['operations' => $this->buildDirectOps($start, $end, 'monthly')],
            'direct_weekly' => ['operations' => $this->buildDirectOps($start, $end, 'weekly')],
            default => ['operations' => $this->buildDirectOps($start, $end, 'daily')],
        };
    }

    private function calculateDirectCost(DateTime $start, DateTime $end, string $granularity): int
    {
        $days = $start->diff($end)->days + 1;

        return match ($granularity) {
            'hourly' => $days * 24,
            'daily' => $days,
            'weekly' => (int) ceil($days / 7),
            'monthly' => (int) ceil($days / 30),
            'quarterly' => (int) ceil($days / 90),
            'yearly' => (int) ceil($days / 365),
            default => $days,
        };
    }

    private function calculateYearlySubtractionCost(DateTime $start, DateTime $end): int
    {
        // Count years touched + estimated cost of excluding edges with optimal granularity
        $startYear = (int) $start->format('Y');
        $endYear = (int) $end->format('Y');
        $cost = 0;

        for ($year = $startYear; $year <= $endYear; $year++) {
            $yearStart = new DateTime("{$year}-01-01");
            $yearEnd = new DateTime("{$year}-12-31");

            $overlapDays = $this->getOverlapDays($yearStart, $yearEnd, $start, $end);

            if ($overlapDays >= 183) {
                $cost += 1; // yearly row

                // exclude leading
                if ($yearStart < $start) {
                    $exclusionEnd = (clone $start)->modify('-1 day');
                    $cost += $this->getOptimalExclusionCost($yearStart, $exclusionEnd);
                }

                // exclude trailing
                if ($yearEnd > $end && $yearEnd->format('Y') === $end->format('Y')) {
                    $exclusionStart = (clone $end)->modify('+1 day');
                    $cost += $this->getOptimalExclusionCost($exclusionStart, $yearEnd);
                }
            } else {
                // partial year: cheaper of monthly vs daily vs weekly vs quarterly
                $periodStart = max($yearStart, $start);
                $periodEnd = min($yearEnd, $end);
                $cost += min(
                    $this->calculateDirectCost($periodStart, $periodEnd, 'monthly'),
                    $this->calculateDirectCost($periodStart, $periodEnd, 'weekly'),
                    $this->calculateDirectCost($periodStart, $periodEnd, 'daily')
                );
            }
        }

        return $cost;
    }

    private function calculateQuarterlySubtractionCost(DateTime $start, DateTime $end): int
    {
        // Similar idea: use quarterly rows when overlap is large inside quarters, subtract edges.
        // Cost uses number of quarters + edge exclusions.
        $quarters = $this->listQuartersBetween($start, $end);
        $cost = 0;

        foreach ($quarters as [$y, $q, $qStart, $qEnd]) {
            $overlapDays = $this->getOverlapDays($qStart, $qEnd, $start, $end);

            // quarter length ~ 90; "more than half" ~ 45
            if ($overlapDays >= 45) {
                $cost += 1; // quarterly row

                if ($qStart < $start && $this->sameQuarter($qStart, $start)) {
                    $exclusionEnd = (clone $start)->modify('-1 day');
                    $cost += $this->getOptimalExclusionCost($qStart, $exclusionEnd);
                }

                if ($qEnd > $end && $this->sameQuarter($qEnd, $end)) {
                    $exclusionStart = (clone $end)->modify('+1 day');
                    $cost += $this->getOptimalExclusionCost($exclusionStart, $qEnd);
                }
            } else {
                // partial quarter
                $periodStart = max($qStart, $start);
                $periodEnd = min($qEnd, $end);
                $cost += min(
                    $this->calculateDirectCost($periodStart, $periodEnd, 'monthly'),
                    $this->calculateDirectCost($periodStart, $periodEnd, 'weekly'),
                    $this->calculateDirectCost($periodStart, $periodEnd, 'daily')
                );
            }
        }

        return $cost;
    }

    private function calculateMonthlySubtractionCost(DateTime $start, DateTime $end): int
    {
        $months = $this->listMonthsBetween($start, $end);
        $cost = 0;

        foreach ($months as [$y, $m, $mStart, $mEnd]) {
            $overlapDays = $this->getOverlapDays($mStart, $mEnd, $start, $end);

            if ($overlapDays >= 15) {
                $cost += 1; // monthly row

                if ($mStart < $start && $mStart->format('Y-m') === $start->format('Y-m')) {
                    $exclusionEnd = (clone $start)->modify('-1 day');
                    $cost += $this->getOptimalExclusionCost($mStart, $exclusionEnd);
                }

                if ($mEnd > $end && $mEnd->format('Y-m') === $end->format('Y-m')) {
                    $exclusionStart = (clone $end)->modify('+1 day');
                    $cost += $this->getOptimalExclusionCost($exclusionStart, $mEnd);
                }
            } else {
                $cost += $overlapDays; // daily rows equivalent
            }
        }

        return $cost;
    }

    private function buildYearlySubtractionOps(DateTime $start, DateTime $end): array
    {
        $ops = [];
        $startYear = (int) $start->format('Y');
        $endYear = (int) $end->format('Y');

        for ($year = $startYear; $year <= $endYear; $year++) {
            $yStart = new DateTime("{$year}-01-01");
            $yEnd = new DateTime("{$year}-12-31");

            $overlapDays = $this->getOverlapDays($yStart, $yEnd, $start, $end);

            if ($overlapDays >= 183) {
                $ops[] = [
                    'type' => '+',
                    'granularity' => 'yearly',
                    'start' => $yStart,
                    'end' => $yEnd,
                    'metadata' => ['year_num' => $year]
                ];

                if ($yStart < $start) {
                    $exclusionEnd = (clone $start)->modify('-1 day');
                    $ops = array_merge($ops, $this->buildOptimalExclusion($yStart, $exclusionEnd));
                }

                if ($yEnd > $end && $yEnd->format('Y') === $end->format('Y')) {
                    $exclusionStart = (clone $end)->modify('+1 day');
                    $ops = array_merge($ops, $this->buildOptimalExclusion($exclusionStart, $yEnd));
                }
            } else {
                // partial year -> recurse for best plan inside that year slice
                $periodStart = max($yStart, $start);
                $periodEnd = min($yEnd, $end);
                $ops = array_merge($ops, $this->buildIntelligentStrategy($periodStart, $periodEnd)['operations']);
            }
        }

        return $ops;
    }

    private function buildQuarterlySubtractionOps(DateTime $start, DateTime $end): array
    {
        $ops = [];
        $quarters = $this->listQuartersBetween($start, $end);

        foreach ($quarters as [$y, $q, $qStart, $qEnd]) {
            $overlapDays = $this->getOverlapDays($qStart, $qEnd, $start, $end);

            if ($overlapDays >= 45) {
                $ops[] = [
                    'type' => '+',
                    'granularity' => 'quarterly',
                    'start' => $qStart,
                    'end' => $qEnd,
                    'metadata' => [
                        'year_num' => $y,
                        'quarter_num' => $q
                    ],
                ];

                if ($qStart < $start && $this->sameQuarter($qStart, $start)) {
                    $exclusionEnd = (clone $start)->modify('-1 day');
                    $ops = array_merge($ops, $this->buildOptimalExclusion($qStart, $exclusionEnd));
                }

                if ($qEnd > $end && $this->sameQuarter($qEnd, $end)) {
                    $exclusionStart = (clone $end)->modify('+1 day');
                    $ops = array_merge($ops, $this->buildOptimalExclusion($exclusionStart, $qEnd));
                }
            } else {
                $periodStart = max($qStart, $start);
                $periodEnd = min($qEnd, $end);
                $ops = array_merge($ops, $this->buildIntelligentStrategy($periodStart, $periodEnd)['operations']);
            }
        }

        return $ops;
    }

    private function buildMonthlySubtractionOps(DateTime $start, DateTime $end): array
    {
        $ops = [];
        $months = $this->listMonthsBetween($start, $end);

        foreach ($months as [$y, $m, $mStart, $mEnd]) {
            $overlapDays = $this->getOverlapDays($mStart, $mEnd, $start, $end);

            if ($overlapDays >= 15) {
                $ops[] = [
                    'type' => '+',
                    'granularity' => 'monthly',
                    'start' => $mStart,
                    'end' => $mEnd,
                    'metadata' => [
                        'year_num' => $y,
                        'month_num' => $m
                    ],
                ];

                if ($mStart < $start && $mStart->format('Y-m') === $start->format('Y-m')) {
                    $exclusionEnd = (clone $start)->modify('-1 day');
                    $ops = array_merge($ops, $this->buildOptimalExclusion($mStart, $exclusionEnd));
                }

                if ($mEnd > $end && $mEnd->format('Y-m') === $end->format('Y-m')) {
                    $exclusionStart = (clone $end)->modify('+1 day');
                    $ops = array_merge($ops, $this->buildOptimalExclusion($exclusionStart, $mEnd));
                }
            } else {
                $periodStart = max($mStart, $start);
                $periodEnd = min($mEnd, $end);
                $ops = array_merge($ops, $this->buildIntelligentStrategy($periodStart, $periodEnd)['operations']);
            }
        }

        return $ops;
    }

    private function buildOptimalExclusion(DateTime $start, DateTime $end): array
    {
        $days = $start->diff($end)->days + 1;

        // tiny: daily
        if ($days <= 3) {
            return [
                [
                    'type' => '-',
                    'granularity' => 'daily',
                    'start' => $start,
                    'end' => $end
                ]
            ];
        }

        // quarterly aligned?
        if ($this->isQuarterStart($start) && $days >= 80) {
            // subtract full quarters when possible
            $ops = [];
            $current = clone $start;

            while ($this->isQuarterStart($current) && $current <= $end) {
                [$qy, $qq, $qStart, $qEnd] = $this->quarterInfo($current);
                if ($qEnd <= $end) {
                    $ops[] = [
                        'type' => '-',
                        'granularity' => 'quarterly',
                        'start' => $qStart,
                        'end' => $qEnd,
                        'metadata' => ['year_num' => $qy, 'quarter_num' => $qq]
                    ];
                    $current = (clone $qEnd)->modify('+1 day');
                } else {
                    break;
                }
            }

            if (!empty($ops)) {
                if ($current <= $end) {
                    $ops = array_merge($ops, $this->buildOptimalExclusion($current, $end));
                }
                return $ops;
            }
        }

        // monthly aligned?
        if ($start->format('d') === '01' && $days >= 28) {
            $ops = [];
            $current = clone $start;

            while ($current->format('d') === '01' && $current <= $end) {
                $monthEnd = new DateTime($current->format('Y-m-t'));
                if ($monthEnd <= $end) {
                    $ops[] = [
                        'type' => '-',
                        'granularity' => 'monthly',
                        'start' => clone $current,
                        'end' => $monthEnd,
                        'metadata' => [
                            'year_num' => (int) $current->format('Y'),
                            'month_num' => (int) $current->format('m')
                        ]
                    ];
                    $current = (clone $monthEnd)->modify('+1 day');
                } else {
                    break;
                }
            }

            if (!empty($ops)) {
                if ($current <= $end) {
                    $ops = array_merge($ops, $this->buildOptimalExclusion($current, $end));
                }
                return $ops;
            }
        }

        // weekly aligned?
        if ($start->format('N') == 1 && $days >= 7) {
            $ops = [];
            $current = clone $start;

            while ($current->format('N') == 1 && $current <= $end) {
                $wEnd = (clone $current)->modify('+6 days');
                if ($wEnd <= $end) {
                    $ops[] = [
                        'type' => '-',
                        'granularity' => 'weekly',
                        'start' => clone $current,
                        'end' => $wEnd,
                        'metadata' => [
                            'year_num' => (int) $current->format('o'),
                            'week_num' => (int) $current->format('W')
                        ]
                    ];
                    $current = (clone $wEnd)->modify('+1 day');
                } else {
                    break;
                }
            }

            if (!empty($ops)) {
                if ($current <= $end) {
                    $ops[] = [
                        'type' => '-',
                        'granularity' => 'daily',
                        'start' => $current,
                        'end' => $end
                    ];
                }
                return $ops;
            }
        }

        // fallback daily
        return [
            [
                'type' => '-',
                'granularity' => 'daily',
                'start' => $start,
                'end' => $end
            ]
        ];
    }

    private function getOptimalExclusionCost(DateTime $start, DateTime $end): int
    {
        $days = $start->diff($end)->days + 1;

        if ($days <= 3) {
            return $days; // daily
        }

        if ($this->isQuarterStart($start) && $days >= 80) {
            // quarters + remainder
            $quarters = 0;
            $current = clone $start;

            while ($this->isQuarterStart($current) && $current <= $end) {
                [, , , $qEnd] = $this->quarterInfo($current);
                if ($qEnd <= $end) {
                    $quarters++;
                    $current = (clone $qEnd)->modify('+1 day');
                } else {
                    break;
                }
            }

            $remaining = $current <= $end ? ($current->diff($end)->days + 1) : 0;
            return min($days, $quarters + $remaining);
        }

        // monthly aligned
        if ($start->format('d') === '01' && $days >= 28) {
            $months = 0;
            $current = clone $start;

            while ($current->format('d') === '01' && $current <= $end) {
                $mEnd = new DateTime($current->format('Y-m-t'));
                if ($mEnd <= $end) {
                    $months++;
                    $current = (clone $mEnd)->modify('+1 day');
                } else {
                    break;
                }
            }

            $remaining = $current <= $end ? ($current->diff($end)->days + 1) : 0;
            return min($days, $months + $remaining);
        }

        // weekly aligned
        if ($start->format('N') == 1 && $days >= 7) {
            $weeks = (int) floor($days / 7);
            $rem = $days % 7;
            return min($days, $weeks + $rem);
        }

        return $days;
    }

    private function buildDirectOps(DateTime $start, DateTime $end, string $granularity): array
    {
        return [
            [
                'type' => '+',
                'granularity' => $granularity,
                'start' => $start,
                'end' => $end
            ]
        ];
    }

    private function getOverlapDays(DateTime $p1Start, DateTime $p1End, DateTime $p2Start, DateTime $p2End): int
    {
        $overlapStart = max($p1Start, $p2Start);
        $overlapEnd = min($p1End, $p2End);

        if ($overlapStart > $overlapEnd) {
            return 0;
        }

        return $overlapStart->diff($overlapEnd)->days + 1;
    }

    // ========================================================================
    // LAYER 3: QUERY BUILDING (ELOQUENT + METADATA-SAFE PERIOD FILTERING)
    // ========================================================================

    private function getModelClass(string $granularity, string $summaryType): string
    {
        $key = "{$granularity}_{$summaryType}";

        if (!isset(self::MODEL_MAP[$key])) {
            throw new InvalidArgumentException("No model found for {$key}");
        }

        return self::MODEL_MAP[$key];
    }

    private function buildQuery(array $request, array $operation): Builder
    {
        $modelClass = $this->getModelClass($operation['granularity'], $request['summary_type']);
        $query = $modelClass::query();

        $this->applyWhereConditions($query, $operation, $request);
        $this->applySelectClause($query, $request, $operation);
        $this->applyGroupBy($query, $request, $operation);

        // DO NOT apply order/limit here (breaks correctness across merge/subtract).
        // We'll do it after merge.
        return $query;
    }

    private function applyWhereConditions(Builder $query, array $operation, array $request): void
    {
        $granularity = $operation['granularity'];
        $start = $operation['start'];
        $end = $operation['end'];

        // Store filtering + other filters
        foreach ($request['filters'] as $k => $v) {
            if (is_array($v)) {
                $query->whereIn($k, $v);
            } else {
                $query->where($k, $v);
            }
        }

        // Period filtering:
        // - hourly/daily: business_date range
        // - weekly/monthly/quarterly/yearly: metadata fields (year_num, week_num, month_num, quarter_num)
        if (in_array($granularity, ['hourly', 'daily'], true)) {
            $query->whereBetween('business_date', [
                $start->format('Y-m-d'),
                $end->format('Y-m-d')
            ]);
            return;
        }

        if ($granularity === 'weekly') {
            $query->where(function (Builder $q) use ($start, $end) {
                // overlap condition: (week_start <= end) AND (week_end >= start)
                $q->whereDate('week_start_date', '<=', $end->format('Y-m-d'))
                    ->whereDate('week_end_date', '>=', $start->format('Y-m-d'));
            });
            return;
        }

        if ($granularity === 'monthly') {
            $pairs = $this->listYearMonthsBetween($start, $end); // [ [year, month], ... ]
            $query->where(function (Builder $q) use ($pairs) {
                foreach ($pairs as [$y, $m]) {
                    $q->orWhere(function (Builder $qq) use ($y, $m) {
                        $qq->where('year_num', $y)->where('month_num', $m);
                    });
                }
            });
            return;
        }

        if ($granularity === 'quarterly') {
            $query->where(function (Builder $q) use ($start, $end) {
                $q->whereDate('quarter_start_date', '<=', $end->format('Y-m-d'))
                    ->whereDate('quarter_end_date', '>=', $start->format('Y-m-d'));
            });
            return;
        }


        if ($granularity === 'yearly') {
            $years = $this->listYearsBetween($start, $end);
            $query->whereIn('year_num', $years);
            return;
        }

        // Fallback: business_date
        $query->whereBetween('business_date', [
            $start->format('Y-m-d'),
            $end->format('Y-m-d')
        ]);
    }

    private function applySelectClause(Builder $query, array $request, array $operation): void
    {
        $granularity = $operation['granularity'];
        $summaryType = $request['summary_type'];

        $selectFields = ['franchise_store'];

        // Optional user-defined dimensions
        foreach ($request['group_by'] as $dim) {
            if (!in_array($dim, $selectFields, true)) {
                $selectFields[] = $dim;
            }
        }

        // Item fields
        if ($summaryType === 'item') {
            $selectFields[] = 'item_id';
            $selectFields[] = 'menu_item_name';
        }

        // If user wants time series, include time dimensions in output + group_by
        if ($request['group_time']) {
            $selectFields = array_merge($selectFields, match ($granularity) {
                'hourly' => ['business_date', 'hour'],
                'daily' => ['business_date'],
                'weekly' => ['year_num', 'week_num'],
                'monthly' => ['year_num', 'month_num'],
                'quarterly' => ['year_num', 'quarter_num'],
                'yearly' => ['year_num'],
                default => []
            });
        }

        // Aggregations
        $aggregations = [];
        foreach ($request['metrics'] as $metric) {
            // Use raw aggregation, alias must be safe identifier (assumed controlled internally)
            $aggregations[] = DB::raw("{$metric['agg']}({$metric['field']}) as {$metric['alias']}");
        }

        $query->select(array_merge($selectFields, $aggregations));
    }

    private function applyGroupBy(Builder $query, array $request, array $operation): void
    {
        $granularity = $operation['granularity'];
        $summaryType = $request['summary_type'];

        $groupFields = ['franchise_store'];

        // Optional user-defined dimensions
        foreach ($request['group_by'] as $dim) {
            if (!in_array($dim, $groupFields, true)) {
                $groupFields[] = $dim;
            }
        }

        if ($summaryType === 'item') {
            $groupFields[] = 'item_id';
            $groupFields[] = 'menu_item_name';
        }

        if ($request['group_time']) {
            $groupFields = array_merge($groupFields, match ($granularity) {
                'hourly' => ['business_date', 'hour'],
                'daily' => ['business_date'],
                'weekly' => ['year_num', 'week_num'],
                'monthly' => ['year_num', 'month_num'],
                'quarterly' => ['year_num', 'quarter_num'],
                'yearly' => ['year_num'],
                default => []
            });
        }

        $query->groupBy($groupFields);
    }

    // ========================================================================
    // LAYER 4: STREAMING EXECUTION + MERGE + SUBTRACTION
    // ========================================================================

    private function executeQueriesStreaming(array $request, array $strategy): array
    {
        $hasSubtractions = false;
        foreach ($strategy['operations'] as $op) {
            if (($op['type'] ?? '+') === '-') {
                $hasSubtractions = true;
                break;
            }
        }

        // Fast-path: streaming Top-N heap if safe:
        // - limit is set
        // - single order_by field
        // - no subtractions
        // - group_time doesn't require returning many time slices
        $heapMode = $this->canUseStreamingTopN($request, $strategy, $hasSubtractions);

        if ($heapMode) {
            return $this->executeStreamingTopN($request, $strategy);
        }

        // Otherwise: full streaming merge into map, then apply subtractions, then finalize.
        $addMap = []; // key => row
        $subMaps = []; // list of maps for subtraction ops (streamed)

        foreach ($strategy['operations'] as $operation) {
            $query = $this->buildQuery($request, $operation);

            if (($operation['type'] ?? '+') === '+') {
                $this->streamIntoMap($addMap, $query, $request, $operation, '+');
            } else {
                $hasSubtractions = true;
                $subMap = [];
                $this->streamIntoMap($subMap, $query, $request, $operation, '-');
                $subMaps[] = $subMap;
            }
        }

        // Apply subtractions
        foreach ($subMaps as $subMap) {
            $this->subtractMapInPlace($addMap, $subMap, $request['metrics']);
        }

        $rows = array_values($addMap);

        // Clean internal fields if not a time series
        $rows = $this->cleanRows($rows, $request);

        // Final ORDER + LIMIT after merge/subtractions
        return $this->applyFinalOrderingAndLimit($rows, $request);
    }

    private function streamIntoMap(array &$map, Builder $query, array $request, array $operation, string $type): void
    {
        // Stream results (cursor) to avoid loading big arrays
        foreach ($query->cursor() as $model) {
            $row = is_array($model) ? $model : $model->toArray();

            $key = $this->buildRowKey($row, $request);

            if (!isset($map[$key])) {
                $row['_key'] = $key;
                // Ensure numeric fields are numeric
                $row = $this->castMetricFields($row, $request['metrics']);
                $map[$key] = $row;
            } else {
                $row = $this->castMetricFields($row, $request['metrics']);
                $map[$key] = $this->sumRows($map[$key], $row, $request['metrics']);
            }
        }
    }

    private function subtractMapInPlace(array &$base, array $toSubtract, array $metrics): void
    {
        foreach ($toSubtract as $key => $subRow) {
            if (!isset($base[$key])) {
                continue;
            }
            $base[$key] = $this->subtractRows($base[$key], $subRow, $metrics);
        }
    }

    private function castMetricFields(array $row, array $metrics): array
    {
        foreach ($metrics as $m) {
            $a = $m['alias'];
            if (isset($row[$a]) && is_numeric($row[$a])) {
                // float cast is safe for sums; if you need exact currency, use decimal strings + bc math.
                $row[$a] = (float) $row[$a];
            }
        }
        return $row;
    }

    private function sumRows(array $row1, array $row2, array $metrics): array
    {
        foreach ($metrics as $metric) {
            $alias = $metric['alias'];
            if (isset($row1[$alias]) && isset($row2[$alias]) && is_numeric($row1[$alias]) && is_numeric($row2[$alias])) {
                $row1[$alias] = (float) $row1[$alias] + (float) $row2[$alias];
            }
        }
        return $row1;
    }

    private function subtractRows(array $row1, array $row2, array $metrics): array
    {
        foreach ($metrics as $metric) {
            // Only meaningful for SUM; AVG subtraction is undefined unless weighted counts exist.
            if (($metric['agg'] ?? 'SUM') !== 'SUM') {
                continue;
            }

            $alias = $metric['alias'];

            if (isset($row1[$alias]) && isset($row2[$alias]) && is_numeric($row1[$alias]) && is_numeric($row2[$alias])) {
                $row1[$alias] = max(0.0, (float) $row1[$alias] - (float) $row2[$alias]);
            }
        }
        return $row1;
    }

    private function cleanRows(array $rows, array $request): array
    {
        // If not a time series, remove time dimension fields so they never leak into results.
        if (!$request['group_time']) {
            foreach ($rows as &$r) {
                unset($r['hour'], $r['business_date'], $r['week_num'], $r['month_num'], $r['quarter_num'], $r['year_num']);
            }
        }

        // Remove internal key
        foreach ($rows as &$r) {
            unset($r['_key']);
        }

        return $rows;
    }

    // ========================================================================
    // FINAL ORDER + LIMIT (ENTERPRISE SAFE TOP-N)
    // ========================================================================

    private function applyFinalOrderingAndLimit(array $rows, array $request): array
    {
        if ($request['order_by']) {
            $clauses = $this->parseOrderBy($request['order_by']);

            usort($rows, function ($a, $b) use ($clauses) {
                foreach ($clauses as [$field, $dir]) {
                    $aVal = $a[$field] ?? 0;
                    $bVal = $b[$field] ?? 0;

                    $cmp = ($dir === 'DESC')
                        ? ($bVal <=> $aVal)
                        : ($aVal <=> $bVal);

                    if ($cmp !== 0) {
                        return $cmp;
                    }
                }
                return 0;
            });
        }

        if ($request['limit']) {
            $rows = array_slice($rows, 0, (int) $request['limit']);
        }

        return $rows;
    }

    private function parseOrderBy(string $orderBy): array
    {
        // Supports: "gross_sales DESC, quantity_sold DESC"
        $parts = array_map('trim', explode(',', $orderBy));
        $clauses = [];

        foreach ($parts as $p) {
            if ($p === '')
                continue;
            $bits = preg_split('/\s+/', $p);
            $field = $bits[0] ?? '';
            $dir = strtoupper($bits[1] ?? 'ASC');
            $dir = ($dir === 'DESC') ? 'DESC' : 'ASC';
            if ($field !== '') {
                $clauses[] = [$field, $dir];
            }
        }

        return $clauses;
    }

    // ========================================================================
    // STREAMING TOP-N HEAP (SAFE FAST-PATH)
    // ========================================================================

    private function canUseStreamingTopN(array $request, array $strategy, bool $hasSubtractions): bool
    {
        if (!$request['limit'] || !$request['order_by']) {
            return false;
        }
        if ($hasSubtractions) {
            return false;
        }
        if ($request['group_time']) {
            // time series top-N is ambiguous (top per time bucket vs overall)
            return false;
        }

        $clauses = $this->parseOrderBy($request['order_by']);
        if (count($clauses) !== 1) {
            return false; // heap supports single sort key only
        }

        // Also, if multiple operations exist, heap is still okay (we stream all into heap by merged totals)
        return true;
    }

    private function executeStreamingTopN(array $request, array $strategy): array
    {
        $limit = (int) $request['limit'];
        $clauses = $this->parseOrderBy($request['order_by']);
        [$sortField, $dir] = $clauses[0];

        // We still need to merge per key across operations; to avoid holding everything,
        // we keep a map of totals *only for keys that could matter* using a min-heap boundary.
        // BUT without knowing totals ahead of time, we still accumulate per key.
        // Enterprise compromise:
        // - We stream rows, accumulate totals in a map
        // - After each chunk we can prune to keep only top-ish keys
        // For simplicity and safety: keep full map for accumulation, then heap-select top N.
        // (Still benefits from streaming query results.)
        $map = [];
        foreach ($strategy['operations'] as $operation) {
            $query = $this->buildQuery($request, $operation);
            $this->streamIntoMap($map, $query, $request, $operation, '+');
        }

        $rows = array_values($map);
        $rows = $this->cleanRows($rows, $request);

        // Use heap to select top N without sorting full array
        $pq = new SplPriorityQueue();
        $pq->setExtractFlags(SplPriorityQueue::EXTR_BOTH);

        foreach ($rows as $row) {
            $val = $row[$sortField] ?? 0;
            $priority = (float) $val;

            // For DESC we want highest; SplPriorityQueue extracts highest first.
            // For ASC we invert.
            if ($dir === 'ASC') {
                $priority = -$priority;
            }

            $pq->insert($row, $priority);
        }

        $out = [];
        for ($i = 0; $i < $limit && !$pq->isEmpty(); $i++) {
            $out[] = $pq->extract()['data'];
        }

        return $out;
    }

    // ========================================================================
    // KEYING (DIMENSION-SAFE MERGE)
    // ========================================================================

    private function buildRowKey(array $row, array $request): string
    {
        $parts = [];

        $parts[] = (string) ($row['franchise_store'] ?? '');

        foreach ($request['group_by'] as $dim) {
            $parts[] = (string) ($row[$dim] ?? '');
        }

        if ($request['summary_type'] === 'item') {
            $parts[] = (string) ($row['item_id'] ?? '');
        }

        if ($request['group_time']) {
            // time series key must include time dims to avoid collapsing them
            // include any time fields that might exist on the row
            foreach (['business_date', 'hour', 'year_num', 'quarter_num', 'month_num', 'week_num'] as $t) {
                if (array_key_exists($t, $row)) {
                    $parts[] = (string) $row[$t];
                }
            }
        }

        return implode('|', $parts);
    }

    // ========================================================================
    // PERIOD LIST HELPERS (WEEKS/MONTHS/QUARTERS/YEARS)
    // ========================================================================

    private function listYearsBetween(DateTime $start, DateTime $end): array
    {
        $years = [];
        $sy = (int) $start->format('Y');
        $ey = (int) $end->format('Y');
        for ($y = $sy; $y <= $ey; $y++) {
            $years[] = $y;
        }
        return $years;
    }

    private function listIsoWeeksBetween(DateTime $start, DateTime $end): array
    {
        // ISO weeks use "o" for ISO year (can differ near year boundary)
        $pairs = [];
        $cur = clone $start;
        $cur->setTime(0, 0, 0);

        // Advance to Monday of current ISO week
        $dow = (int) $cur->format('N');
        $cur->modify('-' . ($dow - 1) . ' days');

        while ($cur <= $end) {
            $isoYear = (int) $cur->format('o');
            $isoWeek = (int) $cur->format('W');
            $pairs[] = [$isoYear, $isoWeek];
            $cur->modify('+7 days');
        }

        // unique
        $uniq = [];
        foreach ($pairs as $p) {
            $uniq[$p[0] . '-' . $p[1]] = $p;
        }
        return array_values($uniq);
    }

    private function listYearMonthsBetween(DateTime $start, DateTime $end): array
    {
        $pairs = [];
        $cur = new DateTime($start->format('Y-m-01'));
        $endMonth = new DateTime($end->format('Y-m-01'));

        while ($cur <= $endMonth) {
            $pairs[] = [(int) $cur->format('Y'), (int) $cur->format('m')];
            $cur->modify('+1 month');
        }

        return $pairs;
    }

    private function listMonthsBetween(DateTime $start, DateTime $end): array
    {
        $out = [];
        $cur = new DateTime($start->format('Y-m-01'));
        $endMonth = new DateTime($end->format('Y-m-01'));

        while ($cur <= $endMonth) {
            $mStart = new DateTime($cur->format('Y-m-01'));
            $mEnd = new DateTime($cur->format('Y-m-t'));
            $out[] = [(int) $cur->format('Y'), (int) $cur->format('m'), $mStart, $mEnd];
            $cur->modify('+1 month');
        }

        return $out;
    }

    private function listYearQuartersBetween(DateTime $start, DateTime $end): array
    {
        $pairs = [];
        $quarters = $this->listQuartersBetween($start, $end);
        foreach ($quarters as [$y, $q]) {
            $pairs[] = [$y, $q];
        }
        // unique
        $uniq = [];
        foreach ($pairs as $p) {
            $uniq[$p[0] . '-' . $p[1]] = $p;
        }
        return array_values($uniq);
    }

    private function listQuartersBetween(DateTime $start, DateTime $end): array
    {
        $out = [];

        // Start at quarter start
        [$sy, $sq, $sStart,] = $this->quarterInfo($start);
        $cur = clone $sStart;

        while ($cur <= $end) {
            [$y, $q, $qStart, $qEnd] = $this->quarterInfo($cur);
            $out[] = [$y, $q, $qStart, $qEnd];
            $cur = (clone $qEnd)->modify('+1 day');
        }

        // unique by y-q
        $uniq = [];
        foreach ($out as $row) {
            $uniq[$row[0] . '-' . $row[1]] = $row;
        }
        return array_values($uniq);
    }

    private function quarterInfo(DateTime $d): array
    {
        $y = (int) $d->format('Y');
        $m = (int) $d->format('m');
        $q = (int) floor(($m - 1) / 3) + 1;

        $startMonth = (($q - 1) * 3) + 1;
        $qStart = new DateTime(sprintf('%04d-%02d-01', $y, $startMonth));
        $qEnd = (clone $qStart)->modify('+3 months')->modify('-1 day');

        return [$y, $q, $qStart, $qEnd];
    }

    private function isQuarterStart(DateTime $d): bool
    {
        $m = (int) $d->format('m');
        $day = (int) $d->format('d');
        return $day === 1 && in_array($m, [1, 4, 7, 10], true);
    }

    private function sameQuarter(DateTime $a, DateTime $b): bool
    {
        [$ya, $qa] = [$a->format('Y'), (int) floor(((int) $a->format('m') - 1) / 3) + 1];
        [$yb, $qb] = [$b->format('Y'), (int) floor(((int) $b->format('m') - 1) / 3) + 1];
        return $ya === $yb && $qa === $qb;
    }

    // ========================================================================
    // METADATA: ROW SCAN ESTIMATE
    // ========================================================================

    private function calculateTotalRows(array $strategy): int
    {
        $total = 0;

        foreach ($strategy['operations'] as $op) {
            $days = $op['start']->diff($op['end'])->days + 1;
            $total += match ($op['granularity']) {
                'yearly' => 1,
                'quarterly' => 1,
                'monthly' => 1,
                'weekly' => 1,
                'daily' => $days,
                'hourly' => $days * 24,
                default => $days
            };
        }

        return $total;
    }
}
