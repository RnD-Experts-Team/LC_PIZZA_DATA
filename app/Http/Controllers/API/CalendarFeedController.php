<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\KeyStoreRule;
use App\Services\DataEntry\ScheduleEvaluationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD[&group=day]
 *
 * `group=day` (year view, all-stores grid): instead of `events`, `days` — each
 * store's due keys per day as done / pending / overdue — for up to 371 days.
 * Sales and goals are `info`, so they are not counted (and not read).
 *
 * Data's part of the unified calendar (calendar-feed/v1):
 *   entry_day_summary  per store and day — the keys due that day, as counts
 *   weekly_goal        per goal and week
 *   daily_sales        per store and day — sales and customers against the
 *                      SAME WEEKDAY 364 days earlier (as DSPR compares), future
 *                      days included so the manager sees last year's number
 *
 * Which keys are due comes from ScheduleEvaluationService, unchanged — the same
 * rules the data-entry screen uses — but everything it needs is read in three
 * queries for all stores and days at once (asking DueKeyResolverService day by
 * day took ~10 queries per store and day: 15 s for every store over a month).
 * A `role_each` key counts everyone who holds the role in the store, whoever
 * is looking. Sales are `royalty_obligation`, the number DSPR shows as "Sales".
 *
 * Who may see which stores is pizzasys's decision: the auth middleware forwards
 * the query string and the rule for this route reads `stores` from it (policy
 * "all"). Stores here are plain strings with no table to check against, so an
 * unknown number simply returns nothing.
 *
 * Times are UTC. Never sent: the entered values themselves, attachments.
 */
class CalendarFeedController extends Controller
{
    private const MAX_STORES = 50;

    private const MAX_DAYS = 62;

    /** With group=day: a year — a 53-week fiscal year is 371 days. */
    private const MAX_DAYS_GROUPED = 371;

    /** The statuses a day counts; `info` and cancelled are not counted. */
    private const COUNTED = ['done' => 1, 'pending' => 1, 'overdue' => 1, 'missed' => 1, 'in_progress' => 1, 'scheduled' => 1];

    /** 52 weeks: the same weekday last year. */
    private const LAST_YEAR_DAYS = 364;

