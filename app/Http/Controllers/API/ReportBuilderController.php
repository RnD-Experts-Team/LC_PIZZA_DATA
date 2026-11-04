<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewReportRequest;
use App\Jobs\RunReportJob;
use App\Models\ReportRun;
use App\Models\ReportTemplate;
use App\Reports\Contract\ReportDataset;
use App\Reports\Engine\FederatedDatasetClient;
use App\Reports\Engine\PeriodResolver;
use App\Reports\Engine\ReportRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * The report builder: what every service offers (catalog), a live, size-capped
 * run of a definition (preview), and a full run of a saved template in the
 * background (run).
 */
class ReportBuilderController extends Controller
{
    private const PREVIEW_ROWS = 200;

    public function __construct(
        private readonly FederatedDatasetClient $client,
        private readonly ReportRunner $runner,
    ) {
    }

    /**
     * GET /api/report-builder/catalog
     *
     * Every service's datasets. The catalog is code, the same for every user,
     * so it is cached; the dashboard hides what canAccessRoute denies.
     */
    public function catalog(Request $request): JsonResponse
    {
        return response()->json([
            'contract_version' => ReportDataset::CONTRACT_VERSION,
            'services' => $this->client->cachedCatalogs((string) $request->bearerToken()),
        ]);
    }

    /**
     * POST /api/report-builder/preview  {definition}
     *
     * The first 200 rows of every dataset and of the result, 15 s for the lot.
     */
    public function preview(PreviewReportRequest $request): JsonResponse
    {
        $bearer = (string) $request->bearerToken();

        return response()->json($this->runner->run(
            $request->validated('definition'),
            $this->client->cachedCatalogs($bearer),
            $bearer,
            (int) Auth::id(),
            (int) config('services.report_datasets.preview_timeout', 15),
            self::PREVIEW_ROWS,
            self::PREVIEW_ROWS,
        ));
    }

    /**
     * POST /api/report-builder/run  {template_id, format: csv|xlsx}
     *
     * Queues a run of a template the caller can read, with its period fixed
     * now. One queued or running run per user (409). The caller's token goes
     * to the job encrypted and is never stored on the run.
     */
    public function run(Request $request, PeriodResolver $periods): JsonResponse
    {
        $data = $request->validate([
            'template_id' => 'required|integer',
            'format' => 'required|string|in:csv,xlsx',
        ]);
        $userId = (int) Auth::id();
        $template = ReportTemplate::query()->readableBy($userId)->findOrFail($data['template_id']);

        // The lock makes check-and-create one step for two clicks at once.
        $run = Cache::lock("report-builder:run:{$userId}", 10)->get(function () use ($userId, $template, $data, $periods) {
            if (ReportRun::query()->where('requested_by_user_id', $userId)->whereIn('status', ['queued', 'running'])->exists()) {
                return null;
            }

            [$from, $to] = $periods->resolve($template->definition['period']);

            return ReportRun::query()->create([
                'template_id' => $template->id,
                'requested_by_user_id' => $userId,
                'definition' => $template->definition,
                'period_from' => $from,
                'period_to' => $to,
                'format' => $data['format'],
                'status' => 'queued',
                'expires_at' => now()->addDays(ReportRun::KEEP_DAYS),
            ]);
        });

        if (! $run instanceof ReportRun) {
            return response()->json(['message' => 'You already have a report running; wait for it to finish.'], 409);
        }

        RunReportJob::dispatch($run->id, (string) $request->bearerToken());

        return response()->json($run->refresh(), 201);
    }
}
