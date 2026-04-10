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

class RebuildMonthlyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Batchable;

    public $timeout = 3600;
    public $tries = 3;

    public function __construct(
        protected string $rebuildId,
        protected int $year,
        protected int $month
    ) {
    }

    public function handle(AggregationService $service): void
    {
        try {
            $service->updateMonthlySummariesYearMonth($this->year, $this->month);
        } catch (\Throwable $e) {
            Log::error('RebuildMonthlyJob failed', [
                'rebuild_id' => $this->rebuildId,
                'year' => $this->year,
                'month' => $this->month,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
