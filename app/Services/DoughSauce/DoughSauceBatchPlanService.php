<?php

namespace App\Services\DoughSauce;

use App\Models\Dough_SauceIngredient;
use App\Models\Dough_SauceRecipe;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DoughSaucePlanService::dailyPlan() for many stores x many dates, in one call.
 *
 * WHY IT EXISTS
 * -------------
 * The weekly report checks every unplanned day of every store against its base.
 * Done one date per request that was ~1,300 browser calls, each paying its own
 * HTTP round trip and its own token check against pizzasys — ~250 ms apiece,
 * almost none of it spent on the actual query.
 *
 * WHAT IT READS
 * -------------
 * Every target date needs its own four source dates (-7, -14, -21, -28). Those
 * overlap heavily across a week, so the union is read once:
 *
 *   recipes      — once for every store, spanning min..max of the source dates
 *   ingredients  — once for every store
 *   sales        — one grouped read PER STORE
 *
 * Why sales per store and not one read for all of them: 44 stores x ~49 source
 * days x ~150 items is ~300k rows, which as PHP arrays is hundreds of MB. One
 * store at a time keeps memory to one store's rows, and the read is on the
 * (franchise_store, business_date) index either way. The caller still makes
 * one request.
 *
 * Then each target date is assembled with exactly the arithmetic of
 * dailyPlan(). The per-date object is the same shape the single endpoint
 * returns, so a client reads the answer the same way.
 */
class DoughSauceBatchPlanService
{
    /** Kept in step with DoughSaucePlanService::CONSUMING_ACCOUNTS. */
    private const CONSUMING_ACCOUNTS = ['Pizza', 'HNR', 'Bread'];

    public function __construct(private readonly DoughSaucePlanService $single)
    {
    }

    /**
     * @param  array<int, string>  $stores       franchise_store codes
     * @param  array<int, string>  $targetDates  Y-m-d
     * @return array<string, array<string, array<string, mixed>>>  store -> date -> plan
     */
    public function dailyPlans(
        array $stores,
        array $targetDates,
        int $lookback = 4,
        bool $includeRefunded = false,
    ): array {
        $sourcesByTarget = [];

        foreach ($targetDates as $target) {
            $sourcesByTarget[$target] = $this->single->sourceDates(Carbon::parse($target), $lookback);
        }

        $allSources = array_values(array_unique(array_merge(...array_values($sourcesByTarget))));
        sort($allSources);

        // Shared by every store: a recipe is the same in all 44.
        $recipes     = $this->recipeMap($allSources);
        $ingredients = $this->ingredients();

        $out = [];

        foreach ($stores as $store) {
            $salesByDate = $this->salesByDate($store, $allSources, $includeRefunded);

            foreach ($sourcesByTarget as $target => $dates) {
                $sales = [];
                foreach ($dates as $d) {
                    array_push($sales, ...($salesByDate[$d] ?? []));
                }

                $out[$store][$target] = $this->assemble($store, Carbon::parse($target), $dates, $sales, $recipes, $ingredients, $lookback, $includeRefunded);
            }
        }

        return $out;
    }

    /**
     * Same body as DoughSaucePlanService::dailyPlan() after its reads.
     *
     * @param  array<int, string>  $dates
     * @param  array<int, array<string, mixed>>  $sales
     * @param  array<string, array<string, array<string, float>>>  $recipes
     * @return array<string, mixed>
     */
    private function assemble(
        string $store,
        Carbon $date,
        array $dates,
        array $sales,
        array $recipes,
        $ingredients,
        int $lookback,
        bool $includeRefunded,
    ): array {
        $totals    = $this->multiply($sales, $recipes);
        $daysFound = count(array_unique(array_column($sales, 'business_date')));

        $rows = [];

        foreach ($ingredients as $ingredient) {
            $values = [];

            foreach ($dates as $d) {
                if (isset($totals[$d][$ingredient->key])) {
                    $values[$d] = $totals[$d][$ingredient->key];
                }
            }

            $average = $values ? array_sum($values) / count($values) : 0.0;
            $divisor = (float) $ingredient->divisor ?: 1.0;

            $rows[] = [
                'key'     => $ingredient->key,
                'name'    => $ingredient->name,
                'unit'    => $ingredient->unit,
                'divisor' => $divisor,
                'values'  => array_map(
                    fn ($d) => isset($values[$d]) ? round($values[$d], 4) : null,
                    $dates
                ),
                'average' => round($average, 4),
                'base'    => round($average / $divisor, 4),
            ];
        }

        return [
            'store'            => $store,
            'date'             => $date->toDateString(),
            'weekday'          => $date->format('l'),
            'source_dates'     => $dates,
            'days_found'       => $daysFound,
            'lookback'         => $lookback,
            'include_refunded' => $includeRefunded,
            'ingredients'      => $rows,
            'unmapped'         => $this->unmapped($sales, $recipes),
        ];
    }

