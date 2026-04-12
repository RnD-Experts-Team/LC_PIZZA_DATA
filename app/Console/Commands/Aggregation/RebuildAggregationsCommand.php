<?php

namespace App\Console\Commands\Aggregation;

use App\Jobs\RunAggregationRebuildJob;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RebuildAggregationsCommand extends Command
{
    protected $signature = 'aggregation:rebuild
                            {--start= : Start date (Y-m-d)}
                            {--end= : End date (Y-m-d)}
                            {--type=all : Shortcut type: hourly, daily, weekly, monthly, quarterly, yearly, all}
                            {--stages= : Comma-separated stages: hourly,daily,weekly,monthly,quarterly,yearly,all}
                            {--except= : Comma-separated stages to exclude}';

    protected $description = 'Queue a background aggregation rebuild';

    protected array $validStages = [
        'hourly',
        'daily',
        'weekly',
        'monthly',
        'quarterly',
        'yearly',
    ];

    protected array $stageOrder = [
        'hourly' => 1,
        'daily' => 2,
        'weekly' => 3,
        'monthly' => 4,
        'quarterly' => 5,
        'yearly' => 6,
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->info('🔄 QUEUE AGGREGATION REBUILD');
        $this->line(str_repeat('═', 80));

        if (!$this->option('start') || !$this->option('end')) {
            $this->error('❌ Both --start and --end dates required');
            return self::FAILURE;
        }

        try {
            $start = Carbon::parse($this->option('start'))->startOfDay();
            $end = Carbon::parse($this->option('end'))->startOfDay();
        } catch (\Throwable $e) {
            $this->error('❌ Invalid date format (use Y-m-d)');
            return self::FAILURE;
        }

        if ($start->gt($end)) {
            $this->error('❌ Start date must be <= end date');
            return self::FAILURE;
        }

        try {
            $stages = $this->resolveStages(
                (string) ($this->option('type') ?? 'all'),
                $this->option('stages'),
                $this->option('except')
            );
        } catch (\InvalidArgumentException $e) {
            $this->error('❌ ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->table(['Start', 'End', 'Stages'], [
            [
                $start->toDateString(),
                $end->toDateString(),
                implode(', ', $stages),
            ]
        ]);

        if (!$this->confirm('🚀 Queue rebuild?', true)) {
            return self::SUCCESS;
        }

        $aggregationId = uniqid('agg_', true);

        Cache::put("agg_progress_{$aggregationId}", [
            'status' => 'queued',
            'aggregation_id' => $aggregationId,
            'type' => (string) ($this->option('type') ?? 'all'),
            'stages' => $stages,
            'processed' => 0,
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'started_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'current_date' => null,
            'current_unit' => null,
            'failed_units' => [],
        ], 60 * 60 * 24);

        RunAggregationRebuildJob::dispatch(
            aggregationId: $aggregationId,
            startDate: $start->toDateString(),
            endDate: $end->toDateString(),
            type: (string) ($this->option('type') ?? 'all'),
            stages: $stages
        );

        $this->newLine();
        $this->info("✅ Rebuild queued. ID: {$aggregationId}");
        $this->info("💡 Progress key: agg_progress_{$aggregationId}");

        return self::SUCCESS;
    }

    protected function resolveStages(string $type, mixed $stagesOption, mixed $exceptOption): array
    {
        if ($stagesOption !== null && trim((string) $stagesOption) !== '') {
            $stages = $this->parseStageList((string) $stagesOption, allowAll: true);
            $stages = $this->normalizeStageOrder($stages);
        } else {
            $stages = $this->stagesFromType(trim(strtolower($type)));
        }

        $except = [];
        if ($exceptOption !== null && trim((string) $exceptOption) !== '') {
            $except = $this->parseStageList((string) $exceptOption, allowAll: false);
        }

        $stages = array_values(array_filter(
            $stages,
            fn(string $stage) => !in_array($stage, $except, true)
        ));

        if (empty($stages)) {
            throw new \InvalidArgumentException('No stages remain after applying --except');
        }

        return $stages;
    }

    protected function stagesFromType(string $type): array
    {
        return match ($type) {
            'hourly' => ['hourly'],
            'daily' => ['hourly', 'daily'],
            'weekly' => ['hourly', 'daily', 'weekly'],
            'monthly' => ['hourly', 'daily', 'weekly', 'monthly'],
            'quarterly' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly'],
            'yearly' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
            'all', '' => ['hourly', 'daily', 'weekly', 'monthly', 'quarterly', 'yearly'],
            default => throw new \InvalidArgumentException(
                '--type must be one of: ' . implode(', ', [...$this->validStages, 'all'])
            ),
        };
    }

    protected function parseStageList(string $csv, bool $allowAll): array
    {
        $parts = array_values(array_filter(array_map(
            fn(string $s) => trim(strtolower($s)),
            explode(',', $csv)
        )));

        if (empty($parts)) {
            throw new \InvalidArgumentException('Stage list cannot be empty');
        }

        if ($allowAll && in_array('all', $parts, true)) {
            return $this->validStages;
        }

        foreach ($parts as $part) {
            if (!in_array($part, $this->validStages, true)) {
                throw new \InvalidArgumentException(
                    "Invalid stage '{$part}'. Valid stages: " . implode(', ', [...$this->validStages, 'all'])
                );
            }
        }

        return array_values(array_unique($parts));
    }

    protected function normalizeStageOrder(array $stages): array
    {
        usort($stages, fn(string $a, string $b) => $this->stageOrder[$a] <=> $this->stageOrder[$b]);
        return array_values(array_unique($stages));
    }
}