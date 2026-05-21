<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\GoalMetric;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class GoalMetricController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            GoalMetric::orderBy('name')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:goal_metrics,name',
        ]);

        $goalMetric = GoalMetric::create($data);

        return response()->json($goalMetric, 201);
    }

    public function destroy(GoalMetric $goalMetric): JsonResponse
    {
        $goalMetric->delete();

        return response()->json(['message' => 'Goal metric deleted.']);
    }
}
