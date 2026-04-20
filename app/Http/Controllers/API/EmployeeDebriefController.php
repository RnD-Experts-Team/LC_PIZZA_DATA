<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDebrief;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeDebriefController extends Controller
{

    /**
     * List debrief notes
     */
    public function index(Request $request, string $store_id): JsonResponse
    {

        $filters = $request->validate([
            'employee_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')->where(fn($query) => $query->where('store_id', $store_id)),
            ],
            'date' => 'nullable|date_format:Y-m-d',
            'per_page' => 'nullable|integer|min:1|max:200',
        ]);

        $q = EmployeeDebrief::query()
            ->where('store_id', $store_id)
            ->with(['author', 'employee']);

        if (isset($filters['employee_id'])) {

            $q->where('employee_id', $filters['employee_id']);

        }

        if (isset($filters['date'])) {

            $q->whereDate('date', $filters['date']);

        }

        $perPage = (int) ($filters['per_page'] ?? 50);

        return response()->json(
            $q->orderByDesc('date')->paginate($perPage)
        );
    }

    /**
     * Create note
     */
    public function store(Request $request, string $store_id): JsonResponse
    {

        $data = $request->validate([
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn($query) => $query->where('store_id', $store_id)),
            ],
            'note' => 'required|string|max:5000',
            'date' => 'required|date_format:Y-m-d'
        ]);

        $user = $request->user();

        $debrief = EmployeeDebrief::create([

            'store_id' => $store_id,

            'user_id' => $user->id,

            'employee_id' => $data['employee_id'],

            'note' => $data['note'],

            'date' => $data['date'],

        ]);

        return response()->json($debrief->load(['author', 'employee']), 201);
    }

    /**
     * Show single note
     */
    public function show(string $store_id, EmployeeDebrief $debrief): JsonResponse
    {

        if ($debrief->store_id !== $store_id) {

            abort(404);

        }

        return response()->json(
            $debrief->load(['author', 'employee'])
        );
    }

    /**
     * Delete note
     */
    public function destroy(string $store_id, EmployeeDebrief $debrief): JsonResponse
    {

        if ($debrief->store_id !== $store_id) {

            abort(404);

        }

        $debrief->delete();

        return response()->json([
            'message' => 'Debrief deleted.'
        ]);
    }

    /**
     * Create multiple notes
     */
    public function storeMultiple(Request $request, string $store_id): JsonResponse
    {
        $data = $request->validate([
            'debriefs' => 'required|array|min:1',
            'debriefs.*.employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->where(fn($query) => $query->where('store_id', $store_id)),
            ],
            'debriefs.*.note' => 'required|string|max:5000',
            'debriefs.*.date' => 'required|date_format:Y-m-d',
        ]);

        $user = $request->user();

        $records = [];

        foreach ($data['debriefs'] as $item) {

            $records[] = [
                'store_id' => $store_id,
                'user_id' => $user->id,
                'employee_id' => $item['employee_id'],
                'note' => $item['note'],
                'date' => $item['date'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        EmployeeDebrief::insert($records);

        return response()->json([
            'message' => 'Debriefs created successfully.',
            'count' => count($records)
        ], 201);
    }
}