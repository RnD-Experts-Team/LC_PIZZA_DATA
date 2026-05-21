<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\Goals\ListGoalsRequest;
use App\Http\Requests\Goals\StoreGoalRequest;
use App\Http\Requests\Goals\UpdateGoalRequest;
use App\Models\Goal;
use Illuminate\Http\JsonResponse;

class GoalController extends Controller
{
    public function index(ListGoalsRequest $request, string $store_id): JsonResponse
    {
        $v = $request->validated();

        $q = Goal::where('store_id', $store_id);

        if (!empty($v['from']))
            $q->where('week_start_date', '>=', $v['from']);

        if (!empty($v['to']))
            $q->where('week_end_date', '<=', $v['to']);

        if (!empty($v['goal_metric_id']))
            $q->where('goal_metric_id', $v['goal_metric_id']);

        $perPage = (int) ($v['per_page'] ?? 20);

        return response()->json($q->orderBy('week_start_date')->paginate($perPage));
    }

    public function store(StoreGoalRequest $request, string $store_id): JsonResponse
    {
        $v = $request->validated();

        $goal = Goal::create([
            'goal_metric_id'  => $v['goal_metric_id'],
            'store_id'        => $store_id,
            'week_start_date' => $v['week_start_date'],
            'week_end_date'   => $v['week_end_date'],
            'goal'            => $v['goal'],
        ]);

        return response()->json($goal, 201);
    }

    public function update(UpdateGoalRequest $request, string $store_id, Goal $goal): JsonResponse
    {
        if ($goal->store_id !== $store_id) {
            abort(403, 'Forbidden.');
        }

        $goal->update($request->validated());

        return response()->json($goal);
    }

    public function destroy(string $store_id, Goal $goal): JsonResponse
    {
        if ($goal->store_id !== $store_id) {
            abort(403, 'Forbidden.');
        }

        $goal->delete();

        return response()->json(['message' => 'Goal deleted.']);
    }
}
