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

class RebuildQuarterlyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public $timeout = 3600;
    public $tries = 3;

    public function __construct(
        protected string $rebuildId,
        protected int $year,
        protected int $quarter
    ) {
    }

    public function handle(AggregationService $service): void
    {
        try {
            $service->updateQuarterlySummariesYearQuarter($this->year, $this->quarter);
        } catch (\Throwable $e) {
            Log::error('RebuildQuarterlyJob failed', [
                'rebuild_id' => $this->rebuildId,
                'year' => $this->year,
                'quarter' => $this->quarter,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