    public function __construct(private readonly ScheduleEvaluationService $schedule)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group'  => ['nullable', 'in:day'],
        ]);
        $grouped = ($data['group'] ?? null) === 'day';
        $maxDays = $grouped ? self::MAX_DAYS_GROUPED : self::MAX_DAYS;

        $stores = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($stores === [] || count($stores) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > $maxDays) {
            throw ValidationException::withMessages(['to' => "Date range may not exceed {$maxDays} days."]);
        }

        $from = Carbon::parse($data['from'], 'UTC')->startOfDay();
        $to = Carbon::parse($data['to'], 'UTC')->startOfDay();

        $events = $this->entries($stores, $from, $to);
        if (! $grouped) { // goals and sales are `info`: nothing for a day to count
            array_push($events, ...$this->goals($stores, $from, $to));
            array_push($events, ...$this->sales($stores, $from, $to));
        }

        usort($events, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        $body = $grouped
            ? ['group' => 'day', 'days' => $this->days($events)]
            : ['events' => $events];

        return response()->json([
            'contract'     => 'calendar-feed/v1',
            'source'       => 'data',
            'generated_at' => $this->utc(now()),
            'range'        => ['from' => $data['from'], 'to' => $data['to']],
            'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $stores),
            ...$body,
            'meta'         => ['count' => count($body['days'] ?? $body['events']), 'truncated' => false],
        ]);
    }

    /**
     * group=day: per store and day, how many things there are in each status.
     * A summary event adds its own counts; `info`, cancelled and store-less
     * events are not counted; days with nothing are left out.
     *
     * @return list<array{store: string, date: string, counts: array<string, int>}>
     */
    private function days(array $events): array
    {
        $days = [];
        foreach ($events as $e) {
            $add = isset($e['counts'])
                ? array_intersect_key($e['counts'], self::COUNTED)
                : (isset(self::COUNTED[$e['status']]) ? [$e['status'] => 1] : []);
            if ($e['store'] === null || array_sum($add) === 0) {
                continue;
            }
            $day = &$days[$e['date'] . '|' . $e['store']];
            $day ??= ['store' => $e['store'], 'date' => $e['date'], 'counts' => []];
            foreach (array_filter($add) as $status => $n) {
                $day['counts'][$status] = ($day['counts'][$status] ?? 0) + $n;
            }
            unset($day);
        }
        ksort($days);

        foreach ($days as &$day) {
            $day['counts']['total'] = array_sum($day['counts']);
        }
        unset($day);

        return array_values($days);
    }

    /** @return array<int, array<string, mixed>> */
    private function entries(array $stores, Carbon $from, Carbon $to): array
    {
        // 1. Each active key's rule in these stores — the first one per key and
        //    store, as the data-entry screen takes it. "Any day this month" keys
        //    are left out: they would repeat one key on every day of the month.
        $rules = KeyStoreRule::query()
            ->whereIn('store_id', $stores)
            ->whereIn('key_id', fn ($q) => $q->select('id')->from('entered_keys')->where('is_active', true))
            ->orderBy('id')
            ->get()
            ->unique(fn ($r) => $r->store_id . '|' . $r->key_id)
            ->reject(fn ($r) => $this->schedule->isMonthlyAnyDayRule($r))
            ->groupBy('store_id');

        if ($rules->isEmpty()) {
            return [];
        }

        // 2. Who filled what: "store|key|date" => user ids. Mistaken values do not
        //    count. Streamed into a plain array: a year of every store is a lot of rows.
        $filled = [];
        foreach (DB::table('entered_key_values')
            ->whereIn('store_id', $stores)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->where('is_mistaken', false)
            ->select(['store_id', 'key_id', 'entry_date', 'user_id'])
            ->cursor() as $v) {
            $who = &$filled[$v->store_id . '|' . $v->key_id . '|' . substr((string) $v->entry_date, 0, 10)];
            $who ??= [];
            if ($v->user_id !== null) {
                $who[(int) $v->user_id] = (int) $v->user_id;
            }
            unset($who);
        }

        // 3. For `role_each` keys: everyone active with the role, per store — "store|role" => user ids.
        $holders = $rules->flatten()->contains('fill_mode', 'role_each')
            ? DB::table('user_store_roles')
                ->join('users', 'users.id', '=', 'user_store_roles.user_id')
                ->whereIn('user_store_roles.store_id', $stores)
                ->where('user_store_roles.active', true)
                ->distinct()
                ->get(['user_store_roles.store_id', 'user_store_roles.role_name', 'user_store_roles.user_id'])
                ->groupBy(fn ($r) => $r->store_id . '|' . $r->role_name)
                ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all())
            : collect();

        // "Is it due on this day?" depends only on the rule's schedule, and most
        // stores share the same schedule for a key — so each schedule is asked
        // once per day, not once per store (a year of every store: ~200k → ~5k).
        $shape = fn (KeyStoreRule $r) => implode('|', [
            $r->frequency_type, $r->interval, json_encode($r->week_days), $r->month_day, $r->week_of_month,
            $r->week_day, $r->year_month, $r->starts_at?->toDateString(), $r->ends_at?->toDateString(),
        ]);
        $shapes = []; // rule (object id) => its schedule
        $dueCache = []; // schedule => date => due?
        $isDue = function (KeyStoreRule $rule, Carbon $day, string $date) use (&$shapes, &$dueCache, $shape) {
            $key = $shapes[spl_object_id($rule)] ??= $shape($rule);

            return $dueCache[$key][$date] ??= $this->schedule->isDueOnDate($rule, $day);
        };

        $today = Carbon::now('UTC')->toDateString();
        $events = [];

        foreach ($stores as $store) {
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $date = $day->toDateString();
                $late = $date < $today;
                $counts = ['done' => 0, 'pending' => 0, 'overdue' => 0];

                foreach ($rules->get($store, []) as $rule) {
                    if (! $isDue($rule, $day, $date)) {
                        continue;
                    }

                    $who = $filled["{$store}|{$rule->key_id}|{$date}"] ?? null;

                    if ($rule->fill_mode === 'store_once') {
                        $counts[$who !== null ? 'done' : ($late ? 'overdue' : 'pending')]++;
                        continue;
                    }

                    // role_each: one item per person who holds one of the roles.
                    $required = collect($rule->role_names ?? [])
                        ->flatMap(fn ($role) => $holders->get("{$store}|{$role}", []))
                        ->unique();
                    foreach ($required as $userId) {
                        $counts[in_array($userId, $who ?? [], true) ? 'done' : ($late ? 'overdue' : 'pending')]++;
                    }
                }

                $total = array_sum($counts);
                if ($total === 0) {
                    continue;
                }

                $events[] = $this->event(
                    id: "data:entry_day_summary:{$store}:{$date}",
                    type: 'entry_day_summary',
                    layer: 'data',
                    store: $store,
                    title: "Data {$counts['done']}/{$total}" . ($counts['overdue'] ? " · {$counts['overdue']} overdue" : ''),
                    date: $date,
                    dateEnd: null,
                    status: $counts['overdue'] ? 'overdue' : ($counts['pending'] ? 'pending' : 'done'),
                    link: '/data-entry?' . http_build_query(['store' => $store, 'date' => $date]),
                    extra: [],
                    counts: $counts + ['total' => $total],
                );
            }
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function goals(array $stores, Carbon $from, Carbon $to): array
    {
        return DB::table('goals as g')
            ->join('goal_metrics as m', 'm.id', '=', 'g.goal_metric_id')
            ->whereIn('g.store_id', $stores)
            ->where('g.week_start_date', '<=', $to->toDateString())
            ->where('g.week_end_date', '>=', $from->toDateString())
            ->orderBy('g.week_start_date')->orderBy('m.name')
            ->get(['g.id', 'g.store_id', 'g.week_start_date', 'g.week_end_date', 'g.goal', 'g.updated_at', 'm.name'])
            ->map(fn ($g) => $this->event(
                id: "data:weekly_goal:{$g->id}",
                type: 'weekly_goal',
                layer: 'company',
                store: $g->store_id,
                title: "Goal: {$g->name} " . rtrim(rtrim(number_format((float) $g->goal, 2, '.', ','), '0'), '.'),
                date: Carbon::parse($g->week_start_date)->toDateString(),
                dateEnd: Carbon::parse($g->week_end_date)->toDateString(),
                status: 'info',
                link: '/goals?' . http_build_query(['store' => $g->store_id, 'week_start' => Carbon::parse($g->week_start_date)->toDateString()]),
                extra: ['metric' => $g->name, 'goal' => (float) $g->goal],
                updatedAt: $g->updated_at,
            ))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function sales(array $stores, Carbon $from, Carbon $to): array
    {
        $lyFrom = $from->copy()->subDays(self::LAST_YEAR_DAYS);
        $lyTo = $to->copy()->subDays(self::LAST_YEAR_DAYS);

        // "store|date" => row, for this year's range and last year's together.
        $rows = DB::connection('aggregation')->table('daily_store_summary')
            ->whereIn('franchise_store', $stores)
            ->where(fn ($q) => $q
                ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
                ->orWhereBetween('business_date', [$lyFrom->toDateString(), $lyTo->toDateString()]))
            ->get(['franchise_store', 'business_date', 'royalty_obligation', 'customer_count'])
            ->keyBy(fn ($r) => $r->franchise_store . '|' . Carbon::parse($r->business_date)->toDateString());

        $events = [];

        foreach ($stores as $store) {
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                $date = $day->toDateString();
                $lyDate = $day->copy()->subDays(self::LAST_YEAR_DAYS)->toDateString();
                $now = $rows->get("{$store}|{$date}");
                $ly = $rows->get("{$store}|{$lyDate}");

                // A store with no sales history at all gets no sales events.
                if ($now === null && $ly === null) {
                    continue;
                }

                $sales = $now ? round((float) $now->royalty_obligation, 2) : null;
                $lySales = $ly ? round((float) $ly->royalty_obligation, 2) : null;
                $change = ($sales !== null && $lySales) ? round(($sales - $lySales) / $lySales * 100, 1) : null;

                $title = $sales !== null
                    ? 'Sales $' . number_format($sales) . ($change !== null ? ($change >= 0 ? ' ▲ ' : ' ▼ ') . abs($change) . '%' : '')
                    : 'LY $' . number_format((float) $lySales);

                $events[] = $this->event(
                    id: "data:daily_sales:{$store}:{$date}",
                    type: 'daily_sales',
                    layer: 'sales',
                    store: $store,
                    title: $title,
                    date: $date,
                    dateEnd: null,
                    status: 'info',
                    link: '/reports/dspr?' . http_build_query(['store' => $store, 'date' => $date]),
                    extra: [
                        'sales'        => $sales,
                        'customers'    => $now ? (int) $now->customer_count : null,
                        'ly_date'      => $lyDate,
                        'ly_sales'     => $lySales,
                        'ly_customers' => $ly ? (int) $ly->customer_count : null,
                        'change_pct'   => $change,
                        'final'        => $now !== null,
                        'currency'     => 'USD',
                    ],
                );
            }
        }

        return $events;
    }

    /** @return array<string, mixed> */
    private function event(
        string $id,
        string $type,
        string $layer,
        string $store,
        string $title,
        string $date,
        ?string $dateEnd,
        string $status,
        string $link,
        array $extra,
        ?array $counts = null,
        $updatedAt = null,
    ): array {
        $event = [
            'id'         => $id,
            'type'       => $type,
            'layer'      => $layer,
            'store'      => $store,
            'title'      => mb_substr($title, 0, 120),
            'all_day'    => true,
            'date'       => $date,
            'date_end'   => $dateEnd,
            'start'      => null,
            'end'        => null,
            'status'     => $status,
            'people'     => [],
            'link'       => $link,
            'updated_at' => $this->utc($updatedAt ?? now()),
        ];

        // Left out when empty: PHP would send [] where the contract expects an object.
        if ($extra !== []) {
            $event['extra'] = $extra;
        }

        if ($counts !== null) {
            $event['counts'] = $counts;
        }

        return $event;
    }

    private function utc($at): ?string
    {
        return $at ? Carbon::parse($at, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
