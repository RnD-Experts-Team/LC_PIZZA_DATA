<?php

namespace App\Jobs\RebuildAggregationPipeline;

use App\Services\Aggregation\AggregationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Log;

class RebuildWeeklyRangeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public $timeout = 3600;
    public $tries = 3;

    public function __construct(
        protected string $rebuildId,
        protected string $startDate,
        protected string $endDate
    ) {
    }

    public function handle(AggregationService $service): void
    {
        try {
            $start = Carbon::parse($this->startDate)->startOfDay();
            $end = Carbon::parse($this->endDate)->startOfDay();


            // Your service already supports weekly range aggregation from DAILY:
            $service->updateWeeklySummariesRange($start, $end);
        } catch (\Throwable $e) {
            Log::error('RebuildWeeklyRangeJob failed', [
                'rebuild_id' => $this->rebuildId,
                'start_date' => $this->startDate,
                'end_date' => $this->endDate,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
