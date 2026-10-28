<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\DataEntry\DueKeyResolverService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * GET /api/calendar/feed?stores=03795-00001[,…]&from=YYYY-MM-DD&to=YYYY-MM-DD
 *
 * Data's part of the unified calendar (calendar-feed/v1):
 *   entry_day_summary  per store and day — the keys due that day, as counts
 *   weekly_goal        per goal and week
 *   daily_sales        per store and day — sales and customers against the
 *                      SAME WEEKDAY 364 days earlier (as DSPR compares), future
 *                      days included so the manager sees last year's number
 *
 * Which keys are due comes from DueKeyResolverService, unchanged — the same rules
 * the data-entry screen uses. Sales are `royalty_obligation`, the number DSPR
 * shows as "Sales".
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

    /** 52 weeks: the same weekday last year. */
    private const LAST_YEAR_DAYS = 364;

    public function __construct(private readonly DueKeyResolverService $due)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stores' => ['required', 'string'],
            'from'   => ['required', 'date_format:Y-m-d'],
            'to'     => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $stores = array_values(array_unique(array_filter(array_map('trim', explode(',', $data['stores'])))));
        if ($stores === [] || count($stores) > self::MAX_STORES) {
            throw ValidationException::withMessages(['stores' => 'Send 1 to ' . self::MAX_STORES . ' store numbers.']);
        }
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'Date range may not exceed ' . self::MAX_DAYS . ' days.']);
        }

        $from = Carbon::parse($data['from'], 'UTC')->startOfDay();
        $to = Carbon::parse($data['to'], 'UTC')->startOfDay();

        $events = [];
        foreach ($stores as $store) {
            array_push($events, ...$this->entries($store, $from, $to));
        }
        array_push($events, ...$this->goals($stores, $from, $to));
        array_push($events, ...$this->sales($stores, $from, $to));

        usort($events, fn ($a, $b) => [$a['date'], $a['id']] <=> [$b['date'], $b['id']]);

        return response()->json([
            'contract'     => 'calendar-feed/v1',
            'source'       => 'data',
            'generated_at' => $this->utc(now()),
            'range'        => ['from' => $data['from'], 'to' => $data['to']],
            'stores'       => array_map(fn ($n) => ['number' => $n, 'timezone' => 'UTC'], $stores),
            'events'       => $events,
            'meta'         => ['count' => count($events), 'truncated' => false],
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function entries(string $store, Carbon $from, Carbon $to): array
    {
        $today = Carbon::now('UTC')->toDateString();
        $events = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $date = $day->toDateString();
            $counts = ['done' => 0, 'pending' => 0, 'overdue' => 0];

            foreach ($this->due->dueForStoreOnDate($store, $day) as $item) {
                // "Any day this month" keys are listed on every day of the month
                // until filled; counting them daily would repeat one key 30 times.
                if (($item['mode'] ?? null) === 'monthly_any_day') {
                    continue;
                }
                $status = $item['filled'] ? 'done' : ($date < $today ? 'overdue' : 'pending');
                $counts[$status]++;
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
