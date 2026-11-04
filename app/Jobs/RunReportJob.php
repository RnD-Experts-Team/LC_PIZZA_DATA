<?php

namespace App\Jobs;

use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Reports\Engine\FederatedDatasetClient;
use App\Reports\Engine\ReportFileWriter;
use App\Reports\Engine\ReportRunner;
use App\Services\DataEvents\DataEventFactory;
use App\Services\DataEvents\DataOutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Runs one queued report run in full and writes its files. The requester's
 * token (a scheduled run: the schedule's own token) travels encrypted in the
 * payload, for the job's life only. Tried once: a run that stops half-way is
 * failed, never repeated.
 *
 * It has no time limit and no memory limit: the engine streams every row and
 * holds none. Instead it shows it is alive: heartbeat_at moves on every 10,000
 * rows and at each phase, and reports:dispatch-due fails a running run that has
 * gone 30 minutes without one.
 *
 * A scheduled run is delivered through the outbox to NotificationsPizza: an
 * in-app notification to every recipient and an email to every recipient with
 * an address, the file attached when the schedule asks for it and it is at most
 * 15 MB. When one fails, its owner is told in-app; when a service the report
 * queries refuses its token (401), the schedule is paused and its owner told so.
 * A run that finished stays done when its delivery fails; its owner is told.
 * A scheduled run needs services.report_datasets.dashboard_url for its links and
 * fails without it.
 */
class RunReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 1;

    /** None: a report takes as long as its rows take (liveness is the heartbeat). */
    public $timeout = 0;

    /** Larger files are not attached; the email carries the link only. */
    private const MAX_ATTACHMENT_BYTES = 15 * 1024 * 1024;

    private const PAUSED = 'The schedule was paused: its token is no longer valid. Resume it to renew.';

    private readonly string $bearer;

    public function __construct(public readonly string $runId, #[\SensitiveParameter] string $bearer)
    {
        $this->bearer = Crypt::encryptString($bearer);
        $this->onConnection('reports')->onQueue('reports');
    }

    public function handle(FederatedDatasetClient $client, ReportRunner $runner, ReportFileWriter $writer): void
    {
        // Claimed in one step: a run that is no longer queued (done, failed, deleted,
        // or already taken by another worker) is left alone.
        $claimed = ReportRun::query()->whereKey($this->runId)->where('status', 'queued')
            ->update(['status' => 'running', 'started_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now()]);
        $run = $claimed === 1 ? ReportRun::query()->find($this->runId) : null;
        if ($run === null) {
            return;
        }

        // No memory limit may stop a run; the worker restarts between jobs past --memory.
        ini_set('memory_limit', '-1');

        try {
            // Its links are in every message about a scheduled run: no point in running without them.
            if ($run->schedule_id !== null && trim((string) config('services.report_datasets.dashboard_url')) === '') {
                throw new RuntimeException('REPORTS_DASHBOARD_URL is not set');
            }

            $bearer = Crypt::decryptString($this->bearer);
            $catalogs = $client->cachedCatalogs($bearer);

            // The period was fixed when the run was asked for.
            $definition = [...$run->definition, 'period' => [
                'type' => 'fixed',
                'from' => $run->period_from->toDateString(),
                'to' => $run->period_to->toDateString(),
            ]];

            $beat = fn () => ReportRun::query()->whereKey($run->id)->where('status', 'running')->update(['heartbeat_at' => now()]);
            $result = $runner->export(
                $definition,
                $catalogs,
                $bearer,
                $run->requested_by_user_id,
                $run->id,
                $beat,
                fn (array $columns, iterable $rows) => $writer->write($run->id, $columns, $rows, $run->format === 'xlsx', $beat),
            );
            $files = $result['files'];

            // Zero rows: the run (or its template) was deleted meanwhile.
            $updated = ReportRun::query()->whereKey($run->id)->where('status', 'running')->update([
                'status' => 'done',
                'row_count' => $result['row_count'],
                'csv_path' => $files['csv_path'],
                'xlsx_path' => $files['xlsx_path'],
                'file_size' => $files['xlsx_size'] ?? $files['csv_size'],
                'meta' => json_encode([
                    'columns' => $result['columns'],
                    'truncated' => $result['truncated'],
                    'skipped_stores' => $result['meta']['skipped_stores'],
                ]),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            if ($updated === 0) {
                $run->deleteFiles();

                return;
            }

        } catch (Throwable $e) {
            $this->refused($e) && $run->schedule_id !== null
                ? $this->failRun($run, self::PAUSED, paused: true)
                : $this->failRun($run, $this->message($e));

            return;
        }

        // The report is made and stays done whatever happens to its delivery.
        if ($run->schedule_id !== null) {
            try {
                $this->deliver($run->fresh());
            } catch (Throwable $e) {
                report($e);
                $this->notifyOwner($run, 'report_delivery_failed', 'The report finished, but it could not be delivered to its recipients.');
            }
        }
    }

    /**
     * A dead worker (the job handed out again past retry_after) or a run gone quiet
     * (reports:dispatch-due): the run is failed and whatever it wrote removed.
     */
    public function failed(?Throwable $e): void
    {
        $run = ReportRun::query()->find($this->runId);
        if ($run !== null) {
            $this->failRun($run, 'The report stopped before it finished.');
        }
    }

    /**
     * Fails a run that has not finished, once: a run that is already done or failed is
     * left as it is, files included. A scheduled run's owner is told in-app; $paused
     * (its token was refused) also pauses the schedule.
     */
    private function failRun(ReportRun $run, string $error, bool $paused = false): void
    {
        $failed = ReportRun::query()->whereKey($run->id)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'failed', 'error' => $error, 'finished_at' => now(), 'updated_at' => now()]);

        if ($failed === 0) {
            return;
        }
        $run->deleteFiles();

        if ($run->schedule_id === null) {
            return;
        }
        if ($paused) {
            ReportSchedule::query()->whereKey($run->schedule_id)->update(['is_active' => false, 'updated_at' => now()]);
        }

        $this->notifyOwner(
            $run,
            $paused ? 'report_schedule_paused' : 'report_failed',
            $paused ? 'Schedule paused: its token is no longer valid. Resume it to renew.' : $error,
        );
    }

    /** An in-app notice to the scheduled run's owner, pointing at the run. */
    private function notifyOwner(ReportRun $run, string $type, string $body): void
    {
        $this->recordEvent('notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [[
                'id' => $run->requested_by_user_id,
                'data' => [
                    'type' => $type,
                    'title' => $run->template?->name ?? 'Report',
                    'body' => $body,
                    'action_url' => $this->runUrl($run),
                ],
            ]],
        ]);
    }

    /** A dataset service answered 401: the token is not (or no longer) valid. */
    private function refused(Throwable $e): bool
    {
        $previous = $e->getPrevious();

        return $e instanceof HttpException && $previous instanceof RequestException && $previous->response->status() === 401;
    }

    /**
     * A finished scheduled run to its recipients: in-app to each by id, by email to
     * each with an address in the users replica.
     */
    private function deliver(ReportRun $run): void
    {
        $schedule = $run->schedule;
        $recipients = array_values(array_unique(array_map('intval', $schedule?->recipients['users'] ?? [])));
        if ($recipients === []) {
            return;
        }

        $name = $run->template?->name ?? 'Report';
        $from = $run->period_from->toDateString();
        $to = $run->period_to->toDateString();
        $period = $from === $to ? $from : "{$from} – {$to}";
        $rows = (int) $run->row_count;
        $url = $this->runUrl($run);

        $this->recordEvent('notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => array_map(fn (int $id) => [
                'id' => $id,
                'data' => [
                    'type' => 'report_ready',
                    'title' => $name,
                    'body' => "{$period} · ".number_format($rows).' '.($rows === 1 ? 'row' : 'rows'),
                    'action_url' => $url,
                ],
            ], $recipients),
        ]);

        $emails = User::query()->whereIn('id', $recipients)->whereNotNull('email')->where('email', '!=', '')->pluck('email', 'id');
        $emails = array_values(array_filter(array_map(fn (int $id) => $emails[$id] ?? null, $recipients)));
        if ($emails === []) {
            return;
        }

        $attach = $schedule->delivery !== 'link';
        $fits = (int) $run->file_size <= self::MAX_ATTACHMENT_BYTES;
        $data = [
            'report_name' => $name,
            'period_label' => $period,
            'row_count' => $rows,
            'run_url' => $url,
            'expires_on' => $run->expires_at->setTimezone($schedule->timezone)->toDateString(),
            // The email then says the file was too big to attach.
            ...($attach && ! $fits ? ['attachment_skipped' => true] : []),
        ];

        $this->recordEvent('notifications.v1.email.send', [
            'template' => 'report_ready',
            'subject' => "{$name} · {$period}",
            'users' => array_map(fn (string $email) => ['email' => $email, 'data' => $data], $emails),
            ...($attach && $fits ? ['attachments' => [[
                // A 24 h signed link, fetched by NotificationsPizza.
                'url' => rtrim((string) config('app.url'), '/').URL::temporarySignedRoute(
                    'report-builder.files',
                    now()->addDay(),
                    ['run' => $run->id, 'format' => $run->format],
                    absolute: false,
                ),
                'name' => $run->fileName($run->format),
                'mime' => $run->format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv',
                'size' => (int) $run->file_size,
            ]]] : []),
        ]);
    }

    /** The run's page in the dashboard, which sends the user on to their own locale. */
    private function runUrl(ReportRun $run): string
    {
        return rtrim((string) config('services.report_datasets.dashboard_url'), '/')."/dashboard/report-builder/runs/{$run->id}";
    }

    private function recordEvent(string $subject, array $data): void
    {
        $factory = app(DataEventFactory::class);
        $outbox = app(DataOutboxService::class);

        $envelope = $factory->make($subject, $data);
        $row = $outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }

    /**
     * What the user is told. The engine's own errors are worded for them; anything
     * else gets a fixed sentence and goes to the log, never onto the run.
     */
    private function message(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return 'The template no longer fits the catalog: '.$e->getMessage();
        }
        if (($e instanceof HttpException || $e instanceof InvalidArgumentException) && $e->getMessage() !== '') {
            return $e->getMessage();
        }

        report($e);

        return 'The report could not be produced.';
    }
}
