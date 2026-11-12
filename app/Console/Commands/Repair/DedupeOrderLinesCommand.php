<?php

namespace App\Console\Commands\Repair;

use App\Services\Aggregation\AggregationService;
use App\Services\Database\DatabaseRouter;
use App\Support\FiscalCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Finds orders whose lines were imported more than once and, with --apply,
 * removes the older copies.
 *
 * Each import writes a store-day's lines as one block of consecutive ids, so
 * a store-day imported again holds a second block far from the first. The
 * blocks are not always identical: a later export can be partial, or carry
 * times without seconds, so lines are matched by ORDER, not by content. Two
 * blocks are copies of each other when they share orders (MIN_SHARED_ORDERS
 * or more); then each shared order keeps only its lines in the newest block.
 * Lines repeated inside one block (the same item twice in an order) are
 * never touched, and a day imported once is never touched.
 *
 * Read-only unless --apply. --rebuild then rebuilds hourly → daily (with the
 * order extras) → weeks/periods/quarters/years for the repaired days only.
 */
class DedupeOrderLinesCommand extends Command
{
    protected $signature = 'data:dedupe-order-lines
        {--from= : First business date (Y-m-d)}
        {--to= : Last business date (Y-m-d)}
        {--store=* : Only these stores (store numbers)}
        {--apply : Delete the older copies (otherwise only report)}
        {--rebuild : After --apply, rebuild the summaries of the repaired days}
        {--pause-ms=250 : Pause between days, to spare the shared MySQL server}';

    protected $description = 'Find (and with --apply remove) order lines imported more than once.';

    /** Ids further apart than this belong to different imports (one order's lines are always consecutive). */
    private const IMPORT_GAP = 1000;

    /** Two import blocks sharing at least this many orders are copies of the same data. */
    private const MIN_SHARED_ORDERS = 3;

    private const DELETE_CHUNK = 1000;

    public function handle(AggregationService $aggregation): int
    {
        if (!$this->option('from') || !$this->option('to')) {
            $this->error('--from and --to are required.');

            return self::FAILURE;
        }

        $from = CarbonImmutable::parse((string) $this->option('from'))->startOfDay();
        $to = CarbonImmutable::parse((string) $this->option('to'))->startOfDay();
        $stores = array_values(array_filter((array) $this->option('store')));
        $apply = (bool) $this->option('apply');
        $pauseMs = max(0, (int) $this->option('pause-ms'));

        $byDate = [];
        $totals = ['store_days' => 0, 'orders' => 0, 'lines' => 0, 'extra' => 0];
        $repairedDates = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            [$query] = DatabaseRouter::routedQueries('order_line', $day->toMutable(), $day->toMutable());
            $table = $query->from;
            $connection = $query->getConnection();
            if ($stores !== []) {
                $query->whereIn('franchise_store', $stores);
            }

            $lines = $query->whereNotNull('order_id')
                ->orderBy('franchise_store')
                ->orderBy('id')
                ->get(['id', 'franchise_store', 'order_id'])
                ->groupBy('franchise_store');

            $dayStores = 0;
            $dayOrders = 0;
            $dayLines = 0;
            $extra = [];
            foreach ($lines as $storeLines) {
                [$storeExtra, $orders] = $this->olderCopies($storeLines->all());
                if ($storeExtra === []) {
                    continue;
                }
                $dayStores++;
                $dayOrders += $orders;
                $dayLines += $storeLines->count();
                array_push($extra, ...$storeExtra);
            }

            if ($extra !== []) {
                $byDate[] = [$day->toDateString(), $dayStores, $dayOrders, $dayLines, count($extra)];
                $totals['store_days'] += $dayStores;
                $totals['orders'] += $dayOrders;
                $totals['lines'] += $dayLines;
                $totals['extra'] += count($extra);

                if ($apply) {
                    $connection->transaction(function () use ($connection, $table, $day, $extra): void {
                        foreach (array_chunk($extra, self::DELETE_CHUNK) as $chunk) {
                            $connection->table($table)
                                ->where('business_date', $day->toDateString())
                                ->whereIn('id', $chunk)
                                ->delete();
                        }
                    });
                    $repairedDates[$day->toDateString()] = true;
                }
            }

            $connection->disconnect();
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        $this->table(['Date', 'Stores', 'Orders imported again', 'Lines', $apply ? 'Removed' : 'Would remove'], $byDate);
        $this->info(sprintf(
            '%d store-days, %d orders imported more than once: %d of their %d lines are older copies%s.',
            $totals['store_days'],
            $totals['orders'],
            $totals['extra'],
            $totals['lines'],
            $apply ? ' and were removed' : ' (nothing removed — add --apply)',
        ));

        if ($apply && $this->option('rebuild') && $repairedDates !== []) {
            $this->rebuild($aggregation, array_keys($repairedDates), $pauseMs);
        }

        return self::SUCCESS;
    }

