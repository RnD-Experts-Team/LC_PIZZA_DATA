<?php

namespace App\Console\Commands\Repair;

use App\Services\Aggregation\AggregationService;
use App\Services\Database\DatabaseRouter;
use App\Support\FiscalCalendar;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Finds store-days whose order lines were imported more than once and, with
 * --apply, removes the extra copies.
 *
 * Order lines have no unique key, so a store-day imported twice at the same
 * time holds every line twice. A store-day is an exact repeat when EVERY
 * distinct line appears a multiple of the same number of times (×2, ×3 …);
 * then one copy of each is kept. A normal day (a few lines legitimately
 * repeated within an order) is never touched. Store-days that look partly
 * duplicated are only listed, to be re-imported from their source files.
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
        {--apply : Delete the extra copies (otherwise only report)}
        {--rebuild : After --apply, rebuild the summaries of the repaired days}
        {--pause-ms=250 : Pause between days, to spare the shared MySQL server}';

    protected $description = 'Find (and with --apply remove) order lines imported more than once.';

    /** Every content column of a line: identical in all of them = the same line imported again. */
    private const LINE_COLUMNS = [
        'order_id', 'item_id', 'date_time_placed', 'date_time_fulfilled', 'menu_item_name', 'menu_item_account',
        'bundle_name', 'net_amount', 'quantity', 'royalty_item', 'taxable_item', 'tax_included_amount', 'employee',
        'override_approval_employee', 'order_placed_method', 'order_fulfilled_method', 'modified_order_amount',
        'modification_reason', 'payment_methods', 'refunded',
    ];

    /** A day not an exact repeat, but with this share of its lines in identical groups, is listed for re-import. */
    private const PARTIAL_SHARE = 0.25;

    /** Fewer distinct lines than this can repeat by chance: never treated as a re-import. */
    private const MIN_DISTINCT_LINES = 10;

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

        $exact = [];
        $partial = [];
        $removed = 0;
        $repairedDates = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            [$query] = DatabaseRouter::routedQueries('order_line', $day->toMutable(), $day->toMutable());
            $table = $query->from;
            $connection = $query->getConnection();
            if ($stores !== []) {
                $query->whereIn('franchise_store', $stores);
            }

            $groups = $query
                ->selectRaw('franchise_store, COUNT(*) as copies, GROUP_CONCAT(id ORDER BY id) as ids')
                ->groupBy('franchise_store', ...self::LINE_COLUMNS)
                ->get()
                ->groupBy('franchise_store');

            foreach ($groups as $store => $lines) {
                $counts = $lines->pluck('copies')->map(fn ($c) => (int) $c)->all();
                $total = array_sum($counts);
                $times = array_reduce($counts, fn (int $g, int $c) => $this->gcd($g, $c), 0);

                if ($times >= 2 && count($counts) >= self::MIN_DISTINCT_LINES) {
                    $extra = [];
                    foreach ($lines as $line) {
                        $ids = explode(',', (string) $line->ids);
                        // Keep the first copy of each repeat, drop the rest.
                        $extra = array_merge($extra, array_slice($ids, intdiv(count($ids), $times)));
                    }
                    $exact[] = [$day->toDateString(), $store, $total, "×{$times}", count($extra)];

                    if ($apply) {
                        $connection->transaction(function () use ($connection, $table, $day, $extra): void {
                            foreach (array_chunk($extra, self::DELETE_CHUNK) as $chunk) {
                                $connection->table($table)
                                    ->where('business_date', $day->toDateString())
                                    ->whereIn('id', $chunk)
                                    ->delete();
                            }
                        });
                        $removed += count($extra);
                        $repairedDates[$day->toDateString()] = true;
                    }

                    continue;
                }

                $repeated = array_sum(array_filter($counts, fn (int $c) => $c > 1));
                if ($total > 0 && $repeated / $total >= self::PARTIAL_SHARE) {
                    $partial[] = [$day->toDateString(), $store, $total, round($repeated / $total * 100) . '%'];
                }
            }

            $connection->disconnect();
            if ($pauseMs > 0) {
                usleep($pauseMs * 1000);
            }
        }

        $this->info(count($exact) . ' store-days with every line repeated (exact re-imports)' . ($apply ? ", {$removed} extra lines removed:" : ' — nothing removed (add --apply):'));
        $this->table(['Date', 'Store', 'Lines', 'Imported', 'Extra lines'], $exact);
        $this->warn(count($partial) . ' store-days partly duplicated — re-import these from their source files:');
        $this->table(['Date', 'Store', 'Lines', 'In identical groups'], $partial);

        if ($apply && $this->option('rebuild') && $repairedDates !== []) {
            $this->rebuild($aggregation, array_keys($repairedDates), $pauseMs);
        }

        return self::SUCCESS;
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

    private function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : $this->gcd($b, $a % $b);
    }
}
