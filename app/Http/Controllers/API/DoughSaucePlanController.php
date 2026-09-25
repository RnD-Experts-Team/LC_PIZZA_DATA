<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DoughSauce\DailyPlanRequest;
use App\Services\DoughSauce\DoughSaucePlanService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

/**
 * The ingredient figures behind a day's production plan.
 *
 * Returns what a store's sales say it needed on the same weekday, averaged over
 * the last four of them, per ingredient. It stops at `base`: the buffer on top is
 * a store manager's decision and belongs to AuditApp.
 *
 * Called from the browser, so it is behind the rate limiter as well as the auth
 * middleware — 44 store screens can open at once.
 */
class DoughSaucePlanController extends Controller
{
    public function __construct(
        private readonly DoughSaucePlanService $plans
    ) {
    }

    public function dailyPlan(DailyPlanRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->plans->dailyPlan(
            store:           $data['store'],
            date:            Carbon::parse($data['date']),
            lookback:        $request->lookback(),
            includeRefunded: $request->includeRefunded(),
        ));
    }
}
