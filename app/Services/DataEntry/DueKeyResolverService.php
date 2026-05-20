<?php

namespace App\Services\DataEntry;

use App\Models\EnteredKey;
use App\Models\EnteredKeyValue;
use App\Models\UserStoreRole;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DueKeyResolverService
{
    public function __construct(
        private readonly ScheduleEvaluationService $schedule,
    ) {
    }

    /**
     * Return list of keys needed for store on date with filled status.
     */
    public function dueForStoreOnDate(
        string $storeId,
        Carbon $date,
        array $tagIds = []
    ): Collection {
        $date = $date->copy()->startOfDay();
        $authUserId = auth()->id();

        $keys = EnteredKey::query()
            ->where('is_active', true)
            ->when(!empty($tagIds), function ($q) use ($tagIds) {
                $q->whereHas('tags', function ($t) use ($tagIds) {
                    $t->whereIn('tags.id', $tagIds);
                });
            })
            ->with([
                'storeRules' => fn($q) => $q->where('store_id', $storeId),
                'tags'
            ])
            ->get();

        $valuesToday = EnteredKeyValue::query()
            ->where('store_id', $storeId)
            ->whereDate('entry_date', $date)
            ->with('attachments', 'user')
            ->get();

        $valuesByKey = $valuesToday->groupBy('key_id');

        $monthStart = $date->copy()->startOfMonth();
        $monthEnd = $date->copy()->endOfMonth();

        $valuesThisMonth = EnteredKeyValue::query()
            ->where('store_id', $storeId)
            ->whereBetween('entry_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->with('attachments', 'user')
            ->get()
            ->groupBy('key_id');

        $out = collect();

        foreach ($keys as $key) {
            $rule = $key->storeRules->first();
            if (!$rule) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Monthly Any Day Mode
            |--------------------------------------------------------------------------
            */

            if ($this->schedule->isMonthlyAnyDayRule($rule)) {

                if (!$this->schedule->monthlyIsApplicableThisMonth($rule, $date)) {
                    continue;
                }

                $existingValues = $valuesThisMonth->get($key->id, collect());

                if ($rule->fill_mode === 'store_once') {

                    $value = $existingValues->sortByDesc('entry_date')->first();
                    $filled = $value !== null;

                    $out->push([
                        'key_id' => $key->id,
                        'label' => $key->label,
                        'data_type' => $key->data_type,
                        'frequency_type' => $rule->frequency_type,
                        'interval' => (int) $rule->interval,
                        'due_time' => $rule->due_time,
                        'mode' => 'monthly_any_day',
                        'fill_mode' => $rule->fill_mode,
                        'user_id' => $value?->user_id,
                        'user_name' => $value?->user?->name,
                        'filled' => $filled,
                        'value' => $this->serializeValueWithUserName($value),
                        'tags' => $key->tags,  // Add tags to the output
                    ]);

                } else {

                    $roles = $rule->role_names ?? [];
                    $valueUserIds = $existingValues->pluck('user_id')->filter()->unique();

                    $users = $this->resolveUsersForRule(
                        $storeId,
                        $roles,
                        (int) $authUserId,
                        $valueUserIds
                    );

                    foreach ($users as $userRole) {

                        $value = $existingValues
                            ->filter(function ($v) use ($userRole) {
                                return (int) $v->user_id === (int) $userRole->user_id;
                            })
                            ->sortByDesc('entry_date')
                            ->first();

                        $out->push([
                            'key_id' => $key->id,
                            'label' => $key->label,
                            'data_type' => $key->data_type,

                            'frequency_type' => $rule->frequency_type,
                            'interval' => (int) $rule->interval,
                            'due_time' => $rule->due_time,

                            'mode' => 'monthly_any_day',
                            'fill_mode' => $rule->fill_mode,

                            'user_id' => $userRole->user_id,
                            'user_name' => $userRole->user_name,
                            'role_name' => $userRole->role_name,

                            'filled' => $value !== null,
                            'value' => $this->serializeValueWithUserName($value),
                            'tags' => $key->tags,  // Add tags to the output
                        ]);
                    }

                }

                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Normal Scheduled Rules
            |--------------------------------------------------------------------------
            */

            if (!$this->schedule->isDueOnDate($rule, $date)) {
                continue;
            }

            $existingValues = $valuesByKey->get($key->id, collect());

            if ($rule->fill_mode === 'store_once') {

                $value = $existingValues->first();

                $out->push([
                    'key_id' => $key->id,
                    'label' => $key->label,
                    'data_type' => $key->data_type,
                    'frequency_type' => $rule->frequency_type,
                    'interval' => (int) $rule->interval,
                    'due_time' => $rule->due_time,
                    'mode' => 'date_specific',
                    'fill_mode' => $rule->fill_mode,
                    'user_id' => $value?->user_id,
                    'user_name' => $value?->user?->name,
                    'filled' => $value !== null,
                    'value' => $this->serializeValueWithUserName($value),
                    'tags' => $key->tags,  // Add tags to the output
                ]);

            } else {

                $roles = $rule->role_names ?? [];
                $valueUserIds = $existingValues->pluck('user_id')->filter()->unique();

                $users = $this->resolveUsersForRule(
                    $storeId,
                    $roles,
                    (int) $authUserId,
                    $valueUserIds
                );

                foreach ($users as $userRole) {

                    $value = $existingValues->first(function ($v) use ($userRole) {
                        return (int) $v->user_id === (int) $userRole->user_id;
                    });

                    $out->push([
                        'key_id' => $key->id,
                        'label' => $key->label,
                        'data_type' => $key->data_type,

                        'frequency_type' => $rule->frequency_type,
                        'interval' => (int) $rule->interval,
                        'due_time' => $rule->due_time,

                        'mode' => 'date_specific',
                        'fill_mode' => $rule->fill_mode,

                        'user_id' => $userRole->user_id,
                        'user_name' => $userRole->user_name,
                        'role_name' => $userRole->role_name,

                        'filled' => $value !== null,
                        'value' => $this->serializeValueWithUserName($value),
                        'tags' => $key->tags,  // Add tags to the output
                    ]);
                }
            }

        }

        return $out->values();
    }

    private function serializeValueWithUserName(?EnteredKeyValue $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $payload = $value->toArray();
        $payload['user_name'] = $value->user?->name;

        return $payload;
    }

    private function resolveUsersForRule(
        string $storeId,
        array $roles,
        int $authUserId,
        Collection $valueUserIds
    ): Collection {
        $baseQuery = UserStoreRole::query()
            ->join('users', 'users.id', '=', 'user_store_roles.user_id')
            ->where('user_store_roles.store_id', $storeId)
            ->where('user_store_roles.active', true)
            ->whereIn('user_store_roles.role_name', $roles)
            ->select([
                'user_store_roles.user_id',
                'user_store_roles.role_name',
                'users.name as user_name',
            ])
            ->distinct();

        $authUsers = $authUserId
            ? (clone $baseQuery)->where('user_store_roles.user_id', $authUserId)->get()
            : collect();

        $filledUsers = $valueUserIds->isNotEmpty()
            ? (clone $baseQuery)->whereIn('user_store_roles.user_id', $valueUserIds->all())->get()
            : collect();

        return $authUsers
            ->merge($filledUsers)
            ->unique('user_id')
            ->values();
    }

    /**
     * Due range for dashboards
     */
    public function dueRange(
        string $storeId,
        Carbon $from,
        Carbon $to,
        array $tagIds = []
    ): array {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();

        $result = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {

            $result[$cursor->toDateString()] = $this
                ->dueForStoreOnDate($storeId, $cursor, $tagIds)
                ->all();

            $cursor->addDay();
        }

        return $result;
    }
}