<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportTemplateRequest;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Reports\Engine\FederatedDatasetClient;
use App\Reports\Engine\ReportDefinitionValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Saved report definitions: the caller's own and every org-wide one. Only the
 * owner changes or deletes a template; for anyone else it does not exist (404).
 */
class ReportTemplateController extends Controller
{
    public function __construct(
        private readonly FederatedDatasetClient $client,
        private readonly ReportDefinitionValidator $validator,
    ) {
    }

    /** GET /api/report-builder/templates */
    public function index(): JsonResponse
    {
        return response()->json(
            ReportTemplate::query()->readableBy((int) Auth::id())->orderBy('name')->orderBy('id')->get()
        );
    }

    /** POST /api/report-builder/templates  {name, description?, visibility, definition} */
    public function store(ReportTemplateRequest $request): JsonResponse
    {
        $data = $this->validated($request);

        return response()->json(ReportTemplate::query()->create([...$data, 'owner_user_id' => (int) Auth::id()]), 201);
    }

    /** GET /api/report-builder/templates/{template} */
    public function show(int $template): JsonResponse
    {
        return response()->json(ReportTemplate::query()->readableBy((int) Auth::id())->findOrFail($template));
    }

    /** PUT /api/report-builder/templates/{template}  (owner only) */
    public function update(ReportTemplateRequest $request, int $template): JsonResponse
    {
        $model = $this->owned($template);
        $model->update($this->validated($request));

        return response()->json($model);
    }

    /**
     * DELETE /api/report-builder/templates/{template}  (owner only; its schedules, runs
     * and their files go too)
     *
     * While another user still has a schedule on it, it stays (409): their tokens are
     * theirs to give back. The caller's own schedules of it give their tokens back
     * first, one by one; while pizzasys cannot confirm that, the template and what is
     * left stay (503).
     */
    public function destroy(Request $request, int $template): JsonResponse
    {
        $model = $this->owned($template);
        if (ReportSchedule::query()->where('template_id', $model->id)->where('owner_user_id', '!=', (int) Auth::id())->exists()) {
            return response()->json(['message' => 'Other users still have schedules on this template; they must delete them first.'], 409);
        }
        $authorization = (string) $request->header('Authorization');
        foreach (ReportSchedule::query()->where('template_id', $model->id)->where('owner_user_id', (int) Auth::id())->get() as $schedule) {
            if (! $schedule->revokeToken($authorization)) {
                return response()->json(['message' => 'Could not revoke the tokens of this template\'s schedules at the auth server; try again shortly.'], 503);
            }
            $schedule->delete();
        }
        $model->runs()->each(fn ($run) => $run->deleteFiles());
        $model->delete();

        return response()->json(['message' => 'Template deleted.']);
    }

    private function owned(int $id): ReportTemplate
    {
        return ReportTemplate::query()->where('owner_user_id', (int) Auth::id())->findOrFail($id);
    }

    /** The request's fields, its definition checked against the catalog as the caller sees it. */
    private function validated(ReportTemplateRequest $request): array
    {
        $data = $request->safe()->only(['name', 'description', 'visibility', 'definition']);

        $datasets = [];
        foreach ($this->client->cachedCatalogs((string) $request->bearerToken()) as $service) {
            foreach ($service['datasets'] ?? [] as $dataset) {
                $datasets[$dataset['key']] = $dataset;
            }
        }
        $this->validator->validate($data['definition'], $datasets);

        return $data;
    }
}
