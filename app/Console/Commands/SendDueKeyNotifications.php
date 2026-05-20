<?php

namespace App\Console\Commands;

use App\Jobs\PublishDataOutboxEventJob;
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

        $rules = KeyStoreRule::query()
            ->with('key')
            ->whereTime('due_time', $targetTime)
            ->where(function ($query) use ($today) {
                $query->whereNull('last_notified_at')
                    ->orWhereDate('last_notified_at', '!=', $today->toDateString());
            })
            ->get();

        foreach ($rules as $rule) {
            if (!$rule->key || !$rule->key->is_active) {
                continue;
            }

            $isDue = $schedule->isMonthlyAnyDayRule($rule)
                ? $schedule->monthlyIsApplicableThisMonth($rule, $today)
                : $schedule->isDueOnDate($rule, $today);

            if (!$isDue) {
                continue;
            }

            DB::transaction(function () use ($rule, $today, $targetTime) {
                $userIds = $this->getTargetUserIds($rule);

                if ($userIds->isEmpty()) {
                    return;
                }

                $this->recordEvent($this->notificationSubject(), [
                    'channels' => ['database'],

                    'users' => $userIds->map(function ($userId) use ($rule, $today, $targetTime) {
                        return [
                            'id' => (int) $userId,
                            'data' => [
                                'type' => 'data_entry_key_due_soon',

                                'title' => 'Data entry key due soon',
                                'message' => "Key {$rule->key?->label} is due at {$targetTime}.",

                                'key_id' => $rule->key_id,
                                'key_label' => $rule->key?->label,

                                'store_id' => $rule->store_id,
                                'fill_mode' => $rule->fill_mode,
                                'role_names' => $rule->role_names,

                                'frequency_type' => $rule->frequency_type,
                                'due_date' => $today->toDateString(),
                                'due_time' => $targetTime,

                                'notify_before_minutes' => 30,
                            ],
                        ];
                    })->values()->all(),
                ]);

                $rule->update([
                    'last_notified_at' => now('America/New_York'),
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

        DB::afterCommit(fn () => PublishDataOutboxEventJob::dispatch($row->id));
    }

    private function notificationSubject(): string
    {
        return config('nats.dev_mode')
            ? 'notifications.testing.v1.send'
            : 'notifications.v1.send';
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