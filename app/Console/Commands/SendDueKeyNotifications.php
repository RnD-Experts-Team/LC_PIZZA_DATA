<?php

namespace App\Console\Commands;

use App\Jobs\PublishOutboxEventJob;
use App\Models\KeyStoreRule;
use App\Models\UserStoreRole;
use App\Services\DataEntry\ScheduleEvaluationService;
use Illuminate\Console\Command;
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
            $isDue = $schedule->isMonthlyAnyDayRule($rule)
                ? $schedule->monthlyIsApplicableThisMonth($rule, $today)
                : $schedule->isDueOnDate($rule, $today);

            if (!$isDue) {
                continue;
            }

            $dueTimeForMessage = $rule->due_time ?? 'end of day';

            DB::transaction(function () use ($rule, $today, $dueTimeForMessage, $now) {
                $userIds = $this->getTargetUserIds($rule);

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

                                'title' => 'Data entry key due soon',
                                'body' => "Key {$rule->key?->label} is due at {$dueTimeForMessage}.",

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
}