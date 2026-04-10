<?php

namespace App\Jobs\RebuildAggregationPipeline;

use Carbon\Carbon;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * JOB PIPELINE ORCHESTRATOR
 *
 * Runs aggregation in correct dependency order across a date range:
 *   hourly(range) -> daily(range) -> weekly(range) -> monthly(periods) -> quarterly(periods) -> yearly(years)
 *
 * IMPORTANT:
 * - Uses Bus::batch() for each stage.
 * - Batch callbacks are STATIC and do NOT capture $this to avoid SerializableClosure serialization errors.
 * - After each stage completes, dispatches ContinueAggregationPipelineJob to run the next stage.
 */
class RebuildAggregationPipelineJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public $timeout = 3600;
    public $tries = 3;

    public function __construct(
        public string $rebuildId,
        public string $startDate,
        public string $endDate,
        public string $type = 'all',
        public ?array $stages = null,
        public int $stageIndex = 0
    ) {
    }

    public function handle(): void
    {
        $start = Carbon::parse($this->startDate)->startOfDay();
        $end = Carbon::parse($this->endDate)->startOfDay();

        // Build stages only on the first run
        $stages = $this->stages ?? $this->stagesForType($this->type);

        // Initialize progress if not present
        $this->initProgressIfMissing($stages, $start, $end);

        // If done
        if ($this->stageIndex >= count($stages)) {
            $this->updateProgress($this->rebuildId, [
                'status' => 'completed',
                'current_stage' => null,
                'stage_index' => $this->stageIndex,
                'stage_total' => count($stages),
            ]);


            return;
        }

        $stage = $stages[$this->stageIndex];

        // Build jobs for this stage
        $jobs = match ($stage) {
            'hourly' => $this->makeHourlyJobs($this->rebuildId, $start, $end),
            'daily' => $this->makeDailyJobs($this->rebuildId, $start, $end),
            'weekly' => $this->makeWeeklyJobs($this->rebuildId, $start, $end),
            'monthly' => $this->makeMonthlyJobs($this->rebuildId, $start, $end),
            'quarterly' => $this->makeQuarterlyJobs($this->rebuildId, $start, $end),
            'yearly' => $this->makeYearlyJobs($this->rebuildId, $start, $end),
            default => [],
        };

        // Nothing to do in this stage -> continue
        if (empty($jobs)) {
            ContinueAggregationPipelineJob::dispatch(
                rebuildId: $this->rebuildId,
                startDate: $start->toDateString(),
                endDate: $end->toDateString(),
                type: $this->type,
                stages: $stages,
                nextIndex: $this->stageIndex + 1
            );

            return;
        }

        // Update progress
        $this->updateProgress($this->rebuildId, [
            'status' => 'processing',
            'current_stage' => $stage,
            'stage_index' => $this->stageIndex,
            'stage_total' => count($stages),
            'stage_job_count' => count($jobs),
        ]);

        // IMPORTANT: prepare all values for closures WITHOUT capturing $this
        $rebuildId = $this->rebuildId;
        $startStr = $start->toDateString();
        $endStr = $end->toDateString();
        $type = $this->type;
        $nextIndex = $this->stageIndex + 1;

        Bus::batch($jobs)
            ->name("agg_rebuild:{$rebuildId}:{$stage}")
            ->allowFailures()
            ->then(static function (Batch $batch) use ($rebuildId, $stage) {
                // Runs only when every job in this stage succeeds.
                Cache::put("agg_rebuild_progress_{$rebuildId}", array_merge(
                    Cache::get("agg_rebuild_progress_{$rebuildId}", []),
                    [
                        'status' => 'processing',
                        'last_stage_completed' => $stage,
                        'last_stage_result' => 'completed',
                        'last_batch_id' => $batch->id,
                        'last_failed_jobs' => $batch->failedJobs,
                        'updated_at' => now()->toISOString(),
                    ]
                ), 7200);
            })
            ->catch(static function (Batch $batch, Throwable $e) use ($rebuildId, $stage) {
                // With allowFailures enabled, catch may run if any job fails.
                // Do not stop the pipeline here; finally() will continue to next stage.
                Cache::put("agg_rebuild_progress_{$rebuildId}", array_merge(
                    Cache::get("agg_rebuild_progress_{$rebuildId}", []),
                    [
                        'status' => 'processing',
                        'current_stage' => $stage,
                        'last_stage_result' => 'completed_with_failures',
                        'last_stage_error' => $e->getMessage(),
                        'updated_at' => now()->toISOString(),
                    ]
                ), 7200);

                Log::error('Aggregation stage batch failed', [
                    'rebuild_id' => $rebuildId,
                    'stage' => $stage,
                    'batch_id' => $batch->id ?? null,
                    'error' => $e->getMessage(),
                ]);
            })
            ->finally(static function (Batch $batch) use ($rebuildId, $startStr, $endStr, $type, $stages, $nextIndex, $stage) {
                // Continue the pipeline once this stage has finished, even with failures.
                Cache::put("agg_rebuild_progress_{$rebuildId}", array_merge(
                    Cache::get("agg_rebuild_progress_{$rebuildId}", []),
                    [
                        'status' => 'processing',
                        'last_stage_completed' => $stage,
                        'last_stage_result' => $batch->failedJobs > 0 ? 'completed_with_failures' : 'completed',
                        'last_batch_id' => $batch->id,
                        'last_failed_jobs' => $batch->failedJobs,
                        'updated_at' => now()->toISOString(),
                    ]
                ), 7200);

                ContinueAggregationPipelineJob::dispatch(
                    rebuildId: $rebuildId,
                    startDate: $startStr,
                    endDate: $endStr,
                    type: $type,
                    stages: $stages,
                    nextIndex: $nextIndex
                );
            })
            ->dispatch();
    }

    // -------------------------------------------------------------------------
    // Stage selection
    // -------------------------------------------------------------------------

    protected function stagesForType(string $type): array
    {
        // "type" means "run up to this level", respecting dependencies
        return match ($type) {
            'hourly' => ['hourly'],
            'daily' => ['hourly', 'daily'],
            'weekly' => ['hourly', 'daily', 'weekly'],
            'monthly' => ['hourly', 'daily', 'weekly', 'monthly'],
            'quarterly' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly'],
            'yearly' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
            'all' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
            default => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
        };
    }

    // -------------------------------------------------------------------------
    // Job builders
    // -------------------------------------------------------------------------

    protected function makeHourlyJobs(string $rebuildId, Carbon $start, Carbon $end): array
    {
        $jobs = [];
        $d = $start->copy();
        while ($d <= $end) {
            $jobs[] = new RebuildHourlyDayJob($rebuildId, $d->toDateString());
            $d->addDay();
        }
        return $jobs;
    }

    protected function makeDailyJobs(string $rebuildId, Carbon $start, Carbon $end): array
    {
        $jobs = [];
        $d = $start->copy();
        while ($d <= $end) {
            $jobs[] = new RebuildDailyDayJob($rebuildId, $d->toDateString());
            $d->addDay();
        }
        return $jobs;
    }

    protected function makeWeeklyJobs(string $rebuildId, Carbon $start, Carbon $end): array
    {
        // Weekly builds from DAILY — range-based job is simplest and correct
        return [
            new RebuildWeeklyRangeJob($rebuildId, $start->toDateString(), $end->toDateString())
        ];
    }

    protected function makeMonthlyJobs(string $rebuildId, Carbon $start, Carbon $end): array
    {
        $jobs = [];
        $cursor = $start->copy()->startOfMonth();
        $last = $end->copy()->startOfMonth();

        while ($cursor <= $last) {
            $jobs[] = new RebuildMonthlyJob($rebuildId, (int) $cursor->year, (int) $cursor->month);
            $cursor->addMonth();
        }

        return $jobs;
    }

    protected function makeQuarterlyJobs(string $rebuildId, Carbon $start, Carbon $end): array
    {
        $jobs = [];
        $cursor = $start->copy()->startOfQuarter();
        $last = $end->copy()->startOfQuarter();

        while ($cursor <= $last) {
            $q = (int) ceil($cursor->month / 3);
            $jobs[] = new RebuildQuarterlyJob($rebuildId, (int) $cursor->year, $q);
            $cursor->addQuarter();
        }

        return $jobs;
    }

    protected function makeYearlyJobs(string $rebuildId, Carbon $start, Carbon $end): array
    {
        $jobs = [];
        for ($y = (int) $start->year; $y <= (int) $end->year; $y++) {
            $jobs[] = new RebuildYearlyJob($rebuildId, $y);
        }
        return $jobs;
    }

    // -------------------------------------------------------------------------
    // Progress helpers
    // -------------------------------------------------------------------------

    protected function initProgressIfMissing(array $stages, Carbon $start, Carbon $end): void
    {
        $key = "agg_rebuild_progress_{$this->rebuildId}";
        if (Cache::has($key)) {
            return;
        }

        Cache::put($key, [
            'status' => 'queued',
            'rebuild_id' => $this->rebuildId,
            'type' => $this->type,
            'stages' => $stages,
            'current_stage' => null,
            'stage_index' => 0,
            'stage_total' => count($stages),
            'started_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
            'date_range' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ],
        ], 7200);
    }

    protected function updateProgress(string $rebuildId, array $patch): void
    {
        $key = "agg_rebuild_progress_{$rebuildId}";
        $cur = Cache::get($key, []);
        $cur['updated_at'] = now()->toISOString();

        foreach ($patch as $k => $v) {
            $cur[$k] = $v;
        }

        Cache::put($key, $cur, 7200);
    }
}
