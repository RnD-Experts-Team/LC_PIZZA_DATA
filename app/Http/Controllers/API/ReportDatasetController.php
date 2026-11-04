<?php
// Copied from LC_PIZZA_DATA/app/Http/Controllers/API/ReportDatasetController.php — contract v1. Edit there, copy everywhere.

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportDatasetQueryRequest;
use App\Reports\Contract\DatasetQueryCompiler;
use App\Reports\Contract\ReportDataset;
use App\Reports\ReportDatasets;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The report dataset contract: what this service offers the report builder
 * (catalog) and one grouped query against one dataset (query). Each dataset
 * has its own literal route and its own pizzasys auth rule.
 */
class ReportDatasetController extends Controller
{
    /**
     * GET /api/report-datasets
     *
     * `service` is this service's pizzasys name, the one the dashboard hands
     * to canAccessRoute.
     */
    public function catalog(): JsonResponse
    {
        return response()->json([
            'service' => config('services.auth_server.service_name'),
            'contract_version' => ReportDataset::CONTRACT_VERSION,
            'contract_sha1' => ReportDataset::contractSha1(),
            'datasets' => array_map(fn (string $dataset) => (new $dataset())->toArray(), ReportDatasets::DATASETS),
        ]);
    }

    /**
     * POST /api/report-datasets/{key}/query — one literal route per dataset,
     * the key arrives as the `dataset` route default. `stream: true` sends every
     * row as NDJSON (DatasetQueryCompiler::stream) instead of one page.
     */
    public function query(ReportDatasetQueryRequest $request, DatasetQueryCompiler $compiler): JsonResponse|StreamedResponse
    {
        $dataset = ReportDatasets::make((string) $request->route('dataset'));

        return $request->boolean('stream')
            ? $compiler->stream($dataset, $request->validated())
            : response()->json($compiler->run($dataset, $request->validated()));
    }
}
