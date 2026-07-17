<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEventJob;
use App\Models\EnteredKeyValue;
use App\Models\KeyStoreRule;
use App\Models\UserStoreRole;
use App\Services\DataEntry\ScheduleEvaluationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\DataEvents\DataEventFactory;
use App\Services\DataEvents\DataOutboxService;

class SendDueKeyNotifications extends Command
{
    protected $signature = 'keys:send-due-notifications';

    protected $description = 'Send notification 30 minutes before key due time';

    public function handle(ScheduleEvaluationService $schedule): int
    {
        $now = now('America/New_York');
        $today = $now->copy()->startOfDay();

        $targetTime = $now->copy()->addMinutes(30)->format('H:i:00');
        $nullDueNotificationTime = $today->copy()->addDay()->startOfDay()->subMinutes(30)->format('H:i:00');
        $includeNullDueTime = $targetTime === $nullDueNotificationTime;

        $rules = KeyStoreRule::query()
            ->with('key')
            ->where(function ($query) use ($targetTime, $includeNullDueTime) {
                $query->where('due_time', $targetTime);

                if ($includeNullDueTime) {
                    $query->orWhereNull('due_time');
                }
            })
            ->whereHas('key', function ($query) {
                $query->where('is_active', true);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('last_notified_at')
                    ->orWhereDate('last_notified_at', '!=', $today->toDateString());
            })
            ->get();

        foreach ($rules as $rule) {
            $isMonthlyAnyDay = $schedule->isMonthlyAnyDayRule($rule);

            $isDue = $isMonthlyAnyDay
                ? $schedule->monthlyIsApplicableThisMonth($rule, $today)
                : $schedule->isDueOnDate($rule, $today);

            if (!$isDue) {
                continue;
            }

            $dueTimeForMessage = $rule->due_time ?? 'end of day';

            DB::transaction(function () use ($rule, $today, $dueTimeForMessage, $now, $isMonthlyAnyDay) {
                $filledUserIds = $this->getFilledUserIds($rule, $today, $isMonthlyAnyDay);

                if ($rule->fill_mode === 'store_once' && $filledUserIds->isNotEmpty()) {
                    return;
                }

                $userIds = $this->getTargetUserIds($rule);

                if ($rule->fill_mode === 'role_each') {
                    $userIds = $userIds->diff($filledUserIds)->values();
                }

                if ($userIds->isEmpty()) {
                    return;
                }

                $this->recordEvent($this->notificationSubject(), [
                    'channels' => ['web'],

                    'users' => $userIds->map(function ($userId) use ($rule, $today, $dueTimeForMessage) {
                        return [
                            'id' => (int) $userId,
                            'data' => [
                                'type' => 'data_entry_key_due_soon',

                                'title' => 'Debrief due soon',
                                'body' => "Debrief ({$rule->key?->label}) is due at {$dueTimeForMessage} For Store {$rule->store_id}.",
                                'action_url' => "/data-entries/keys/{$rule->key_id}?date={$today->toDateString()}&store_id={$rule->store_id}",
                            ],
                        ];
                    })->values()->all(),
                ]);

                $rule->update([
                    'last_notified_at' => $now->copy(),
                ]);
            });
        }

        return self::SUCCESS;
    }

    private function recordEvent(string $subject, array $data): void
    {
        $factory = app(DataEventFactory::class);
        $outbox = app(DataOutboxService::class);

        $envelope = $factory->make($subject, $data);
        $row = $outbox->record($subject, $envelope);

        PublishOutboxEventJob::dispatch($row->id);
    }

    private function notificationSubject(): string
    {
        return 'notifications.v1.notification.send';
    }

    private function getTargetUserIds($rule)
    {
        $query = UserStoreRole::query()
            ->where('active', true)
            ->where(function ($q) use ($rule) {
                $q->where('store_id', $rule->store_id)
                    ->orWhereNull('store_id');
            });

        if ($rule->fill_mode === 'role_each') {
            $query->whereIn('role_name', $rule->role_names ?? []);
        }

        return $query
            ->pluck('user_id')
            ->unique()
            ->values();
    }

    private function getFilledUserIds($rule, $today, bool $isMonthlyAnyDay): Collection
    {
        $query = EnteredKeyValue::query()
            ->where('key_id', $rule->key_id)
            ->where('store_id', $rule->store_id)
            ->current();

        if ($isMonthlyAnyDay) {
            $query->whereBetween('entry_date', [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ]);
        } else {
            $query->whereDate('entry_date', $today);
        }

        return $query
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values();
    }
}