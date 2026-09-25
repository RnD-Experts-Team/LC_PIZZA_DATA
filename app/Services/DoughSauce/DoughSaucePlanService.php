<?php

namespace App\Services\DoughSauce;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * How much dough and sauce a store needed on the same weekday, averaged over the
 * last four of them.
 *
 * WHAT THIS REPLACES
 * ------------------
 * A spreadsheet that pulled raw order lines, multiplied each sold item by a
 * recipe table, averaged four matching weekdays and divided by a per-ingredient
 * divisor. Everything above is the same arithmetic; what changes is that the
 * recipe table is a table, the multipliers that were hidden inside formulas are
 * data, and an item the recipe table does not know about is reported instead of
 * being silently counted as zero.
 *
 * WHY IT IS CHEAP
 * ---------------
 * daily_item_summary already exists and is already maintained — order_line_hot ->
 * hourly_item_summary -> daily_item_summary, rebuilt twice a day. So this reads
 * one pre-aggregated, indexed, partitioned table for four specific dates rather
 * than scanning order lines. The four dates are also all at least 7 days old,
 * which puts them at or before the rebuild window's start: they are settled.
 *
 * WHAT IT DOES NOT DO
 * -------------------
 * It does not apply a buffer and it does not produce a plan. The buffer is a
 * store manager's decision and lives in AuditApp. This returns `base`; someone
 * else multiplies.
 */
class DoughSaucePlanService
{
    /** Accounts whose items are expected to consume dough or sauce. */
    private const CONSUMING_ACCOUNTS = ['Pizza', 'HNR', 'Bread'];

