<?php

namespace App\Jobs\RebuildAggregationPipeline;

use App\Services\Aggregation\AggregationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Log;

class RebuildYearlyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public $timeout = 3600;
    public $tries = 3;

    public function __construct(
        protected string $rebuildId,
        protected int $year
    ) {
    }

    public function handle(AggregationService $service): void
    {
        try {
            $service->updateYearlySummariesYear($this->year);
        } catch (\Throwable $e) {
            Log::error('RebuildYearlyJob failed', [
                'rebuild_id' => $this->rebuildId,
                'year' => $this->year,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
