<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Concerns;

use App\Models\Aggregation\DailyItemSummary;
use App\Models\Aggregation\DailyOrderExtras;
use App\Models\Aggregation\DailyStoreSummary;
use App\Models\Aggregation\HourlyStoreSummary;
use App\Models\CleaningReview;
use App\Models\CustomerService;
use App\Models\EnteredKeyValue;
use App\Models\GoToCall;
use App\Models\HnrPlusItem;
use App\Models\InventoryOrder;
use App\Models\NonNegotiableReport;
use App\Models\TransferInOut;
use App\Services\Analytics\EmployeeReportService;
use App\Services\Database\DatabaseRouter;
use App\Support\OrderBuckets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business Reports: what Dashboard V1 shows for one store and one business
 * week, for any list of stores (or "all") over any date range.
 *
 * POST /reports/business/{section} with {start_date, end_date, stores}. Each
 * section is pre-aggregated with grouped queries (never one query per store or
 * per day), cached per request, and uses the same formulas as the single-store
 * builders in ReportsController — one store over one week gives V1's numbers.
 *
 * A trait of ReportsController so it reuses that class's private helpers
 * (scores, fiscal calendar, memo) instead of copying them.
 */
trait BuildsBusinessReports
{
    private const BUSINESS_CATEGORIES = ['pizza', 'hnr', 'bread', 'wings', 'beverages', 'other_foods', 'side_items'];

    private const BUSINESS_CHANNELS = [
        'phone', 'website', 'mobile', 'call_center', 'drive_thru', 'doordash', 'ubereats', 'grubhub',
    ];

    /** Ingredient sets of the V1 "Top Ingredients" card (404 + 406 are shown merged as 404). */
    private const BUSINESS_MAIN_INGREDIENT_IDS = ['1515', '1103', '3813', '1042', '404', '406'];
    private const BUSINESS_PAPER_INGREDIENT_IDS = ['4659', '4660/4621', '5858', '4540', '4342'];

    /** Cache seconds: a range that is over, and one that still includes today. */
    /** The orders section's parts → their builders. */
    private const BUSINESS_ORDER_PARTS = [
        'channels' => 'businessChannelSplit',
        'important_items_hnr' => 'businessImportantItemsHnr',
        'upselling' => 'businessUpselling',
        'promo' => 'businessPromo',
    ];

    /** Business Reports shows every percentage with 3 decimals, so they are sent with 3. */
    private const BUSINESS_PCT_DECIMALS = 3;

    private const BUSINESS_PAST_TTL = 86400;
    private const BUSINESS_LIVE_TTL = 300;

    /** How many rows the "top" lists keep (combined; per store keeps 5). */
    private const BUSINESS_TOP_LIMIT = 10;

    // ---------------------------------------------------------------------
    // Endpoints
    // ---------------------------------------------------------------------

    public function businessSales(Request $request): JsonResponse
    {
        return $this->businessSection($request, 'sales', fn (array $stores, CarbonImmutable $s, CarbonImmutable $e) => $this->buildBusinessSales($stores, $s, $e));
    }

    public function businessOperations(Request $request): JsonResponse
    {
        return $this->businessSection($request, 'operations', fn (array $stores, CarbonImmutable $s, CarbonImmutable $e) => $this->buildBusinessOperations($stores, $s, $e));
    }

    public function businessMenu(Request $request): JsonResponse
    {
        return $this->businessSection($request, 'menu', fn (array $stores, CarbonImmutable $s, CarbonImmutable $e) => $this->buildBusinessMenu($stores, $s, $e));
    }

    /**
     * The four order-level reads (each a scan of the raw orders). The body's
     * optional `part` returns just one of them, so a caller can ask for all
     * four at once instead of waiting for them one after another.
     */
    public function businessOrders(Request $request): JsonResponse
    {
        $part = $request->validate(['part' => ['nullable', 'in:' . implode(',', array_keys(self::BUSINESS_ORDER_PARTS))]])['part'] ?? null;
        $parts = $part === null ? self::BUSINESS_ORDER_PARTS : [$part => self::BUSINESS_ORDER_PARTS[$part]];

        return $this->businessSection(
            $request,
            $part === null ? 'orders' : "orders-{$part}",
            fn (array $stores, CarbonImmutable $s, CarbonImmutable $e) => array_map(fn (string $build) => $this->$build($stores, $s, $e), $parts),
        );
    }

    public function businessQuality(Request $request): JsonResponse
    {
        return $this->businessSection($request, 'quality', fn (array $stores, CarbonImmutable $s, CarbonImmutable $e) => $this->buildBusinessQuality($stores, $s, $e));
    }

    public function businessDebriefs(Request $request, EmployeeReportService $employees): JsonResponse
    {
        return $this->businessSection($request, 'debriefs', fn (array $stores, CarbonImmutable $s, CarbonImmutable $e) => $employees->getMultiStoreReport($stores, $s, $e));
    }

    /**
     * V1's Sales History for the loaded stores together: every week since the
     * first data (the picked range is ignored, as on V1), rolled up into
     * fiscal periods, quarters and years. It always runs to today, so it is
     * cached for minutes, never for a day.
     */
    public function businessSalesHistory(Request $request): JsonResponse
    {
        return $this->businessSection(
            $request,
            'sales-history',
            fn (array $stores, CarbonImmutable $s, CarbonImmutable $e) => $this->salesHistoryFor(
                $stores,
                count($stores) === 1 ? $stores[0] : count($stores) . ' stores',
                CarbonImmutable::today()
            ),
            self::BUSINESS_LIVE_TTL
        );
    }

    public function businessStoreScore(Request $request): JsonResponse
    {
        return $this->businessSection($request, 'store-score', fn (array $stores, CarbonImmutable $s, CarbonImmutable $e) => $this->buildBusinessStoreScores($stores, $s, $e));
    }

