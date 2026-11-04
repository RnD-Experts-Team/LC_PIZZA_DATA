<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Reports\Engine\ReportRowReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Report runs: the runs a user can read, the rows of a finished one paged out
 * of its CSV, its files, and (signed URL only) the file for a server-to-server
 * fetch. A run is readable by the user who asked for it (a scheduled run: the
 * schedule's owner) and by the users listed in its schedule's recipients; for
 * anyone else it does not exist (404). An expired run is gone (410) until the
 * prune removes it.
 */
class ReportRunController extends Controller
{
    /** GET /api/report-builder/runs  ?template_id&per_page — the runs the caller can read, newest first */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_id' => 'sometimes|integer',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        return response()->json($this->readable()
            ->when(isset($data['template_id']), fn ($q) => $q->where('template_id', $data['template_id']))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20)));
    }

    /** GET /api/report-builder/runs/{run} */
    public function show(string $run): JsonResponse
    {
        return response()->json($this->visible($run));
    }

    /**
     * GET /api/report-builder/runs/{run}/rows  ?page (from 1) &per (25-200, default 50)
     *
     * {columns: [{id, label, type, format}], rows: [[...]],
     *  page: {current_page, per_page, total, last_page}, meta: {row_count, truncated, skipped_stores}}
     * where page.total is the number of rows and page.last_page the number of pages.
     */
    public function rows(Request $request, string $run, ReportRowReader $reader): JsonResponse
    {
        $data = $request->validate([
            'page' => 'sometimes|integer|min:1',
            'per' => 'sometimes|integer|min:25|max:200',
        ]);
        $model = $this->live($this->visible($run));
        if ($model->status !== 'done' || $model->csv_path === null || ! Storage::disk('local')->exists($model->csv_path)) {
            abort(409, 'The report has no rows to show yet.');
        }

        $page = (int) ($data['page'] ?? 1);
        $per = (int) ($data['per'] ?? 50);
        $columns = array_values($model->meta['columns'] ?? []);
        $total = (int) $model->row_count;

        return response()->json([
            'columns' => array_map(fn (array $column) => [
                'id' => $column['id'],
                'label' => $column['label'] ?? $column['id'],
                'type' => $column['type'] ?? 'string',
                'format' => $column['format'] ?? null,
            ], $columns),
            'rows' => $reader->read(Storage::disk('local')->path($model->csv_path), $columns, ($page - 1) * $per, $per),
            'page' => ['current_page' => $page, 'per_page' => $per, 'total' => $total, 'last_page' => max((int) ceil($total / $per), 1)],
            'meta' => [
                'row_count' => $total,
                'truncated' => (bool) ($model->meta['truncated'] ?? false),
                'skipped_stores' => $model->meta['skipped_stores'] ?? [],
            ],
        ]);
    }

    /** GET /api/report-builder/runs/{run}/download  ?format=csv|xlsx (default: the run's format) */
    public function download(Request $request, string $run): BinaryFileResponse
    {
        return $this->send($request, $this->visible($run));
    }

    /** GET /api/report-builder/files/{run}  ?format — signed URL only, for a server fetching the file. */
    public function file(Request $request, string $run): BinaryFileResponse
    {
        return $this->send($request, ReportRun::query()->findOrFail($run));
    }

    private function visible(string $id): ReportRun
    {
        return $this->readable()->findOrFail($id);
    }

    /** Runs the caller asked for, and runs of schedules that list the caller as a recipient. */
    private function readable(): Builder
    {
        $userId = (int) Auth::id();

        return ReportRun::query()->where(fn (Builder $q) => $q
            ->where('requested_by_user_id', $userId)
            ->orWhereIn('schedule_id', ReportSchedule::query()->select('id')->whereJsonContains('recipients->users', $userId)));
    }

    private function live(ReportRun $run): ReportRun
    {
        abort_if($run->expires_at->isPast(), 410, 'This report has expired.');

        return $run;
    }

    private function send(Request $request, ReportRun $run): BinaryFileResponse
    {
        $format = $request->validate(['format' => 'sometimes|string|in:csv,xlsx'])['format'] ?? $run->format;
        $path = $this->live($run)->status === 'done' ? ($format === 'xlsx' ? $run->xlsx_path : $run->csv_path) : null;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404, 'This report has no '.strtoupper($format).' file.');

        return response()->download(
            Storage::disk('local')->path($path),
            $run->fileName($format),
            ['Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=UTF-8'],
        );
    }
}