    /**
     * One store-day's lines (sorted by id) → [ids of the older copies, orders imported again].
     *
     * @param  list<object{id: int, order_id: string}>  $lines
     * @return array{0: list<int>, 1: int}
     */
    private function olderCopies(array $lines): array
    {
        // Split into import blocks at every large id jump.
        $blockOf = [];
        $ordersIn = [];
        $block = 0;
        $previous = null;
        foreach ($lines as $i => $line) {
            if ($previous !== null && $line->id - $previous > self::IMPORT_GAP) {
                $block++;
            }
            $previous = $line->id;
            $blockOf[$i] = $block;
            $ordersIn[$block][$line->order_id] = true;
        }
        if ($block === 0) {
            return [[], 0];
        }

        // Blocks that share orders are copies of each other.
        $copies = [];
        for ($a = 0; $a < $block; $a++) {
            for ($b = $a + 1; $b <= $block; $b++) {
                if (count(array_intersect_key($ordersIn[$a], $ordersIn[$b])) >= self::MIN_SHARED_ORDERS) {
                    $copies["{$a}|{$b}"] = true;
                }
            }
        }
        if ($copies === []) {
            return [[], 0];
        }

        // Each order keeps its lines in the newest block holding it; its lines in older copies go.
        $newest = [];
        foreach ($lines as $i => $line) {
            $newest[$line->order_id] = max($newest[$line->order_id] ?? 0, $blockOf[$i]);
        }
        $extra = [];
        $orders = [];
        foreach ($lines as $i => $line) {
            $keep = $newest[$line->order_id];
            if ($blockOf[$i] < $keep && isset($copies["{$blockOf[$i]}|{$keep}"])) {
                $extra[] = (int) $line->id;
                $orders[$line->order_id] = true;
            }
        }

        return [$extra, count($orders)];
    }

    /** Hourly → daily (with order extras) for each repaired day, then every week/period/quarter/year they touch. */
    private function rebuild(AggregationService $aggregation, array $dates, int $pauseMs): void
    {
        sort($dates);
        $weeks = [];
        $periods = [];
        $quarters = [];
        $years = [];

        foreach ($dates as $date) {
            $day = Carbon::parse($date);
            $aggregation->updateHourlySummaries($day);
            $aggregation->updateDailySummaries($day);
            $at = FiscalCalendar::of($day);
            $weeks[FiscalCalendar::weekStart($day)->toDateString()] = true;
            $periods["{$at['year']}|{$at['period']}"] = [$at['year'], $at['period']];
            $quarters["{$at['year']}|{$at['quarter']}"] = [$at['year'], $at['quarter']];
            $years[$at['year']] = true;
            $this->line("Rebuilt {$date}");
            usleep($pauseMs * 1000);
        }

        foreach (array_keys($weeks) as $weekStart) {
            $start = Carbon::parse($weekStart);
            $aggregation->updateWeeklySummariesRange($start, $start->copy()->addDays(6));
        }
        foreach ($periods as [$year, $period]) {
            $aggregation->updateMonthlySummariesYearMonth($year, $period);
        }
        foreach ($quarters as [$year, $quarter]) {
            $aggregation->updateQuarterlySummariesYearQuarter($year, $quarter);
        }
        foreach (array_keys($years) as $year) {
            $aggregation->updateYearlySummariesYear($year);
        }
        $this->info('Summaries rebuilt: ' . count($weeks) . ' weeks, ' . count($periods) . ' periods, ' . count($quarters) . ' quarters, ' . count($years) . ' years.');
    }
}
