<?php

namespace App\Jobs;

use App\Services\Aggregation\AggregationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RunAggregationRebuildJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 21600;
    public $tries = 1;

    protected array $stageOrder = [
        'hourly' => 1,
        'daily' => 2,
        'weekly' => 3,
        'monthly' => 4,
        'quarterly' => 5,
        'yearly' => 6,
    ];

    public function __construct(
        protected string $aggregationId,
        protected string $startDate,
        protected string $endDate,
        protected string $type = 'all',
        protected ?array $stages = null
    ) {
    }

    public function handle(AggregationService $service): void
    {
        ini_set('memory_limit', '2G');

        $lock = Cache::lock('aggregation_rebuild_lock', 60 * 60 * 12);

        if (!$lock->get()) {
            $this->updateProgress('failed', [
                'message' => 'Another aggregation rebuild is already running.',
                'updated_at' => now()->toISOString(),
            ]);
            return;
        }

        try {
            $start = Carbon::parse($this->startDate)->startOfDay();
            $end = Carbon::parse($this->endDate)->startOfDay();

            $stages = $this->normalizeStageOrder($this->stages ?? $this->stagesFromType($this->type));
            $units = $this->buildExecutionPlan($start, $end, $stages);

            $processed = 0;
            $successful = 0;
            $failed = 0;
            $failedUnits = [];

            $this->updateProgress('processing', [
                'type' => $this->type,
                'stages' => $stages,
                'total' => count($units),
                'processed' => 0,
                'successful' => 0,
                'failed' => 0,
                'current_date' => null,
                'current_unit' => null,
                'failed_units' => [],
                'started_at' => now()->toISOString(),
            ]);

            foreach ($units as $unit) {
                $this->updateProgress('processing', [
                    'total' => count($units),
                    'processed' => $processed,
                    'successful' => $successful,
                    'failed' => $failed,
                    'current_date' => $this->unitDateForProgress($unit),
                    'current_unit' => $unit,
                ]);

                try {
                    $this->runUnit($service, $unit);
                    $successful++;
                } catch (\Throwable $e) {
                    $failed++;

                    $failedUnit = [
                        'stage' => $unit['stage'],
                        'label' => $unit['label'],
                        'error' => $e->getMessage(),
                        'failed_at' => now()->toISOString(),
                    ];

                    $failedUnits[] = $failedUnit;

                    Log::error('Aggregation rebuild unit failed', [
                        'aggregation_id' => $this->aggregationId,
                        'type' => $this->type,
                        'stages' => $stages,
                        'unit' => $unit,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }

                $processed++;

                $this->updateProgress('processing', [
                    'total' => count($units),
                    'processed' => $processed,
                    'successful' => $successful,
                    'failed' => $failed,
                    'current_date' => $processed < count($units)
                        ? $this->unitDateForProgress($units[$processed])
                        : null,
                    'current_unit' => $processed < count($units) ? $units[$processed] : null,
                    'failed_units' => array_slice($failedUnits, -50),
                ]);
            }

            $this->updateProgress('completed', [
                'type' => $this->type,
                'stages' => $stages,
                'total' => count($units),
                'processed' => $processed,
                'successful' => $successful,
                'failed' => $failed,
                'current_date' => null,
                'current_unit' => null,
                'failed_units' => array_slice($failedUnits, -50),
                'completed_at' => now()->toISOString(),
            ]);
        } finally {
            optional($lock)->release();
        }
    }

    protected function stagesFromType(string $type): array
    {
        $type = trim(strtolower($type));

        return match ($type) {
            'hourly' => ['hourly'],
            'daily' => ['hourly', 'daily'],
            'weekly' => ['hourly', 'daily', 'weekly'],
            'monthly' => ['hourly', 'daily', 'weekly', 'monthly'],
            'quarterly' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly'],
            'yearly' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
            'all', '' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
            default => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
        };
    }

    protected function normalizeStageOrder(array $stages): array
    {
        usort($stages, fn(string $a, string $b) => $this->stageOrder[$a] <=> $this->stageOrder[$b]);
        return array_values(array_unique($stages));
    }

    protected function buildExecutionPlan(Carbon $start, Carbon $end, array $stages): array
    {
        $units = [];

        foreach ($stages as $stage) {
            $units = array_merge($units, match ($stage) {
                'hourly' => $this->buildDailyUnits($start, $end, 'hourly'),
                'daily' => $this->buildDailyUnits($start, $end, 'daily'),
                'weekly' => $this->buildWeeklyUnits($start, $end),
                'monthly' => $this->buildMonthlyUnits($start, $end),
                'quarterly' => $this->buildQuarterlyUnits($start, $end),
                'yearly' => $this->buildYearlyUnits($start, $end),
                default => [],
            });
        }

        return $units;
    }

    protected function buildDailyUnits(Carbon $start, Carbon $end, string $stage): array
    {
        $units = [];
        $current = $start->copy();

        while ($current <= $end) {
            $units[] = [
                'stage' => $stage,
                'date' => $current->toDateString(),
                'label' => "{$stage}:{$current->toDateString()}",
            ];
            $current->addDay();
        }

        return $units;
    }

    protected function buildWeeklyUnits(Carbon $start, Carbon $end): array
    {
        $units = [];
        $seen = [];

        $current = $start->copy();
        while ($current <= $end) {
            $weekStart = $current->copy()->startOfWeek(Carbon::TUESDAY);
            $weekEnd = $current->copy()->endOfWeek(Carbon::MONDAY);
            $key = $weekStart->toDateString() . '_' . $weekEnd->toDateString();

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $units[] = [
                    'stage' => 'weekly',
                    'start_date' => $weekStart->toDateString(),
                    'end_date' => $weekEnd->toDateString(),
                    'label' => "weekly:{$weekStart->toDateString()}->{$weekEnd->toDateString()}",
                ];
            }

            $current->addDay();
        }

        return $units;
    }

    protected function buildMonthlyUnits(Carbon $start, Carbon $end): array
    {
        $units = [];
        $cursor = $start->copy()->startOfMonth();
        $last = $end->copy()->startOfMonth();

        while ($cursor <= $last) {
            $units[] = [
                'stage' => 'monthly',
                'year' => (int) $cursor->year,
                'month' => (int) $cursor->month,
                'label' => 'monthly:' . $cursor->format('Y-m'),
            ];

            $cursor->addMonth();
        }

        return $units;
    }

    protected function buildQuarterlyUnits(Carbon $start, Carbon $end): array
    {
        $units = [];
        $cursor = $start->copy()->startOfQuarter();
        $last = $end->copy()->startOfQuarter();

        while ($cursor <= $last) {
            $quarter = (int) ceil($cursor->month / 3);

            $units[] = [
                'stage' => 'quarterly',
                'year' => (int) $cursor->year,
                'quarter' => $quarter,
                'label' => 'quarterly:' . $cursor->year . '-Q' . $quarter,
            ];

            $cursor->addQuarter();
        }

        return $units;
    }

    protected function buildYearlyUnits(Carbon $start, Carbon $end): array
    {
        $units = [];

        for ($year = (int) $start->year; $year <= (int) $end->year; $year++) {
            $units[] = [
                'stage' => 'yearly',
                'year' => $year,
                'label' => "yearly:{$year}",
            ];
        }

        return $units;
    }

    protected function runUnit(AggregationService $service, array $unit): void
    {
        match ($unit['stage']) {
            'hourly' => $service->updateHourlySummaries(Carbon::parse($unit['date']), $this->aggregationId),
            'daily' => $service->updateDailySummaries(Carbon::parse($unit['date']), $this->aggregationId),
            'weekly' => $service->updateWeeklySummariesRange(
                Carbon::parse($unit['start_date']),
                Carbon::parse($unit['end_date'])
            ),
            'monthly' => $service->updateMonthlySummariesYearMonth($unit['year'], $unit['month']),
            'quarterly' => $service->updateQuarterlySummariesYearQuarter($unit['year'], $unit['quarter']),
            'yearly' => $service->updateYearlySummariesYear($unit['year']),
            default => null,
        };
    }

    protected function unitDateForProgress(array $unit): ?string
    {
        return match ($unit['stage']) {
            'hourly', 'daily' => $unit['date'] ?? null,
            'weekly' => $unit['start_date'] ?? null,
            'monthly' => isset($unit['year'], $unit['month'])
            ? Carbon::create($unit['year'], $unit['month'], 1)->toDateString()
            : null,
            'quarterly' => isset($unit['year'], $unit['quarter'])
            ? Carbon::create($unit['year'], (($unit['quarter'] - 1) * 3) + 1, 1)->toDateString()
            : null,
            'yearly' => isset($unit['year'])
            ? Carbon::create($unit['year'], 1, 1)->toDateString()
            : null,
            default => null,
        };
    }

    protected function updateProgress(string $status, array $data = []): void
    {
        $current = Cache::get("agg_progress_{$this->aggregationId}", []);

        $progress = array_merge($current, [
            'status' => $status,
            'aggregation_id' => $this->aggregationId,
            'type' => $this->type,
            'updated_at' => now()->toISOString(),
        ], $data);

        Cache::put("agg_progress_{$this->aggregationId}", $progress, 60 * 60 * 24);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Aggregation rebuild job crashed', [
            'aggregation_id' => $this->aggregationId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'type' => $this->type,
            'stages' => $this->stages,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        $this->updateProgress('failed', [
            'message' => $e->getMessage(),
            'current_date' => null,
            'current_unit' => null,
            'failed_at' => now()->toISOString(),
        ]);
    }
}