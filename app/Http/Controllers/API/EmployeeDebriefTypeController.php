<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDebriefType;
use Illuminate\Http\JsonResponse;

class EmployeeDebriefTypeController extends Controller
{
    /**
     * List available employee debrief types
     */
    public function index(string $store_id): JsonResponse
    {
        return response()->json(
            EmployeeDebriefType::query()->get(['id', 'slug', 'label'])
        );
    }
}