    /**
     * @return array<string, mixed>
     */
    public function dailyPlan(
        string $store,
        CarbonInterface $date,
        int $lookback = 4,
        bool $includeRefunded = false,
    ): array {
        $dates = $this->sourceDates($date, $lookback);

        $totals     = $this->totalsByDateAndIngredient($store, $dates, $includeRefunded);
        $ingredients = $this->ingredients();

        $rows      = [];
        $daysFound = count(array_unique(array_column($totals, 'business_date')));

        foreach ($ingredients as $ingredient) {
            $values = [];

            foreach ($dates as $d) {
                foreach ($totals as $row) {
                    if ($row['business_date'] === $d && $row['ingredient_key'] === $ingredient->key) {
                        $values[$d] = (float) $row['units'];
                        continue 2;
                    }
                }
                // A date with no row for this ingredient contributes nothing. It is
                // reported through days_found rather than being averaged as a zero.
            }

            $average = $values ? array_sum($values) / count($values) : 0.0;
            $divisor = (float) $ingredient->divisor ?: 1.0;

            $rows[] = [
                'key'      => $ingredient->key,
                'name'     => $ingredient->name,
                'unit'     => $ingredient->unit,
                'divisor'  => $divisor,
                // Keyed by date so a missing day is visibly missing rather than
                // shifting the others along.
                'values'   => array_map(
                    fn ($d) => isset($values[$d]) ? round($values[$d], 4) : null,
                    $dates
                ),
                'average'  => round($average, 4),
                // What the caller multiplies by its buffer.
                'base'     => round($average / $divisor, 4),
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
            'unmapped'         => $this->unmapped($store, $dates),
        ];
    }

    /**
     * The same weekday, `$lookback` times back.
     *
     * Plain date arithmetic — the previous four Fridays are just -7, -14, -21, -28.
     * No accounting calendar is involved here; that belongs to whoever measures
     * weeks, which is not this service.
     *
     * @return array<int, string>
     */
    public function sourceDates(CarbonInterface $date, int $lookback = 4): array
    {
        $dates = [];

        for ($i = $lookback; $i >= 1; $i--) {
            $dates[] = $date->copy()->subWeeks($i)->toDateString();
        }

        return $dates;
    }

    /**
     * Ingredient units sold per date — sales x recipe, summed.
     *
     * One query. The recipe join is date-bounded so each day is multiplied by the
     * recipe that was in force on that day, not today's.
     *
     * @param  array<int, string>  $dates
     * @return array<int, array<string, mixed>>
     */
    private function totalsByDateAndIngredient(string $store, array $dates, bool $includeRefunded): array
    {
        $quantity = $includeRefunded
            ? 's.quantity_sold'
            : '(s.quantity_sold - s.refunded_quantity)';

        return DB::connection('aggregation')
            ->table('daily_item_summary as s')
            ->join('ds_menu_items as m', 'm.item_id', '=', 's.item_id')
            ->join('ds_recipes as r', function ($join) {
                $join->on('r.ds_menu_item_id', '=', 'm.id')
                    ->whereColumn('s.business_date', '>=', 'r.effective_from')
                    ->where(fn ($q) => $q->whereNull('r.effective_to')
                        ->orWhereColumn('s.business_date', '<=', 'r.effective_to'));
            })
            ->join('ds_ingredients as i', 'i.id', '=', 'r.ds_ingredient_id')
            ->where('s.franchise_store', $store)
            ->whereIn('s.business_date', $dates)
            ->groupBy('s.business_date', 'i.key')
            ->orderBy('s.business_date')
            ->get([
                DB::raw('s.business_date as business_date'),
                DB::raw('i.`key` as ingredient_key'),
                DB::raw("SUM({$quantity} * r.qty) as units"),
            ])
            ->map(fn ($r) => [
                'business_date'  => Carbon::parse($r->business_date)->toDateString(),
                'ingredient_key' => $r->ingredient_key,
                'units'          => (float) $r->units,
            ])
            ->all();
    }

    /**
     * Items that sold, whose account says they consume dough or sauce, and that no
     * recipe covers.
     *
     * THIS IS NOT OPTIONAL. The spreadsheet's lookup was IFERROR(VLOOKUP(...), 0):
     * an item it did not recognise became a zero, indistinguishable from an item
     * that genuinely sold none. On real data that was 17 items and 1,772 units —
     * Webberoni alone was 234 whole pizzas counted as needing no dough — leaving
     * every plan about 1.7% short with nobody informed.
     *
     * Returning these in the same response is the difference between fixing that
     * bug and moving it into a codebase where it is harder to find.
     *
     * The account filter matters as much as the check: a soft drink with no recipe
     * is correct, and warning about it would teach people to ignore the warning
     * that matters.
     *
     * @param  array<int, string>  $dates
     * @return array<int, array<string, mixed>>
     */
    private function unmapped(string $store, array $dates): array
    {
        return DB::connection('aggregation')
            ->table('daily_item_summary as s')
            ->leftJoin('ds_menu_items as m', 'm.item_id', '=', 's.item_id')
            ->leftJoin('ds_recipes as r', function ($join) {
                $join->on('r.ds_menu_item_id', '=', 'm.id')
                    ->whereColumn('s.business_date', '>=', 'r.effective_from')
                    ->where(fn ($q) => $q->whereNull('r.effective_to')
                        ->orWhereColumn('s.business_date', '<=', 'r.effective_to'));
            })
            ->where('s.franchise_store', $store)
            ->whereIn('s.business_date', $dates)
            ->whereIn('s.menu_item_account', self::CONSUMING_ACCOUNTS)
            ->whereNull('r.id')
            ->groupBy('s.item_id')
            ->orderByDesc(DB::raw('SUM(s.quantity_sold)'))
            ->get([
                's.item_id',
                DB::raw('MAX(s.menu_item_name) as name'),
                DB::raw('MAX(s.menu_item_account) as account'),
                DB::raw('SUM(s.quantity_sold) as quantity'),
            ])
            ->map(fn ($r) => [
                'item_id'  => $r->item_id,
                'name'     => $r->name,
                'account'  => $r->account,
                'quantity' => (int) $r->quantity,
            ])
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    private function ingredients()
    {
        return DB::connection('aggregation')
            ->table('ds_ingredients')
            ->where('active', true)
            ->orderBy('sort_order')
            ->get(['key', 'name', 'unit', 'divisor']);
    }
}
