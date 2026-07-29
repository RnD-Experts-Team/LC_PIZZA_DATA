<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\Export\LcArchiveZipService;
use Illuminate\Validation\ValidationException;

class LcArchiveExportController extends Controller
{
    public function __construct(protected LcArchiveZipService $service)
    {
    }

    /**
     * Rebuild the LC daily report zip (same zip/CSV naming + formatting as the
     * LC gateway download) from our database for the given business date,
     * across every store/location that has data for that date.
     *
     * GET /api/reports/lc-archive-zip/{date}
     */
    public function download(string $date)
    {
        $this->validateInputs($date);

        try {
            $zip = $this->service->buildZip($date);

            return response()->download($zip['path'], $zip['filename'], [
                'Content-Type' => 'application/zip',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function validateInputs(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw ValidationException::withMessages(['date' => 'Invalid date format']);
        }
    }
}
