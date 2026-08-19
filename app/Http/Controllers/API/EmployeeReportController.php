<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Analytics\EmployeeReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EmployeeReportController extends Controller
{
    public function __construct(
        private readonly EmployeeReportService $employeeReportService,
    ) {
    }

    /**
     * GET /api/reports/employees/{store}/{date}
     *
     * Store-wide employee roster + debrief activity for the business week
     * containing {date}, plus trailing-week debrief trend. Override the
     * trend window with ?trend_weeks= (default 6, max 12).
     */
    public function show(Request $request, string $store, string $date): JsonResponse
    {
        $this->validateInputs($store, $date);

        $trendWeeks = $request->query('trend_weeks');

        return response()->json(
            $this->employeeReportService->getEmployeeReport($store, $date, $trendWeeks !== null ? (int) $trendWeeks : null)
        );
    }

    private function validateInputs(string $store, string $date): void
    {
        if ($store === '') {
            throw ValidationException::withMessages(['store' => 'Store is required']);
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages(['date' => 'Invalid date format']);
        }
    }
}