    /**
     * Validates the body, resolves the stores, and caches the section: a day
     * when the range is over, five minutes while it still includes today.
     *
     * @param callable(array, CarbonImmutable, CarbonImmutable): array $build
     * @param int|null $ttl seconds; null = by the range (a day once it is over)
     */
    private function businessSection(Request $request, string $section, callable $build, ?int $ttl = null): JsonResponse
    {
        $request->headers->set('Accept', 'application/json');

        $input = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'stores' => ['required'],
        ]);

        $start = CarbonImmutable::parse($input['start_date'])->startOfDay();
        $end = CarbonImmutable::parse($input['end_date'])->startOfDay();
        $stores = $this->businessStores($input['stores'], $start, $end);

        // "v2": percentages went from 2 to 3 decimals; older cached answers are not reused.
        $key = sprintf(
            'business:v2:%s:%s:%s:%s',
            $section,
            md5(implode(',', $stores)),
            $start->toDateString(),
            $end->toDateString()
        );
        $ttl ??= $end->lt(CarbonImmutable::today()) ? self::BUSINESS_PAST_TTL : self::BUSINESS_LIVE_TTL;

        $payload = Cache::remember($key, $ttl, fn (): array => [
            'meta' => $this->businessMeta($stores, $start, $end),
        ] + $build($stores, $start, $end));

        return response()->json($payload);
    }

    /** "all" (or ["all"]) = every store with sales in the range; otherwise the trimmed, de-duplicated list. */
    private function businessStores(mixed $input, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($input === 'all' || $input === ['all']) {
            $stores = $this->storesWithSales($start, $end);
        } elseif (is_array($input)) {
            $stores = array_filter(array_map(fn ($s): string => trim((string) $s), $input), 'strlen');
        } else {
            throw ValidationException::withMessages(['stores' => 'stores must be "all" or a list of store numbers']);
        }

        $stores = array_values(array_unique($stores));
        sort($stores);

        return $stores;
    }

    private function businessMeta(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => (int) $start->diffInDays($end) + 1,
            'stores' => $stores,
            'store_count' => count($stores),
            'weeks' => $this->businessWeeks($start, $end),
        ];
    }

    /**
     * Tue-Mon business weeks overlapping the range, oldest first. `start`/`end`
     * are the part of the week inside the range; `week_start` is the key every
     * by-week block uses.
     */
    private function businessWeeks(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $weeks = [];
        for ($w = $start->startOfWeek(CarbonInterface::TUESDAY); $w->lte($end); $w = $w->addWeek()) {
            $weekEnd = $w->addDays(6);
            $weeks[] = [
                'week_start' => $w->toDateString(),
                'week_end' => $weekEnd->toDateString(),
                'start' => ($w->lt($start) ? $start : $w)->toDateString(),
                'end' => ($weekEnd->gt($end) ? $end : $weekEnd)->toDateString(),
            ] + array_intersect_key($this->fiscalWeekInfo($w), array_flip(['fiscal_year', 'week_number', 'period_number', 'quarter_number']));
        }

        return $weeks;
    }

    // ---------------------------------------------------------------------
    // Section: sales (daily/hourly summaries, deposit, waste, labor)
    // ---------------------------------------------------------------------

    private function buildBusinessSales(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cols = $this->businessSummaryColumns();
        $sums = implode(', ', array_map(fn (string $c): string => "COALESCE(SUM({$c}), 0) as {$c}", $cols));
        $week = $this->businessWeekSql('business_date');

        $totals = [];
        $byStore = [];
        $byWeek = [];
        $storeWeek = [];
        $byDate = [];

        $rows = DailyStoreSummary::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("franchise_store, {$week} as week_start, COUNT(*) as store_days, {$sums}")
            ->groupBy('franchise_store')
            ->groupByRaw($week)
            ->get();

        foreach ($rows as $row) {
            $vals = ['store_days' => (int) $row->store_days];
            foreach ($cols as $c) {
                $vals[$c] = (float) $row->$c;
            }
            $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $row->franchise_store, (string) $row->week_start, $vals);
        }

        $dateRows = DailyStoreSummary::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'business_date, COUNT(*) as store_days,'
                . ' COALESCE(SUM(royalty_obligation), 0) as royalty_obligation,'
                . ' COALESCE(SUM(gross_sales), 0) as gross_sales,'
                . ' COALESCE(SUM(total_orders), 0) as total_orders,'
                . ' COALESCE(SUM(customer_count), 0) as customer_count,'
                . ' COALESCE(SUM(cash_sales), 0) as cash_sales,'
                . ' COALESCE(SUM(over_short), 0) as over_short,'
                . ' COALESCE(SUM(total_tips), 0) as total_tips,'
                . ' COALESCE(SUM(refund_amount), 0) as refund_amount,'
                . ' COALESCE(SUM(refunded_orders), 0) as refunded_orders'
            )
            ->groupBy('business_date')
            ->get();

        foreach ($dateRows as $row) {
            $date = substr((string) $row->business_date, 0, 10);
            $byDate[$date] = [
                'store_days' => (int) $row->store_days,
                'royalty_obligation' => (float) $row->royalty_obligation,
                'gross_sales' => (float) $row->gross_sales,
                'total_orders' => (float) $row->total_orders,
                'customer_count' => (float) $row->customer_count,
                'cash_sales' => (float) $row->cash_sales,
                'over_short' => (float) $row->over_short,
                'total_tips' => (float) $row->total_tips,
                'refund_amount' => (float) $row->refund_amount,
                'refunded_orders' => (float) $row->refunded_orders,
            ];
        }

        // Store/day values from other tables, folded into the same blocks.
        $extras = [
            'deposit' => $this->businessDepositByStoreDate($stores, $start, $end),
            'waste_normal' => $this->businessWasteByStoreDate('waste', 'SUM(item_cost * quantity)', $stores, $start, $end),
            'waste_alta' => $this->businessWasteByStoreDate('alta_inventory_waste', 'SUM(unit_food_cost * qty)', $stores, $start, $end),
            'labor_cost' => $this->businessLaborByStoreDate($stores, $start, $end),
        ];

        foreach ($extras as $key => $byStoreDate) {
            foreach ($byStoreDate as $store => $dates) {
                foreach ($dates as $date => $value) {
                    $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $store, $this->businessWeekOf($date), [$key => $value]);
                    $byDate[$date][$key] = ($byDate[$date][$key] ?? 0) + $value;
                }
            }
        }

        $hourly = HourlyStoreSummary::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'hour, COUNT(DISTINCT franchise_store, business_date) as store_days,'
                . ' COALESCE(SUM(royalty_obligation), 0) as royalty_obligation,'
                . implode(',', array_map(fn (string $ch): string => " COALESCE(SUM({$ch}_sales), 0) as {$ch}_sales", self::BUSINESS_CHANNELS))
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(function ($row): array {
                $out = ['hour' => (int) $row->hour, 'store_days' => (int) $row->store_days, 'total_sales' => round((float) $row->royalty_obligation, 2)];
                $channels = 0.0;
                foreach (self::BUSINESS_CHANNELS as $ch) {
                    $out["{$ch}_sales"] = round((float) $row->{"{$ch}_sales"}, 2);
                    $channels += (float) $row->{"{$ch}_sales"};
                }
                // Register = what no channel claims (V1's adjusted royalty obligation).
                $out['register_sales'] = round((float) $row->royalty_obligation - $channels, 2);

                return $out;
            })
            ->values()
            ->all();

        ksort($byDate);
        ksort($byStore);

        return [
            'totals' => $this->businessDerive($totals),
            'by_store' => array_map(fn (array $b): array => $this->businessDerive($b), $byStore),
            'by_week' => $this->businessWeekList($byWeek, $start, $end, fn (array $b): array => $this->businessDerive($b)),
            'by_store_week' => array_map(
                fn (array $weeks): array => array_map(fn (array $b): array => $this->businessCompact($b), $weeks),
                $storeWeek
            ),
            'by_date' => array_map(
                fn (string $date, array $b): array => ['date' => $date] + $this->businessDerive($b),
                array_keys($byDate),
                $byDate
            ),
            'hourly' => $hourly,
        ];
    }

    /** Every numeric daily_store_summary column the reports sum. */
    private function businessSummaryColumns(): array
    {
        $cols = [
            'royalty_obligation', 'gross_sales', 'net_sales', 'refund_amount',
            'total_orders', 'completed_orders', 'cancelled_orders', 'modified_orders', 'refunded_orders', 'customer_count',
            'delivery_orders', 'delivery_sales', 'carryout_orders', 'carryout_sales',
            'sales_tax', 'delivery_fees', 'delivery_tips', 'store_tips', 'total_tips', 'cash_sales', 'over_short',
            'portal_eligible_orders', 'portal_used_orders', 'portal_on_time_orders',
            'digital_orders', 'digital_sales', 'hnr_transactions', 'hnr_broken_promises',
        ];
        foreach (self::BUSINESS_CHANNELS as $ch) {
            $cols[] = "{$ch}_orders";
            $cols[] = "{$ch}_sales";
        }
        foreach (self::BUSINESS_CATEGORIES as $cat) {
            foreach (['delivery', 'carryout'] as $f) {
                $cols[] = "{$cat}_{$f}_quantity";
                $cols[] = "{$cat}_{$f}_sales";
            }
        }

        return $cols;
    }

    /** Cash-Check-Deposit per store and business day. */
    private function businessDepositByStoreDate(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->businessGrouped('financial_views', $stores, $start, $end, ['franchise_store', 'business_date'], fn (callable $part): Builder => $part('f')
            ->where('sub_account', 'Cash-Check-Deposit')
            ->selectRaw('franchise_store, business_date, SUM(amount) as v')
            ->groupBy('franchise_store', 'business_date'));

        return $this->businessStoreDateMap($rows);
    }

    private function businessWasteByStoreDate(string $table, string $sumSql, array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->businessGrouped($table, $stores, $start, $end, ['franchise_store', 'business_date'], fn (callable $part): Builder => $part('w')
            ->selectRaw("franchise_store, business_date, {$sumSql} as v")
            ->groupBy('franchise_store', 'business_date'));

        return $this->businessStoreDateMap($rows);
    }

    /**
     * Labor cost per store and BUSINESS day: the latest non-mistaken entry per
     * store/entry day (laborValueSumForRange's rule), key 23 before the cutoff
     * and key 28 after it — key 28 is entered the day after, so its business
     * day is entry_date - 1.
     */
    private function businessLaborByStoreDate(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = EnteredKeyValue::query()->toBase()
            ->whereIn('id', function ($q) use ($stores, $start, $end) {
                $q->from('entered_key_values')
                    ->selectRaw('MAX(id)')
                    ->whereIn('store_id', $stores)
                    ->where('is_mistaken', false);
                $this->applyLaborKeySegments($q, $start, $end);
                $q->groupBy('store_id', 'entry_date');
            })
            ->get(['store_id', 'entry_date', 'key_id', 'value_number']);

        $out = [];
        foreach ($rows as $row) {
            $entry = CarbonImmutable::parse($row->entry_date);
            $date = ((int) $row->key_id === self::LABOR_YESTERDAY_KEY_ID ? $entry->subDay() : $entry)->toDateString();
            $out[(string) $row->store_id][$date] = ($out[(string) $row->store_id][$date] ?? 0) + (float) $row->value_number;
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Section: operations (portal, HnR, HNR+, go-to, non-negotiables,
    // transfers, orders vs sales)
    // ---------------------------------------------------------------------

    private function buildBusinessOperations(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $week = $this->businessWeekSql('business_date');

        // Portal %s are sums over the whole range (Σ used ÷ Σ eligible, Σ on time
        // ÷ Σ used), never an average of daily rates.
        $rows = DailyStoreSummary::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                "franchise_store, {$week} as week_start,"
                . ' COALESCE(SUM(royalty_obligation), 0) as sales,'
                . ' COALESCE(SUM(portal_eligible_orders), 0) as portal_eligible_orders,'
                . ' COALESCE(SUM(portal_used_orders), 0) as portal_used_orders,'
                . ' COALESCE(SUM(portal_on_time_orders), 0) as portal_on_time_orders,'
                . ' SUM(CASE WHEN portal_eligible_orders > 0 THEN 1 ELSE 0 END) as portal_days,'
                . ' COALESCE(SUM(hnr_transactions), 0) as hnr_transactions,'
                . ' COALESCE(SUM(hnr_broken_promises), 0) as hnr_broken_promises'
            )
            ->groupBy('franchise_store')
            ->groupByRaw($week)
            ->get();

        $totals = [];
        $byStore = [];
        $byWeek = [];
        $storeWeek = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach (['sales', 'portal_eligible_orders', 'portal_used_orders', 'portal_on_time_orders', 'portal_days', 'hnr_transactions', 'hnr_broken_promises'] as $k) {
                $vals[$k] = (float) $row->$k;
            }
            $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $row->franchise_store, (string) $row->week_start, $vals);
        }

        // Blue Line / Pepsi invoices per store and week (orders vs sales).
        $deliveryWeek = $this->businessWeekSql('delivery_date');
        $orders = InventoryOrder::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('delivery_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                "store_number, {$deliveryWeek} as week_start,"
                . " COALESCE(SUM(CASE WHEN vendor_name LIKE '%BLUE LINE%' THEN invoice_total END), 0) as blue_line_total,"
                . " COALESCE(SUM(CASE WHEN vendor_name LIKE '%PEPSI%' THEN invoice_total END), 0) as pepsi_total"
            )
            ->groupBy('store_number')
            ->groupByRaw($deliveryWeek)
            ->get();
        foreach ($orders as $row) {
            $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $row->store_number, (string) $row->week_start, [
                'blue_line_total' => (float) $row->blue_line_total,
                'pepsi_total' => (float) $row->pepsi_total,
            ]);
        }

        // Go-to calls: V1 counts the week's calls by status.
        $callWeek = $this->businessWeekSql('DATE(datetime)');
        $calls = GoToCall::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('datetime', [$start->toDateTimeString(), $end->addDay()->toDateTimeString()])
            ->selectRaw("store_number, {$callWeek} as week_start, status, COUNT(*) as n")
            ->groupBy('store_number', 'status')
            ->groupByRaw($callWeek)
            ->get();
        foreach ($calls as $row) {
            $vals = ['calls_total' => (int) $row->n];
            if (in_array($row->status, ['is_missed', 'is_store', 'is_store_manager', 'is_call_center'], true)) {
                $vals['calls_' . substr((string) $row->status, 3)] = (int) $row->n;
            }
            $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $row->store_number, (string) $row->week_start, $vals);
        }

        // Transfers: in = into a requested store, out = out of one.
        $transfers = TransferInOut::query()->toBase()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($q) => $q->whereIn('from_store_number', $stores)->orWhereIn('to_store_number', $stores))
            ->orderByDesc('date')
            ->get(['date', 'from_store_number', 'to_store_number', 'ing_des', 'quantity', 'unit', 'total_cost']);
        $storeSet = array_flip($stores);
        foreach ($transfers as $t) {
            $date = substr((string) $t->date, 0, 10);
            $cost = (float) ($t->total_cost ?? 0);
            if (isset($storeSet[(string) $t->to_store_number])) {
                $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $t->to_store_number, $this->businessWeekOf($date), ['transfers_in_count' => 1, 'transfers_in_cost' => $cost]);
            }
            if (isset($storeSet[(string) $t->from_store_number])) {
                $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $t->from_store_number, $this->businessWeekOf($date), ['transfers_out_count' => 1, 'transfers_out_cost' => $cost]);
            }
        }

        // Non-negotiables: every entry (small table), counted per store.
        $nonNegotiables = NonNegotiableReport::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('date')
            ->get(['store_number', 'action', 'date', 'time', 'date_two', 'time_two', 'notes']);
        foreach ($nonNegotiables as $n) {
            $date = substr((string) $n->date, 0, 10);
            $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $n->store_number, $this->businessWeekOf($date), [
                'non_negotiable_count' => 1,
                'non_negotiable_downtime' => $n->action === 'Downtime' ? 1 : 0,
            ]);
        }

        $hnrPlus = $this->businessHnrPlus($stores, $start, $end);

        $finish = fn (array $b): array => $this->businessDeriveOperations($b);
        ksort($byStore);

        return [
            'totals' => $finish($totals),
            'by_store' => array_map($finish, $byStore),
            'by_week' => $this->businessWeekList($byWeek, $start, $end, $finish),
            'by_store_week' => array_map(fn (array $weeks): array => array_map($finish, $weeks), $storeWeek),
            'hnr_plus' => $hnrPlus,
            'transfers' => $transfers->map(fn ($t): array => [
                'date' => substr((string) $t->date, 0, 10),
                'from_store_number' => (string) $t->from_store_number,
                'to_store_number' => (string) $t->to_store_number,
                'ing_des' => $t->ing_des,
                'quantity' => (float) $t->quantity,
                'unit' => $t->unit,
                'total_cost' => round((float) $t->total_cost, 2),
            ])->values()->all(),
            'non_negotiables' => $nonNegotiables->map(fn ($n): array => [
                'store' => (string) $n->store_number,
                'action' => $n->action,
                'date' => substr((string) $n->date, 0, 10),
                'time' => $n->time,
                'date_two' => $n->date_two !== null ? substr((string) $n->date_two, 0, 10) : null,
                'time_two' => $n->time_two,
                'notes' => $n->notes,
            ])->values()->all(),
        ];
    }

    private function businessDeriveOperations(array $b): array
    {
        $sales = (float) ($b['sales'] ?? 0);
        $portalDays = (float) ($b['portal_days'] ?? 0);
        $eligible = (float) ($b['portal_eligible_orders'] ?? 0);
        $used = (float) ($b['portal_used_orders'] ?? 0);
        $onTime = (float) ($b['portal_on_time_orders'] ?? 0);
        $transactions = (int) ($b['hnr_transactions'] ?? 0);
        $broken = (int) ($b['hnr_broken_promises'] ?? 0);
        $blue = (float) ($b['blue_line_total'] ?? 0);
        $pepsi = (float) ($b['pepsi_total'] ?? 0);

        return [
            'sales' => round($sales, 2),
            'portal_eligible_orders' => (int) ($b['portal_eligible_orders'] ?? 0),
            'portal_used_orders' => (int) ($b['portal_used_orders'] ?? 0),
            'portal_on_time_orders' => (int) ($b['portal_on_time_orders'] ?? 0),
            'portal_days' => (int) $portalDays,
            'put_into_portal_percent' => $eligible > 0 ? round($used / $eligible * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
            'in_portal_on_time_percent' => $used > 0 ? round($onTime / $used * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
            ...$this->hnrTotals(['hnr_transactions' => $transactions, 'hnr_broken_promises' => $broken], self::BUSINESS_PCT_DECIMALS),
            'blue_line_total' => round($blue, 2),
            'pepsi_total' => round($pepsi, 2),
            'blue_line_pct' => $sales > 0 ? round($blue / $sales * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
            'pepsi_pct' => $sales > 0 ? round($pepsi / $sales * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
            'calls_total' => (int) ($b['calls_total'] ?? 0),
            'calls_missed' => (int) ($b['calls_missed'] ?? 0),
            'calls_store' => (int) ($b['calls_store'] ?? 0),
            'calls_store_manager' => (int) ($b['calls_store_manager'] ?? 0),
            'calls_call_center' => (int) ($b['calls_call_center'] ?? 0),
            'transfers_in_count' => (int) ($b['transfers_in_count'] ?? 0),
            'transfers_in_cost' => round((float) ($b['transfers_in_cost'] ?? 0), 2),
            'transfers_out_count' => (int) ($b['transfers_out_count'] ?? 0),
            'transfers_out_cost' => round((float) ($b['transfers_out_cost'] ?? 0), 2),
            'non_negotiable_count' => (int) ($b['non_negotiable_count'] ?? 0),
            'non_negotiable_downtime' => (int) ($b['non_negotiable_downtime'] ?? 0),
        ];
    }

    /**
     * HNR+ per business week (the CSV is weekly): V1's percentages and scores
     * per store-week, per week over all stores, per store over the range, and
     * overall; plus the item totals.
     */
    private function businessHnrPlus(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $firstWeek = $start->startOfWeek(CarbonInterface::TUESDAY)->toDateString();
        $sums = 'COALESCE(SUM(made), 0) as made, COALESCE(SUM(sold), 0) as sold, COALESCE(SUM(voided), 0) as voided,'
            . ' COALESCE(SUM(wasted), 0) as wasted, COALESCE(SUM(variance), 0) as variance,'
            . ' COALESCE(SUM(no_inventory_available), 0) as no_inventory_available';
        $keys = ['made', 'sold', 'voided', 'wasted', 'variance', 'no_inventory_available'];

        $rows = HnrPlusItem::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('week_start', [$firstWeek, $end->toDateString()])
            ->selectRaw("store_number, week_start, {$sums}")
            ->groupBy('store_number', 'week_start')
            ->get();

        $totals = [];
        $byStore = [];
        $byWeek = [];
        $storeWeek = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ($keys as $k) {
                $vals[$k] = (int) $row->$k;
            }
            $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $row->store_number, substr((string) $row->week_start, 0, 10), $vals);
        }

        $score = function (array $b) use ($keys): array {
            $t = [];
            foreach ($keys as $k) {
                $t[$k] = (int) ($b[$k] ?? 0);
            }

            return $t + $this->computeHnrPlusScores($t);
        };

        $items = HnrPlusItem::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('week_start', [$firstWeek, $end->toDateString()])
            ->selectRaw("item_id, MAX(item_name) as item_name, {$sums}")
            ->groupBy('item_id')
            ->orderBy('item_name')
            ->get()
            ->map(function ($row) use ($keys): array {
                $out = ['item_id' => (string) $row->item_id, 'item_name' => $row->item_name];
                foreach ($keys as $k) {
                    $out[$k] = (int) $row->$k;
                }

                return $out;
            })
            ->all();

        ksort($byStore);
        ksort($byWeek);

        return [
            'totals' => $score($totals),
            'by_store' => array_map($score, $byStore),
            'by_week' => array_map(fn (string $w, array $b): array => ['week_start' => $w] + $score($b), array_keys($byWeek), $byWeek),
            'by_store_week' => array_map(fn (array $weeks): array => array_map(fn (array $b): array => ['total_score' => $score($b)['total_score'], 'made' => (int) ($b['made'] ?? 0)], $weeks), $storeWeek),
            'items' => $items,
        ];
    }

    // ---------------------------------------------------------------------
    // Section: menu (items, LTO, ingredients, portioning)
    // ---------------------------------------------------------------------

    private function buildBusinessMenu(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $itemRows = DailyItemSummary::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'franchise_store, item_id, MAX(menu_item_name) as menu_item_name,'
                . ' COALESCE(SUM(gross_sales), 0) as gross_sales, COALESCE(SUM(quantity_sold), 0) as quantity_sold'
            )
            ->groupBy('franchise_store', 'item_id')
            ->get();

        $combined = [];
        $perStore = [];
        foreach ($itemRows as $row) {
            $id = (string) $row->item_id;
            $item = [
                'item_id' => $id,
                'menu_item_name' => $row->menu_item_name,
                'gross_sales' => (float) $row->gross_sales,
                'quantity_sold' => (int) $row->quantity_sold,
            ];
            $perStore[(string) $row->franchise_store][] = $item;
            $combined[$id] ??= ['item_id' => $id, 'menu_item_name' => $row->menu_item_name, 'gross_sales' => 0.0, 'quantity_sold' => 0];
            $combined[$id]['gross_sales'] += $item['gross_sales'];
            $combined[$id]['quantity_sold'] += $item['quantity_sold'];
        }

        $top = function (array $items, string $by, int $limit): array {
            usort($items, fn (array $a, array $b): int => $b[$by] <=> $a[$by]);

            return array_map(
                fn (array $i): array => ['gross_sales' => round($i['gross_sales'], 2)] + $i,
                array_slice($items, 0, $limit)
            );
        };

        // LTO: the LTO items vs the store's quantity (the six category totals) and sales.
        $storeTotals = DailyStoreSummary::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'franchise_store, COALESCE(SUM(royalty_obligation), 0) as sales, COALESCE('
                . implode(' + ', array_map(
                    fn (string $c): string => "SUM({$c}_delivery_quantity) + SUM({$c}_carryout_quantity)",
                    ['pizza', 'hnr', 'bread', 'wings', 'beverages', 'other_foods']
                ))
                . ', 0) as quantity'
            )
            ->groupBy('franchise_store')
            ->get()
            ->keyBy(fn ($r): string => (string) $r->franchise_store);

        $ltoIds = array_map('strval', self::LTO_ITEM_IDS);
        $lto = function (array $storeList) use ($perStore, $storeTotals, $ltoIds): array {
            $sales = 0.0;
            $qty = 0;
            $storeSales = 0.0;
            $storeQty = 0;
            $items = array_fill_keys($ltoIds, ['sales' => 0.0, 'quantity' => 0]);
            foreach ($storeList as $store) {
                $storeSales += (float) ($storeTotals[$store]->sales ?? 0);
                $storeQty += (int) ($storeTotals[$store]->quantity ?? 0);
                foreach ($perStore[$store] ?? [] as $item) {
                    if (isset($items[$item['item_id']])) {
                        $items[$item['item_id']]['sales'] += $item['gross_sales'];
                        $items[$item['item_id']]['quantity'] += $item['quantity_sold'];
                        $sales += $item['gross_sales'];
                        $qty += $item['quantity_sold'];
                    }
                }
            }

            return [
                'store_totals' => ['total_sales' => round($storeSales, 2), 'total_quantity' => $storeQty],
                'lto_totals' => [
                    'total_sales' => round($sales, 2),
                    'total_quantity' => $qty,
                    'pct_of_store_sales' => $storeSales > 0 ? round($sales / $storeSales * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
                    'pct_of_store_quantity' => $storeQty > 0 ? round($qty / $storeQty * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
                ],
                'items' => array_map(fn (string $id, array $i): array => [
                    'item_id' => $id,
                    'sales' => round($i['sales'], 2),
                    'quantity' => $i['quantity'],
                    'pct_of_store_sales' => $storeSales > 0 ? round($i['sales'] / $storeSales * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
                    'pct_of_store_quantity' => $storeQty > 0 ? round($i['quantity'] / $storeQty * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
                ], array_keys($items), $items),
            ];
        };

        $ingredients = $this->businessIngredients($stores, $start, $end);
        ksort($perStore);

        return [
            'top_items' => [
                'by_sales' => $top(array_values($combined), 'gross_sales', self::BUSINESS_TOP_LIMIT),
                'by_count' => $top(array_values($combined), 'quantity_sold', self::BUSINESS_TOP_LIMIT),
                'by_store' => array_map(fn (array $items): array => [
                    'by_sales' => $top($items, 'gross_sales', 5),
                    'by_count' => $top($items, 'quantity_sold', 5),
                ], $perStore),
            ],
            'lto' => $lto($stores) + [
                'by_store' => array_combine($stores, array_map(fn (string $s): array => $lto([$s])['lto_totals'], $stores)),
            ],
            ...$ingredients,
        ];
    }

    /**
     * One scan of ingredient usage, grouped by store / ingredient / count
     * period: the V1 Top Ingredients lists (every count period, variance value
     * = SUM(variance_qty * unit_cost) per row) and Portioning (weekly counts).
     */
    private function businessIngredients(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->businessGrouped('alta_inventory_ingredient_usage', $stores, $start, $end, ['franchise_store', 'ingredient_id', 'count_period'], fn (callable $part): Builder => $part('u')
            ->selectRaw(
                'franchise_store, ingredient_id, MAX(ingredient_description) as ingredient_description, count_period,'
                . ' COALESCE(SUM(actual_usage), 0) as actual_usage,'
                . ' COALESCE(SUM(variance_qty * ingredient_unit_cost), 0) as variance_value,'
                . ' COALESCE(SUM((theoretical_usage * ingredient_unit_cost) + ((actual_usage - theoretical_usage + variance_qty) * ingredient_unit_cost)), 0) as theo_usage_value'
            )
            ->groupBy('franchise_store', 'ingredient_id', 'count_period'));

        $all = [];
        $portioning = [];
        foreach ($rows as $row) {
            $id = (string) $row->ingredient_id;
            $all[$id] ??= ['ingredient_id' => $id, 'ingredient_description' => $row->ingredient_description, 'actual_usage' => 0.0, 'variance_value' => 0.0];
            $all[$id]['actual_usage'] += (float) $row->actual_usage;
            $all[$id]['variance_value'] += (float) $row->variance_value;

            if ($row->count_period === 'W') {
                $store = (string) $row->franchise_store;
                $portioning[$store]['theo'] = ($portioning[$store]['theo'] ?? 0) + (float) $row->theo_usage_value;
                $portioning[$store]['ingredients'][$id] ??= ['ingredient_description' => $row->ingredient_description, 'variance_value' => 0.0];
                $portioning[$store]['ingredients'][$id]['variance_value'] += (float) $row->variance_value;
            }
        }

        $round = fn (array $i): array => ['actual_usage' => round($i['actual_usage'], 2), 'variance_value' => round($i['variance_value'], 2)] + $i;
        $sorted = array_values($all);
        usort($sorted, fn (array $a, array $b): int => $b['variance_value'] <=> $a['variance_value']);

        $pick = fn (array $ids): array => array_values(array_filter($all, fn (array $i): bool => in_array($i['ingredient_id'], $ids, true)));

        // 404 and 406 are one ingredient on the V1 card.
        $main = [];
        foreach ($pick(self::BUSINESS_MAIN_INGREDIENT_IDS) as $i) {
            $key = $i['ingredient_id'] === '406' ? '404' : $i['ingredient_id'];
            $main[$key] ??= ['ingredient_id' => $key, 'ingredient_description' => $i['ingredient_description'], 'actual_usage' => 0.0, 'variance_value' => 0.0];
            if ($i['ingredient_id'] === '404') {
                $main[$key]['ingredient_description'] = $i['ingredient_description'];
            }
            $main[$key]['actual_usage'] += $i['actual_usage'];
            $main[$key]['variance_value'] += $i['variance_value'];
        }
        $main = array_values($main);
        usort($main, fn (array $a, array $b): int => $b['variance_value'] <=> $a['variance_value']);
        $paper = $pick(self::BUSINESS_PAPER_INGREDIENT_IDS);
        usort($paper, fn (array $a, array $b): int => $b['variance_value'] <=> $a['variance_value']);

        // Portioning: V1's weekly theoretical usage and the tracked ingredients' variance.
        $detailIds = array_map('strval', self::PORTIONING_DETAIL_IDS);
        $portion = function (float $theo, array $ingredients) use ($detailIds): array {
            $breakdown = [];
            foreach ($detailIds as $id) {
                if (!isset($ingredients[$id])) {
                    continue;
                }
                $value = round($ingredients[$id]['variance_value'], 2);
                $breakdown[] = [
                    'ingredient_id' => $id,
                    'ingredient_description' => $ingredients[$id]['ingredient_description'],
                    'variance_value' => $value,
                    'percentage_of_theo_usage' => $theo != 0.0 ? round($value / $theo * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
                ];
            }

            return ['theo_usage' => round($theo, 2), 'variance_breakdown' => $breakdown];
        };

        $combinedTheo = 0.0;
        $combinedIngredients = [];
        foreach ($portioning as $p) {
            $combinedTheo += $p['theo'];
            foreach ($p['ingredients'] as $id => $i) {
                $combinedIngredients[$id] ??= ['ingredient_description' => $i['ingredient_description'], 'variance_value' => 0.0];
                $combinedIngredients[$id]['variance_value'] += $i['variance_value'];
            }
        }
        ksort($portioning);

        return [
            'ingredients' => [
                'variance_high' => array_map($round, array_slice($sorted, 0, 5)),
                'variance_low' => array_map($round, array_slice(array_reverse($sorted), 0, 5)),
                'main' => array_map($round, $main),
                'paper' => array_map($round, $paper),
            ],
            'portioning' => $portion($combinedTheo, $combinedIngredients) + [
                'by_store' => array_map(fn (array $p): array => $portion($p['theo'], $p['ingredients']), $portioning),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Section: orders (channel split, special-items HnR and upselling from the
    // daily order extras; promos from the raw order lines). Kept apart so
    // they never hold the other sections back.
    // ---------------------------------------------------------------------

    /** The stores' daily_order_extras rows in the range, as a query to sum. */
    private function businessExtras(array $stores, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return DailyOrderExtras::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()]);
    }

    /**
     * Channel customers and the website/mobile in-store vs delivery split, from
     * the daily order extras (built once per store-day by the daily aggregation).
     */
    private function businessChannelSplit(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $keys = [
            'customers_total', 'customers_phone', 'customers_call_center', 'customers_drive_thru',
            'customers_doordash', 'customers_ubereats', 'customers_grubhub',
            'website_in_store', 'website_delivery', 'mobile_in_store', 'mobile_delivery',
            'website_in_store_customers', 'website_delivery_customers', 'mobile_in_store_customers', 'mobile_delivery_customers',
        ];
        $rows = $this->businessExtras($stores, $start, $end)
            ->selectRaw('franchise_store, ' . implode(', ', array_map(fn (string $k): string => "COALESCE(SUM({$k}), 0) as {$k}", $keys)))
            ->groupBy('franchise_store')
            ->get();

        $totals = array_fill_keys($keys, 0.0);
        $byStore = [];
        foreach ($rows as $row) {
            foreach ($keys as $k) {
                $byStore[(string) $row->franchise_store][$k] = round((float) $row->$k, 2);
                $totals[$k] += (float) $row->$k;
            }
        }
        ksort($byStore);

        return [
            'totals' => array_map(fn (float $v): float => round($v, 2), $totals),
            'by_store' => $byStore,
        ];
    }

    /** HnR on orders with a special item, per store and week, from the daily order extras. */
    private function businessImportantItemsHnr(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $week = $this->businessWeekSql('business_date');
        $rows = $this->businessExtras($stores, $start, $end)
            ->selectRaw(
                "franchise_store, {$week} as week_start,"
                . ' COALESCE(SUM(special_hnr_transactions), 0) as hnr_transactions,'
                . ' COALESCE(SUM(special_hnr_broken_promises), 0) as hnr_broken_promises'
            )
            ->groupBy('franchise_store')
            ->groupByRaw($week)
            ->get();

        $totals = [];
        $byStore = [];
        $byWeek = [];
        $storeWeek = [];
        foreach ($rows as $row) {
            $this->businessFold($totals, $byStore, $byWeek, $storeWeek, (string) $row->franchise_store, (string) $row->week_start, [
                'hnr_transactions' => (int) $row->hnr_transactions,
                'hnr_broken_promises' => (int) $row->hnr_broken_promises,
            ]);
        }

        $hnr = fn (array $b): array => $this->hnrTotals($b, self::BUSINESS_PCT_DECIMALS);
        ksort($byStore);

        return [
            'totals' => $hnr($totals),
            'by_store' => array_map($hnr, $byStore),
            'by_week' => $this->businessWeekList($byWeek, $start, $end, $hnr),
            'by_store_week' => array_map(fn (array $weeks): array => array_map($hnr, $weeks), $storeWeek),
        ];
    }

    /**
     * Upselling (in-store units sold with a pizza, pizza units and EMB units)
     * per store, from the daily order extras.
     */
    private function businessUpselling(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $columns = [
            'crazy_bread' => 'upsell_crazy_bread',
            'cookies' => 'upsell_cookies',
            'sauce' => 'upsell_sauce',
            'wings' => 'upsell_wings',
            'beverages' => 'upsell_beverages',
            'crazy_puffs' => 'upsell_crazy_puffs',
            'bev_20oz' => 'upsell_bev_20oz',
            'bev_2l' => 'upsell_bev_2l',
            'italian_cheese_bread' => 'upsell_italian_cheese_bread',
            'pizza_base' => 'upsell_pizza_base',
            OrderBuckets::UPSELL_ITEM_NAMES[OrderBuckets::UPSELL_ITEM_IDS[0]] => 'upsell_emb_cheese',
            OrderBuckets::UPSELL_ITEM_NAMES[OrderBuckets::UPSELL_ITEM_IDS[1]] => 'upsell_emb_pepperoni',
        ];
        $rows = $this->businessExtras($stores, $start, $end)
            ->selectRaw('franchise_store, ' . implode(', ', array_map(fn (string $c): string => "COALESCE(SUM({$c}), 0) as {$c}", $columns)))
            ->groupBy('franchise_store')
            ->get()
            ->keyBy(fn ($r): string => (string) $r->franchise_store);

        $block = function (array $units): array {
            $score = $this->upsellingScoreForItems($units);

            return [
                'units' => $units,
                'total_units' => $this->totalUpsellingUnits($units),
                'score' => $score,
                'total_score' => $this->totalUpsellingScore($score),
            ];
        };

        $combined = [];
        $byStore = [];
        foreach ($stores as $store) {
            $units = [];
            foreach ($columns as $name => $column) {
                $units[$name] = (int) ($rows[$store]->$column ?? 0);
            }
            if (array_sum($units) === 0) {
                continue;
            }
            $byStore[$store] = $block($units);
            foreach ($units as $k => $v) {
                $combined[$k] = ($combined[$k] ?? 0) + $v;
            }
        }

        return $block($combined) + ['by_store' => $byStore];
    }

    /** Promo lines (promoBreakdownForRange) grouped by reason, overall and per store, against royalty-obligation sales. */
    private function businessPromo(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->businessGrouped('order_line', $stores, $start, $end, ['franchise_store', 'modification_reason'], fn (callable $part): Builder => $part('pl')
            ->whereNotNull('modification_reason')
            ->where('modification_reason', '<>', '')
            ->selectRaw("
                franchise_store, modification_reason,
                COALESCE(SUM(net_amount), 0) as promo_sales,
                COALESCE(SUM(CASE WHEN order_placed_method = 'Doordash' THEN net_amount ELSE 0 END), 0) as doordash_sales,
                COALESCE(SUM(CASE WHEN order_placed_method IN ('UberEats','Uber Eats') THEN net_amount ELSE 0 END), 0) as ubereats_sales,
                COALESCE(SUM(CASE WHEN order_placed_method IN ('Grubhub','GrubHub') THEN net_amount ELSE 0 END), 0) as grubhub_sales
            ")
            ->groupBy('franchise_store', 'modification_reason'));

        $sales = DailyStoreSummary::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('franchise_store, COALESCE(SUM(royalty_obligation), 0) as sales')
            ->groupBy('franchise_store')
            ->pluck('sales', 'franchise_store');

        $reasonRows = function (array $byReason, float $storeSales): array {
            $out = [];
            foreach ($byReason as $reason => $r) {
                $promo = round($r['promo_sales'], 2);
                $out[] = [
                    'modification_reason' => $reason,
                    'promo_sales' => $promo,
                    'doordash_sales' => round($r['doordash_sales'], 2),
                    'ubereats_sales' => round($r['ubereats_sales'], 2),
                    'grubhub_sales' => round($r['grubhub_sales'], 2),
                    'lc_sales' => round($r['promo_sales'] - $r['doordash_sales'] - $r['ubereats_sales'] - $r['grubhub_sales'], 2),
                    'pct_of_store_sales' => $storeSales > 0 ? round($promo / $storeSales * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
                ];
            }
            usort($out, fn (array $a, array $b): int => $b['promo_sales'] <=> $a['promo_sales']);

            return $out;
        };

        $combined = [];
        $perStore = [];
        foreach ($rows as $row) {
            $vals = [
                'promo_sales' => (float) $row->promo_sales,
                'doordash_sales' => (float) $row->doordash_sales,
                'ubereats_sales' => (float) $row->ubereats_sales,
                'grubhub_sales' => (float) $row->grubhub_sales,
            ];
            $reason = (string) $row->modification_reason;
            foreach ($vals as $k => $v) {
                $combined[$reason][$k] = ($combined[$reason][$k] ?? 0) + $v;
                $perStore[(string) $row->franchise_store][$reason][$k] = ($perStore[(string) $row->franchise_store][$reason][$k] ?? 0) + $v;
            }
        }

        $totalSales = (float) collect($sales)->sum();
        $breakdown = $reasonRows($combined, $totalSales);
        ksort($perStore);

        return [
            'total_store_sales' => round($totalSales, 2),
            'promo_breakdown' => $breakdown,
            'promo_totals' => $this->sumPromoBreakdown($breakdown, round($totalSales, 2)),
            'by_store' => array_map(
                fn (string $store, array $byReason): array => ['store' => $store] + $this->sumPromoBreakdown(
                    $reasonRows($byReason, (float) ($sales[$store] ?? 0)),
                    round((float) ($sales[$store] ?? 0), 2)
                ),
                array_keys($perStore),
                $perStore
            ),
        ];
    }

    // ---------------------------------------------------------------------
    // Section: quality (cleaning review, customer service)
    // ---------------------------------------------------------------------

    private function buildBusinessQuality(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cleaning = CleaningReview::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw("store_number, review_place, COUNT(*) as total, SUM(CASE WHEN score = 'Pass' THEN 1 ELSE 0 END) as passes")
            ->groupBy('store_number', 'review_place')
            ->get();

        $cleanTotals = ['total' => 0, 'passes' => 0];
        $cleanByStore = [];
        $cleanByPlace = [];
        foreach ($cleaning as $row) {
            $store = (string) $row->store_number;
            $place = (string) $row->review_place;
            foreach (['total', 'passes'] as $k) {
                $cleanTotals[$k] += (int) $row->$k;
                $cleanByStore[$store][$k] = ($cleanByStore[$store][$k] ?? 0) + (int) $row->$k;
                $cleanByPlace[$place][$k] = ($cleanByPlace[$place][$k] ?? 0) + (int) $row->$k;
            }
        }
        $score = fn (array $b): array => [
            'total' => (int) ($b['total'] ?? 0),
            'passes' => (int) ($b['passes'] ?? 0),
            'overall_score' => ($b['total'] ?? 0) > 0 ? round($b['passes'] / $b['total'] * 100, self::BUSINESS_PCT_DECIMALS) : 0.0,
        ];
        ksort($cleanByStore);
        ksort($cleanByPlace);

        // Customer service: V1 averages every entry's guest service (a missing
        // one counts as 0) and the in→out minutes of the entries that have both.
        $minutes = fn (string $in, string $out): string => "CASE WHEN {$in} IS NOT NULL AND {$out} IS NOT NULL THEN (TIME_TO_SEC({$out}) - TIME_TO_SEC({$in})) / 60 END";
        $service = CustomerService::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(
                'store_number, COUNT(*) as entries, COALESCE(SUM(COALESCE(guest_service, 0)), 0) as guest_service_sum,'
                . " COALESCE(SUM({$minutes('lobby_in', 'lobby_out')}), 0) as lobby_sum, COUNT({$minutes('lobby_in', 'lobby_out')}) as lobby_count,"
                . " COALESCE(SUM({$minutes('drive_thru_in', 'drive_thru_out')}), 0) as drive_thru_sum, COUNT({$minutes('drive_thru_in', 'drive_thru_out')}) as drive_thru_count"
            )
            ->groupBy('store_number')
            ->get();

        $serviceKeys = ['entries', 'guest_service_sum', 'lobby_sum', 'lobby_count', 'drive_thru_sum', 'drive_thru_count'];
        $serviceTotals = [];
        $serviceByStore = [];
        foreach ($service as $row) {
            foreach ($serviceKeys as $k) {
                $serviceTotals[$k] = ($serviceTotals[$k] ?? 0) + (float) $row->$k;
                $serviceByStore[(string) $row->store_number][$k] = (float) $row->$k;
            }
        }
        $avg = fn (array $b): array => [
            'entries' => (int) ($b['entries'] ?? 0),
            'guest_service' => ($b['entries'] ?? 0) > 0 ? round($b['guest_service_sum'] / $b['entries'], 2) : null,
            'lobby_points' => ($b['lobby_count'] ?? 0) > 0 ? round($b['lobby_sum'] / $b['lobby_count'], 2) : null,
            'drive_thru_points' => ($b['drive_thru_count'] ?? 0) > 0 ? round($b['drive_thru_sum'] / $b['drive_thru_count'], 2) : null,
        ];
        ksort($serviceByStore);

        return [
            'cleaning_review' => $score($cleanTotals) + [
                'by_store' => array_map($score, $cleanByStore),
                'by_place' => array_map(fn (string $place, array $b): array => ['review_place' => $place] + $score($b), array_keys($cleanByPlace), $cleanByPlace),
            ],
            'customer_service' => $avg($serviceTotals) + [
                'by_store' => array_map($avg, $serviceByStore),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Section: store score (V1's weekly grade, per store per business week)
    // ---------------------------------------------------------------------

    /**
     * computeStoreScore() for every store and every business week overlapping
     * the range, each scored as of the week's last day inside the range — the
     * same figure V1 shows for that store on that date. The few range-wide
     * lookups the score repeats per week (sales by day, daily rows, Blue Line,
     * HNR+) are fetched once and fed to the request memo.
     */
    private function buildBusinessStoreScores(array $stores, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $weeks = $this->businessWeeks($start, $end);
        $this->primeStoreScoreMemo($stores, $weeks);

        $byStore = [];
        foreach ($stores as $store) {
            $weekScores = [];
            foreach ($weeks as $w) {
                $weekStart = CarbonImmutable::parse($w['week_start']);
                $day = CarbonImmutable::parse($w['end']);
                $result = $this->businessStoreScoreForWeek($store, $weekStart, $day);
                $weekScores[$w['week_start']] = [
                    'as_of' => $day->toDateString(),
                    'score' => $result['score'],
                    'label' => $result['label'],
                    'raw_score' => $result['raw_score'],
                    'non_negotiable' => $result['non_negotiable'],
                    'details' => array_map(fn (array $d): array => array_intersect_key($d, array_flip(['key', 'label', 'score', 'max', 'actual_percent'])), $result['details']),
                ];
            }
            $scores = array_column($weekScores, 'score');
            $average = $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 2);
            $byStore[$store] = [
                'average_score' => $average,
                'average_label' => $this->labelForScore($average),
                'weeks' => $weekScores,
            ];
        }

        return ['by_store' => $byStore];
    }

    /** buildReport()'s inputs to computeStoreScore() for one store, week and day. */
    private function businessStoreScoreForWeek(string $store, CarbonImmutable $weekStart, CarbonImmutable $day): array
    {
        $daysElapsed = (int) $weekStart->diffInDays($day) + 1;
        $prevWeekStart = $weekStart->subWeek();

        $thisWeekByDay = $this->salesByDay($store, $weekStart, $weekStart->addDays(6));
        $previousWeekByDay = $this->salesByDay($store, $prevWeekStart, $weekStart->subDay());
        $weekToDateSales = $this->sumSalesByDayRange($thisWeekByDay, $weekStart, $day);
        $laborAvg = $this->averageValue($this->laborValueSumForRange($store, $weekStart, $day), $daysElapsed);
        $laborAvgPercent = $this->percentOfSales($laborAvg, $this->averageValue($weekToDateSales, $daysElapsed));

        return $this->computeStoreScore(
            $store,
            $weekStart,
            $day,
            $daysElapsed,
            $previousWeekByDay,
            $prevWeekStart,
            $this->sumSalesByDay($previousWeekByDay),
            $weekToDateSales,
            $laborAvgPercent,
            $this->getGoalsForStoreDate($store, $day->toDateString())
        );
    }

    /**
     * Pre-fills the memo keys computeStoreScore() reads so each store-week does
     * not repeat range-wide queries. Values come from the same tables and
     * formulas the helpers use; a week with a missing daily row is left to the
     * helper (getSales backfills that day from the hourly table).
     *
     * @param array<int, array{week_start: string, end: string}> $weeks scored weeks and their as-of day
     */
    private function primeStoreScoreMemo(array $stores, array $weeks): void
    {
        $from = CarbonImmutable::parse($weeks[0]['week_start'])->subWeeks(5);
        $to = CarbonImmutable::parse($weeks[count($weeks) - 1]['end']);

        $daily = DailyStoreSummary::query()->toBase()
            ->whereIn('franchise_store', $stores)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])
            ->get(['franchise_store', 'business_date', 'royalty_obligation', 'hnr_transactions', 'hnr_broken_promises', 'portal_eligible_orders', 'portal_used_orders', 'portal_on_time_orders']);

        $rowsByStore = [];
        foreach ($daily as $row) {
            $row->business_date = substr((string) $row->business_date, 0, 10);
            $rowsByStore[(string) $row->franchise_store][$row->business_date] = $row;
        }

        $blue = [];
        $pepsi = [];
        foreach (InventoryOrder::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('delivery_date', [$from->toDateString(), $to->addWeek()->toDateString()])
            ->selectRaw("store_number, delivery_date, COALESCE(SUM(CASE WHEN vendor_name LIKE '%BLUE LINE%' THEN invoice_total END), 0) as blue, COALESCE(SUM(CASE WHEN vendor_name LIKE '%PEPSI%' THEN invoice_total END), 0) as pepsi")
            ->groupBy('store_number', 'delivery_date')
            ->get() as $row) {
            $date = substr((string) $row->delivery_date, 0, 10);
            $blue[(string) $row->store_number][$date] = (float) $row->blue;
            $pepsi[(string) $row->store_number][$date] = (float) $row->pepsi;
        }

        $hnrPlus = [];
        foreach (HnrPlusItem::query()->toBase()
            ->whereIn('store_number', $stores)
            ->whereBetween('week_start', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('store_number, week_start, COALESCE(SUM(made), 0) as made, COALESCE(SUM(sold), 0) as sold, COALESCE(SUM(voided), 0) as voided, COALESCE(SUM(wasted), 0) as wasted, COALESCE(SUM(variance), 0) as variance, COALESCE(SUM(no_inventory_available), 0) as no_inventory_available')
            ->groupBy('store_number', 'week_start')
            ->get() as $row) {
            $hnrPlus[(string) $row->store_number][substr((string) $row->week_start, 0, 10)] = [
                'made' => (int) $row->made,
                'sold' => (int) $row->sold,
                'voided' => (int) $row->voided,
                'wasted' => (int) $row->wasted,
                'variance' => (int) $row->variance,
                'no_inventory_available' => (int) $row->no_inventory_available,
            ];
        }

        // Sum of a [date => value] map over [s, e], walking the days (a few weeks at most).
        $sumDays = function (array $byDate, CarbonImmutable $s, CarbonImmutable $e): float {
            $total = 0.0;
            for ($d = $s; $d->lte($e); $d = $d->addDay()) {
                $total += (float) ($byDate[$d->toDateString()] ?? 0);
            }

            return $total;
        };
        $noHnrPlus = ['made' => 0, 'sold' => 0, 'voided' => 0, 'wasted' => 0, 'variance' => 0, 'no_inventory_available' => 0];

        foreach ($stores as $store) {
            $rows = $rowsByStore[$store] ?? [];
            $sales = array_map(fn ($r): float => (float) $r->royalty_obligation, $rows);

            foreach ($weeks as $week) {
                $weekStart = CarbonImmutable::parse($week['week_start']);
                $weekEnd = $weekStart->addDays(6);
                $day = CarbonImmutable::parse($week['end']);

                // salesByDay(store, this week) and (store, previous week), when no needed day is missing.
                foreach ([$weekStart, $weekStart->subWeek()] as $w) {
                    $byDay = [];
                    $complete = true;
                    for ($d = $w; $d->lte($w->addDays(6)); $d = $d->addDay()) {
                        $key = $d->toDateString();
                        $complete = $complete && (isset($rows[$key]) || $d->gt($day));
                        $byDay[$key] = (float) ($sales[$key] ?? 0);
                    }
                    if ($complete) {
                        $this->memo["salesByDay:{$store}:{$w->toDateString()}:{$w->addDays(6)->toDateString()}"] = $byDay;
                    }
                }

                // dailySummaryRowsForRange(store, weekStart, day).
                $weekRows = [];
                for ($d = $weekStart; $d->lte($day); $d = $d->addDay()) {
                    if (isset($rows[$d->toDateString()])) {
                        $weekRows[] = $rows[$d->toDateString()];
                    }
                }
                $this->memo["dailySummaryRows:{$store}:{$weekStart->toDateString()}:{$day->toDateString()}"] = $weekRows;

                // Orders vs sales: the effective-week check, then the 4-week
                // Blue Line and sales totals for either effective week. Ranges
                // over 14 days read only the daily table, so the sum is exact.
                $this->memo["blueLineTotalForRange:{$store}:{$weekStart->toDateString()}:{$weekEnd->toDateString()}"] = $sumDays($blue[$store] ?? [], $weekStart, $weekEnd);
                $this->memo["pepsiTotalForRange:{$store}:{$weekStart->toDateString()}:{$weekEnd->toDateString()}"] = $sumDays($pepsi[$store] ?? [], $weekStart, $weekEnd);
                foreach ([$day, $day->subWeek()] as $effDay) {
                    $effStart = $effDay->startOfWeek(CarbonInterface::TUESDAY)->subWeeks(3);
                    $range = "{$store}:{$effStart->toDateString()}:{$effDay->toDateString()}";
                    $this->memo["blueLineTotalForRange:{$range}"] = $sumDays($blue[$store] ?? [], $effStart, $effDay);
                    $this->memo["salesTotal:{$range}"] = $sumDays($sales, $effStart, $effDay);
                }

                // HNR+ (hnrPlusEffectiveWeek / hnrPlusTotalsForWeek): this week, or the one before when this one has no rows.
                $prev = $weekStart->subWeek()->toDateString();
                $this->memo["hnrPlusTotalsForWeek:{$store}:{$weekStart->toDateString()}"] = $hnrPlus[$store][$weekStart->toDateString()] ?? $noHnrPlus;
                $this->memo["hnrPlusTotalsForWeek:{$store}:{$prev}"] = $hnrPlus[$store][$prev] ?? $noHnrPlus;
                $this->memo["hnrPlusEffectiveWeek:{$store}:{$weekStart->toDateString()}:{$weekEnd->toDateString()}"] = isset($hnrPlus[$store][$weekStart->toDateString()])
                    ? ['start' => $weekStart, 'end' => $weekEnd, 'used_previous_week' => false]
                    : ['start' => $weekStart->subWeek(), 'end' => $weekEnd->subWeek(), 'used_previous_week' => true];
            }
        }
    }

    // ---------------------------------------------------------------------
    // Shared helpers
    // ---------------------------------------------------------------------

    /**
     * Hot + archive rows of a routed table for the range, already narrowed to
     * the stores inside each half of the union (so the filter is not left to
     * the derived table).
     */
    /**
     * A grouped read of a raw hot/archive table. $shape builds the grouped
     * query from $part($alias): the table's rows for the stores and dates in
     * ONE part (archive or hot), as a derived table MySQL merges into
     * the query — so it groups straight on the table and its indexes, never
     * copying every row first as a UNION of both parts would. Each part runs
     * on its own connection; rows with the same $keys from the two parts are
     * added together (SUM/COUNT columns only).
     */
    private function businessGrouped(string $table, array $stores, CarbonImmutable $start, CarbonImmutable $end, array $keys, callable $shape): Collection
    {
        $routed = DatabaseRouter::routedQueries($table, $start->toMutable(), $end->toMutable());
        $rows = collect();

        foreach ($routed as $routedPart) {
            $part = function (string $alias) use ($routedPart, $stores): Builder {
                $query = (clone $routedPart)->whereIn('franchise_store', $stores);

                return $query->getConnection()->query()->fromSub($query, $alias);
            };
            $rows = $rows->concat($shape($part)->get());
        }

        // Hand the raw-table connections back now rather than holding them for
        // the rest of the request: every service shares one MySQL server with
        // few connections. (A later read reconnects on its own.)
        foreach ($routed as $routedPart) {
            $routedPart->getConnection()->disconnect();
        }

        return count($routed) > 1 ? $this->businessSumBy($rows, $keys) : $rows;
    }

    /** Rows with the same $keys added together (numeric columns; the rest from the first row). */
    private function businessSumBy(Collection $rows, array $keys): Collection
    {
        return $rows
            ->groupBy(fn (object $row): string => implode('|', array_map(fn (string $k): string => (string) $row->$k, $keys)))
            ->map(function (Collection $group) use ($keys): object {
                $out = clone $group->first();
                foreach ($group->slice(1) as $row) {
                    foreach (get_object_vars($row) as $k => $v) {
                        if (!in_array($k, $keys, true) && is_numeric($v)) {
                            $out->$k = (float) $out->$k + (float) $v;
                        }
                    }
                }

                return $out;
            })
            ->values();
    }

    /** SQL for the Tuesday that starts the business week of a DATE expression (see buildSalesHistory). */
    private function businessWeekSql(string $dateExpr): string
    {
        return "DATE_SUB({$dateExpr}, INTERVAL ((DAYOFWEEK({$dateExpr}) + 4) % 7) DAY)";
    }

    private function businessWeekOf(string $date): string
    {
        return CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::TUESDAY)->toDateString();
    }

    /** Adds $vals to the overall, per-store, per-week and per-store-week bags. */
    private function businessFold(array &$totals, array &$byStore, array &$byWeek, array &$storeWeek, string $store, string $week, array $vals): void
    {
        $week = substr($week, 0, 10);
        foreach ($vals as $k => $v) {
            $totals[$k] = ($totals[$k] ?? 0) + $v;
            $byStore[$store][$k] = ($byStore[$store][$k] ?? 0) + $v;
            $byWeek[$week][$k] = ($byWeek[$week][$k] ?? 0) + $v;
            $storeWeek[$store][$week][$k] = ($storeWeek[$store][$week][$k] ?? 0) + $v;
        }
    }

    /** Ordered per-week list over every week of the range (weeks without data are zero-filled). */
    private function businessWeekList(array $byWeek, CarbonImmutable $start, CarbonImmutable $end, callable $finish): array
    {
        return array_map(
            fn (array $w): array => $w + $finish($byWeek[$w['week_start']] ?? []),
            $this->businessWeeks($start, $end)
        );
    }

    /** [store => [date => value]] from rows of (franchise_store, business_date, v). */
    private function businessStoreDateMap(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $date = substr((string) $row->business_date, 0, 10);
            $out[(string) $row->franchise_store][$date] = ($out[(string) $row->franchise_store][$date] ?? 0) + (float) $row->v;
        }

        return $out;
    }

    /** Rounds a summed bag and adds the derived ratios (same definitions as the single-store reports). */
    private function businessDerive(array $b): array
    {
        $sales = (float) ($b['royalty_obligation'] ?? 0);
        $orders = (float) ($b['total_orders'] ?? 0);
        $customers = (float) ($b['customer_count'] ?? 0);
        $out = [];
        foreach ($b as $k => $v) {
            $out[$k] = is_float($v) ? round($v, 2) : $v;
        }

        $out['sales'] = round($sales, 2);
        $out['labor_cost'] = round((float) ($b['labor_cost'] ?? 0), 2);
        $out['labor_percent'] = $this->percentOfSales((float) ($b['labor_cost'] ?? 0), $sales, self::BUSINESS_PCT_DECIMALS);
        $out['deposit'] = round((float) ($b['deposit'] ?? 0), 2);
        $out['deposit_minus_cash_sales'] = round((float) ($b['deposit'] ?? 0) - (float) ($b['cash_sales'] ?? 0), 2);
        $out['waste_normal'] = round((float) ($b['waste_normal'] ?? 0), 2);
        $out['waste_alta'] = round((float) ($b['waste_alta'] ?? 0), 2);
        $out['avg_ticket'] = $customers > 0 ? round($sales / $customers, 2) : 0.0;
        $out['avg_order_value'] = $orders > 0 ? round($sales / $orders, 2) : 0.0;

        if (array_key_exists('phone_sales', $b)) {
            $channels = 0.0;
            foreach (self::BUSINESS_CHANNELS as $ch) {
                $channels += (float) ($b["{$ch}_sales"] ?? 0);
            }
            $out['register_sales'] = round($sales - $channels, 2);
            $out['delivery_rate'] = $orders > 0 ? round(($b['delivery_orders'] ?? 0) / $orders * 100, self::BUSINESS_PCT_DECIMALS) : 0.0;
            $out['digital_penetration'] = ($b['gross_sales'] ?? 0) > 0 ? round(($b['digital_sales'] ?? 0) / $b['gross_sales'] * 100, self::BUSINESS_PCT_DECIMALS) : 0.0;
            $out += array_diff_key($this->hnrTotals($b, self::BUSINESS_PCT_DECIMALS), ['hnr_transactions' => 1, 'hnr_broken_promises' => 1]);
        }

        return $out;
    }

    /** The few figures the store × week grid needs. */
    private function businessCompact(array $b): array
    {
        $sales = (float) ($b['royalty_obligation'] ?? 0);

        return [
            'sales' => round($sales, 2),
            'customer_count' => (int) ($b['customer_count'] ?? 0),
            'total_orders' => (int) ($b['total_orders'] ?? 0),
            'labor_cost' => round((float) ($b['labor_cost'] ?? 0), 2),
            'labor_percent' => $this->percentOfSales((float) ($b['labor_cost'] ?? 0), $sales, self::BUSINESS_PCT_DECIMALS),
            'store_days' => (int) ($b['store_days'] ?? 0),
        ];
    }
}
