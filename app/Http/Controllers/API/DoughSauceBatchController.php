<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DoughSauce\DailyPlansRequest;
use App\Services\DoughSauce\DoughSauceBatchPlanService;
use Illuminate\Http\JsonResponse;

/**
 * GET stores/{store_id}/dough-sauce/daily-plans?dates=…
 *
 * The weekly report's "check every unplanned day against its base", as one call
 * per store instead of one per store per day. Still store-scoped, so pizzasys
 * authorizes it with the ordinary path rule — a single all-stores endpoint would
 * have needed a special case there.
 */
class DoughSauceBatchController extends Controller
{
    public function __construct(
        private readonly DoughSauceBatchPlanService $plans
    ) {
    }

    public function dailyPlans(DailyPlansRequest $request, string $store_id): JsonResponse
    {
        return response()->json([
            'store'            => $store_id,
            'lookback'         => $request->lookback(),
            'include_refunded' => $request->includeRefunded(),
            // Keyed by date; each value is exactly what /daily-plan returns.
            'plans'            => $this->plans->dailyPlans(
                store:           $store_id,
                targetDates:     $request->dates(),
                lookback:        $request->lookback(),
                includeRefunded: $request->includeRefunded(),
            ),
        ]);
    }
}