    /**
     * One grouped read for every source date, bucketed by date.
     *
     * @param  array<int, string>  $dates
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function salesByDate(string $store, array $dates, bool $includeRefunded): array
    {
        if (! $dates) {
            return [];
        }

        $quantity = $includeRefunded
            ? 's.quantity_sold'
            : '(s.quantity_sold - s.refunded_quantity)';

        $rows = DB::connection('aggregation')
            ->table('daily_item_summary as s')
            ->where('s.franchise_store', $store)
            ->whereIn('s.business_date', $dates)
            ->groupBy('s.business_date', 's.item_id')
            ->orderBy('s.business_date')
            ->get([
                DB::raw('s.business_date as business_date'),
                's.item_id',
                DB::raw('MAX(s.menu_item_name) as menu_item_name'),
                DB::raw('MAX(s.menu_item_account) as menu_item_account'),
                DB::raw("SUM({$quantity}) as quantity"),
                DB::raw('SUM(s.quantity_sold) as quantity_sold'),
            ]);

        $byDate = [];

        foreach ($rows as $r) {
            $d = Carbon::parse($r->business_date)->toDateString();

            $byDate[$d][] = [
                'business_date'     => $d,
                'item_id'           => (string) $r->item_id,
                'menu_item_name'    => $r->menu_item_name,
                'menu_item_account' => $r->menu_item_account,
                'quantity'          => (float) $r->quantity,
                'quantity_sold'     => (float) $r->quantity_sold,
            ];
        }

        return $byDate;
    }

    /**
     * date -> item_id -> ingredient key -> qty, effective-dated per date.
     *
     * @param  array<int, string>  $dates
     * @return array<string, array<string, array<string, float>>>
     */
    private function recipeMap(array $dates): array
    {
        if (! $dates) {
            return [];
        }

        $rows = Dough_SauceRecipe::query()
            ->effectiveWithin(min($dates), max($dates))
            ->with(['menuItem:id,item_id', 'ingredient:id,key'])
            ->get();

        $map = [];

        foreach ($dates as $date) {
            $map[$date] = [];

            foreach ($rows as $row) {
                $from = $row->effective_from?->toDateString();
                $to   = $row->effective_to?->toDateString();

                if ($from === null || $from > $date) {
                    continue;
                }

                if ($to !== null && $to < $date) {
                    continue;
                }

                $itemId = $row->menuItem?->item_id;
                $key    = $row->ingredient?->key;

                if ($itemId === null || $key === null) {
                    continue;
                }

                $map[$date][$itemId][$key] = (float) $row->qty;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array<string, mixed>>  $sales
     * @param  array<string, array<string, array<string, float>>>  $recipes
     * @return array<string, array<string, float>>
     */
    private function multiply(array $sales, array $recipes): array
    {
        $totals = [];

        foreach ($sales as $row) {
            $lines = $recipes[$row['business_date']][$row['item_id']] ?? null;

            if (! $lines) {
                continue;
            }

            foreach ($lines as $key => $qty) {
                $totals[$row['business_date']][$key] =
                    ($totals[$row['business_date']][$key] ?? 0.0) + $row['quantity'] * $qty;
            }
        }

        return $totals;
    }

    /**
     * Items that sold in a consuming account with no recipe — see
     * DoughSaucePlanService::unmapped() for why this is not optional.
     *
     * @param  array<int, array<string, mixed>>  $sales
     * @param  array<string, array<string, array<string, float>>>  $recipes
     * @return array<int, array<string, mixed>>
     */
    private function unmapped(array $sales, array $recipes): array
    {
        $out = [];

        foreach ($sales as $row) {
            if (! in_array($row['menu_item_account'], self::CONSUMING_ACCOUNTS, true)) {
                continue;
            }

            if (! empty($recipes[$row['business_date']][$row['item_id']])) {
                continue;
            }

            $id = $row['item_id'];

            if (! isset($out[$id])) {
                $out[$id] = [
                    'item_id'  => $id,
                    'name'     => $row['menu_item_name'],
                    'account'  => $row['menu_item_account'],
                    'quantity' => 0,
                ];
            }

            $out[$id]['quantity'] += (int) $row['quantity_sold'];
        }

        usort($out, fn ($a, $b) => $b['quantity'] <=> $a['quantity']);

        return array_values($out);
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Dough_SauceIngredient> */
    private function ingredients()
    {
        return Dough_SauceIngredient::query()
            ->active()
            ->orderBy('sort_order')
            ->get(['key', 'name', 'unit', 'divisor']);
    }
}
