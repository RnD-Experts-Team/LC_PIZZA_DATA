<?php

namespace App\Console\Commands\Reports;

use App\Jobs\PublishOutboxEventJob;
use App\Jobs\RunReportJob;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Reports\Engine\PeriodResolver;
use App\Services\DataEvents\DataEventFactory;
use App\Services\DataEvents\DataOutboxService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Starts every active schedule whose next run is due: a queued run for the
 * owner, with the period read in the schedule's timezone, handed to
 * RunReportJob with the schedule's token. Each schedule is its own transaction,
 * so one that cannot be handled never holds up the others.
 *
 * The next run moves on when a run was started (one missed while the scheduler
 * was down runs once, not once per missed slot) or the schedule was paused. A
 * schedule whose owner already has a run going, or is asking for one this
 * instant, is left due and tried again next minute.
 *
 * A schedule is paused, and its owner told in-app, when its template is no
 * longer readable by the owner, its token lapses within a week, or its token
 * cannot be decrypted.
 *
 * First, a run still `running` with no heartbeat for STALE_MINUTES is failed as
 * a job that died would be (its files and staging database removed, a
 * scheduled run's owner told): runs have no time limit, so this is how a
 * worker that was killed is noticed. Prints nothing.
 */
class DispatchDueReports extends Command
{
    protected $signature = 'reports:dispatch-due';

    protected $description = 'Start the report schedules that are due';

    private const STALE_MINUTES = 30;

    public function handle(PeriodResolver $periods): int
    {
        ReportRun::query()->where('status', 'running')
            ->where(fn ($q) => $q->where('heartbeat_at', '<', now()->subMinutes(self::STALE_MINUTES))
                ->orWhere(fn ($q) => $q->whereNull('heartbeat_at')->where('started_at', '<', now()->subMinutes(self::STALE_MINUTES))))
            ->pluck('id')
            ->each(fn (string $id) => (new RunReportJob($id, ''))->failed(null));

        $due = ReportSchedule::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($due as $id) {
            try {
                DB::transaction(fn () => $this->dispatch($id, $periods));
            } catch (DecryptException) {
                $schedule = ReportSchedule::query()->with('template:id,name')->find($id);
                if ($schedule !== null) {
                    DB::transaction(fn () => $this->pause($schedule, 'Schedule paused: its token could not be read. Resume it to renew.', $schedule->template?->name ?? 'Report'));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }

    /** One due schedule: a run for the owner, unless they already have one going. */
    private function dispatch(int $id, PeriodResolver $periods): void
    {
        $schedule = ReportSchedule::query()->with('template')->lockForUpdate()->find($id);
        if ($schedule === null || ! $schedule->is_active || $schedule->next_run_at === null || $schedule->next_run_at->isFuture()) {
            return;
        }

        $owner = $schedule->owner_user_id;
        $template = $schedule->template;

        if (! ReportTemplate::query()->readableBy($owner)->whereKey($schedule->template_id)->exists()) {
            // Not named: the owner can no longer read this template.
            $this->pause($schedule, 'Schedule paused: the template is no longer available to you.', 'Report');

            return;
        }
        if ($schedule->token_expires_at !== null && $schedule->token_expires_at->lte(now()->addDays(7))) {
            $this->pause($schedule, 'Schedule paused: its token expires soon. Resume it to renew.', $template->name);

            return;
        }

        try {
            [$from, $to] = $periods->resolve($template->definition['period'] ?? [], $schedule->timezone);
        } catch (InvalidArgumentException) {
            $schedule->update(['next_run_at' => $schedule->computeNextRunAt(now())]);

            return;
        }

        // Read before anything is written: an unreadable token (DecryptException) rolls this schedule back.
        $token = (string) $schedule->run_token;

        // The same lock as a run asked for by hand, so the two never both start one.
        $run = Cache::lock("report-builder:run:{$owner}", 10)->get(function () use ($schedule, $template, $owner, $from, $to) {
            if (ReportRun::query()->where('requested_by_user_id', $owner)->whereIn('status', ['queued', 'running'])->exists()) {
                return null;
            }

            return ReportRun::query()->create([
                'template_id' => $template->id,
                'schedule_id' => $schedule->id,
                'requested_by_user_id' => $owner,
                'definition' => $template->definition,
                'period_from' => $from,
                'period_to' => $to,
                'format' => $schedule->format,
                'status' => 'queued',
                'expires_at' => now()->addDays(ReportRun::KEEP_DAYS),
            ]);
        });

        // Skipped (the owner is busy, or the lock is held): still due, tried again next minute.
        if (! $run instanceof ReportRun) {
            return;
        }

        $schedule->update(['next_run_at' => $schedule->computeNextRunAt(now())]);
        // Queued once the run row is committed, so no worker looks for it before it exists.
        RunReportJob::dispatch($run->id, $token)->afterCommit();
    }

    /** Stops the schedule, moves its next run on and tells the owner in-app why. */
    private function pause(ReportSchedule $schedule, string $body, string $title): void
    {
        $schedule->update(['is_active' => false, 'next_run_at' => $schedule->computeNextRunAt(now())]);

        $this->recordEvent('notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [[
                'id' => $schedule->owner_user_id,
                'data' => [
                    'type' => 'report_schedule_paused',
                    'title' => $title,
                    'body' => $body,
                    'action_url' => rtrim((string) config('services.report_datasets.dashboard_url'), '/').'/dashboard/report-builder?tab=schedules',
                ],
            ]],
        ]);
    }

    private function recordEvent(string $subject, array $data): void
    {
        $factory = app(DataEventFactory::class);
        $outbox = app(DataOutboxService::class);

        $envelope = $factory->make($subject, $data);
        $row = $outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id)->afterCommit();
    }
}
