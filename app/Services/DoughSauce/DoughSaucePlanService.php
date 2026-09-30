<?php

namespace App\Services\DoughSauce;

use App\Models\Dough_SauceIngredient;
use App\Models\Dough_SauceRecipe;
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
 * WHY TWO QUERIES AND NOT A JOIN
 * ------------------------------
 * Sales live in `aggregation`; the recipe tables live in the application
 * database. Those are separate schemas with their own host settings in
 * config/database.php, so a statement joining across them could work on one
 * machine and fail on another. Instead: read the recipes (about 82 rows), read
 * the sales for four dates, multiply in PHP. The recipe side is small and fully
 * indexed, the sales side is one grouped read of a partitioned table, and the
 * multiplication is a few hundred iterations.
 *
 * The date arithmetic that a SQL join used to do is done here instead, by
 * `recipeMap()` building one map per date from the effective-dated rows. Same
 * result: each day is multiplied by the recipe that was in force on that day, not
 * by today's.
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

        $sales       = $this->salesByDateAndItem($store, $dates, $includeRefunded);
        $recipes     = $this->recipeMap($dates);
        $ingredients = $this->ingredients();

        $totals    = $this->multiply($sales, $recipes);
        $daysFound = count(array_unique(array_column($sales, 'business_date')));

        $rows = [];

        foreach ($ingredients as $ingredient) {
            $values = [];

            foreach ($dates as $d) {
                if (isset($totals[$d][$ingredient->key])) {
                    $values[$d] = $totals[$d][$ingredient->key];
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
            'unmapped'         => $this->unmapped($sales, $recipes),
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
     * What sold, per date and item — the only read against `aggregation`.
     *
     * Already grouped by the database, so what comes back is one row per item per
     * day: a few hundred rows for a four-date window, not order lines.
     *
     * @param  array<int, string>  $dates
     * @return array<int, array<string, mixed>>
     */
    private function salesByDateAndItem(string $store, array $dates, bool $includeRefunded): array
    {
        $quantity = $includeRefunded
            ? 's.quantity_sold'
            : '(s.quantity_sold - s.refunded_quantity)';

        return DB::connection('aggregation')
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
                // Kept separate from `quantity`: the unmapped report counts what was
                // sold, which should not move when the caller flips include_refunded.
                DB::raw('SUM(s.quantity_sold) as quantity_sold'),
            ])
            ->map(fn ($r) => [
                'business_date'     => Carbon::parse($r->business_date)->toDateString(),
                'item_id'           => (string) $r->item_id,
                'menu_item_name'    => $r->menu_item_name,
                'menu_item_account' => $r->menu_item_account,
                'quantity'          => (float) $r->quantity,
                'quantity_sold'     => (float) $r->quantity_sold,
            ])
            ->all();
    }

    /**
     * The recipe in force on each date: date -> item_id -> ingredient key -> qty.
     *
     * This is the effective-dating rule the SQL join used to express, moved into
     * PHP because the two sides now live in different databases. One read of the
     * rows that touch the window at all, then a pass per date. ~82 rows.
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
     * Sales x recipe, summed per date and ingredient.
     *
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
     * Costs no query of its own — it is the same sales rows the plan was built
     * from, filtered to the ones the recipe map had nothing for.
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
