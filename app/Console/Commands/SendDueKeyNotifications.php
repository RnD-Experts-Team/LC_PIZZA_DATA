<?php

namespace App\Console\Commands;

use App\Models\KeyStoreRuleTime;
use App\Models\UserStoreRole;
use App\Services\DataEntry\ScheduleEvaluationService;
use App\Services\Nats\NatsClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendDueKeyNotifications extends Command
{
    protected $signature = 'keys:send-due-notifications';

    protected $description = 'Send notification 30 minutes before key due time';

    public function handle(ScheduleEvaluationService $schedule): int
    {
        $now = now('America/New_York');
        $today = $now->copy()->startOfDay();

        $targetTime = $now->copy()->addMinutes(30)->format('H:i:00');

        $ruleTimes = KeyStoreRuleTime::query()
            ->with(['rule.key'])
            ->whereTime('due_time', $targetTime)
            ->where(function ($query) use ($today) {
                $query->whereNull('last_notified_for_date')
                    ->orWhereDate('last_notified_for_date', '!=', $today->toDateString());
            })
            ->get();

        foreach ($ruleTimes as $ruleTime) {
            $rule = $ruleTime->rule;

            if (!$rule || !$rule->key || !$rule->key->is_active) {
                continue;
            }

            $isDue = $schedule->isMonthlyAnyDayRule($rule)
                ? $schedule->monthlyIsApplicableThisMonth($rule, $today)
                : $schedule->isDueOnDate($rule, $today);

            if (!$isDue) {
                continue;
            }

            DB::transaction(function () use ($ruleTime, $rule, $today, $targetTime) {
                $userIds = $this->getTargetUserIds($rule);

                foreach ($userIds as $userId) {
                    $this->publishNotification($rule, $today, $targetTime, (int) $userId);
                }

                $ruleTime->update([
                    'last_notified_at' => now('America/New_York'),
                    'last_notified_for_date' => $today->toDateString(),
                ]);
            });
        }

        return self::SUCCESS;
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

    private function publishNotification($rule, $today, string $targetTime, int $userId): void
    {
        $payload = [
            'event' => 'data_entry_key_due_soon',

            'user_id' => $userId,

            'key_id' => $rule->key_id,
            'key_label' => $rule->key?->label,

            'store_id' => $rule->store_id,
            'fill_mode' => $rule->fill_mode,
            'role_names' => $rule->role_names,

            'frequency_type' => $rule->frequency_type,
            'due_date' => $today->toDateString(),
            'due_time' => $targetTime,

            'notify_before_minutes' => 30,
            'sent_at' => now('America/New_York')->toISOString(),
        ];

        app(NatsClientFactory::class)
            ->make()
            ->publish(
                'data-entry.key.due-soon',
                json_encode($payload)
            );
    }
}