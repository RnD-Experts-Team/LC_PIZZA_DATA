<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\GoalMetric;
use Illuminate\Http\JsonResponse;

class GoalMetricController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            GoalMetric::orderBy('name')->get()
        );
    }

    public function destroy(GoalMetric $goalMetric): JsonResponse
    {
        $goalMetric->delete();

        return response()->json(['message' => 'Goal metric deleted.']);
    }
}
