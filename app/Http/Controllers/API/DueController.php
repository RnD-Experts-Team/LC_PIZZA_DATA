<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataEntry\DueRangeRequest;
use App\Services\DataEntry\DueKeyResolverService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use App\Models\Employee;
use App\Models\EmployeeDebriefType;
class DueController extends Controller
{
    public function __construct(
        private readonly DueKeyResolverService $due
    ) {
    }

    /**
     * GET /engine/stores/{store_id}/dates/{date}/due
     */
    public function dueOnDate(string $store_id, string $date): JsonResponse
    {
        $tags = request()->query('tags');
        $tagIds = $tags ? explode(',', $tags) : [];

        $items = $this->due->dueForStoreOnDate(
            $store_id,
            Carbon::parse($date),
            $tagIds
        );
        return response()->json([
            'store_id' => $store_id,
            'date' => $date,
            'items' => $items,
            'employees' => Employee::where('store_id', $store_id)->where('active', true)->get(),
            'employee_debrief_types' => EmployeeDebriefType::all(['id', 'slug', 'label']),
        ]);
    }

    /**
     * GET /engine/stores/{store_id}/due-range?from=...&to=...
     */
    public function dueRange(DueRangeRequest $request, string $store_id): JsonResponse
    {
        $v = $request->validated();

        $tags = request()->query('tags');
        $tagIds = $tags ? explode(',', $tags) : [];

        $paginated = filter_var(request()->query('paginated'), FILTER_VALIDATE_BOOLEAN);

        $data = $this->due->dueRange(
            $store_id,
            Carbon::parse($v['from']),
            Carbon::parse($v['to']),
            $tagIds
        );

        if (!$paginated) {
            return response()->json([
                'store_id' => $store_id,
                'from' => $v['from'],
                'to' => $v['to'],
                'days' => $data,
            ]);
        }

        $collection = collect($data)->forPage(
            request()->query('page', 1),
            request()->query('per_page', 20)
        );

        return response()->json([
            'store_id' => $store_id,
            'from' => $v['from'],
            'to' => $v['to'],
            'days' => $collection->values(),
            'pagination' => [
                'page' => (int) request()->query('page', 1),
                'per_page' => (int) request()->query('per_page', 20),
                'total_days' => count($data)
            ]
        ]);
    }
}
