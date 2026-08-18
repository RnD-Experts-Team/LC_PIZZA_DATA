<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Models\Employee;
use App\Models\EmployeeDebrief;
use App\Models\EmployeeDebriefType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Store-scoped employee report for the business week containing a given
 * date, plus trailing-week debrief trend.
 *
 * LC Pizza's local database only holds an employee roster snapshot
 * (name/active/store, synced from the HR system) and employee debrief
 * notes — no pay/hours/status-history data lives here — so unlike a full
 * labor report, everything below is built from the roster + debriefs.
 */
class EmployeeReportService
{
    private const DEFAULT_TREND_WEEKS = 6;

    private const MAX_TREND_WEEKS = 12;

    private const UNTYPED_SLUG = 'untyped';

    private const UNTYPED_LABEL = 'Untyped (no type specified)';

    public function getEmployeeReport(string $storeId, string $date, ?int $trendWeeks = null): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();
        [$weekStart, $weekEnd] = $this->isoBusinessWeek($day);

        $trendWeeks = max(1, min(self::MAX_TREND_WEEKS, $trendWeeks ?? self::DEFAULT_TREND_WEEKS));

        $types = EmployeeDebriefType::query()->orderBy('label')->get(['id', 'slug', 'label']);

        $headcount = $this->buildHeadcount($storeId);
        $weekDebriefs = $this->loadDebriefs($storeId, $weekStart, $weekEnd);
        $debriefs = $this->buildDebriefSummary($weekDebriefs, $types);
        $trend = $this->buildTrend($storeId, $day, $trendWeeks, $types);
        $employees = $this->buildRoster($storeId, $weekDebriefs, $types);
        $summary = $this->buildSummary($headcount, $debriefs, $trend);

        return [
            'store' => $storeId,
            'date' => $day->toDateString(),
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'summary' => $summary,
            'headcount' => $headcount,
            'debrief_types' => $types->map(fn (EmployeeDebriefType $t) => $t->only(['id', 'slug', 'label']))->values()->all(),
            'debriefs' => $debriefs,
            'trend' => $trend,
            'employees' => $employees,
        ];
    }

    // -------------------------------------------------------------------------
    // Headcount (roster snapshot only — no status history available locally)
    // -------------------------------------------------------------------------

    private function buildHeadcount(string $storeId): array
    {
        $employees = Employee::query()->where('store_id', $storeId)->get(['id', 'active']);

        return [
            'active' => $employees->where('active', true)->count(),
            'inactive' => $employees->where('active', false)->count(),
            'total' => $employees->count(),
        ];
    }

    // -------------------------------------------------------------------------
    // Debriefs — this week
    // -------------------------------------------------------------------------

    private function loadDebriefs(string $storeId, CarbonImmutable $weekStart, CarbonImmutable $weekEnd): Collection
    {
        return EmployeeDebrief::query()
            ->where('store_id', $storeId)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->with(['employee', 'author', 'type'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Store-wide count per debrief type for the week. Every known type is
     * listed explicitly (0 if it wasn't used this week), plus an explicit
     * "untyped" bucket for debriefs with no type set.
     */
    private function buildDebriefSummary(Collection $weekDebriefs, Collection $types): array
    {
        $byType = $this->countsByType($weekDebriefs, $types);

        return [
            'total_count' => $weekDebriefs->count(),
            'by_type' => $byType,
            'events' => $weekDebriefs->map(fn (EmployeeDebrief $d) => [
                'debrief_id' => $d->id,
                'employee_id' => $d->employee_id,
                'employee_name' => $d->employee !== null ? $this->fullName($d->employee) : null,
                'type' => $d->type?->only(['id', 'slug', 'label']),
                'date' => $d->date->toDateString(),
                'note' => $d->note,
                'author' => $d->author?->name,
            ])->values()->all(),
        ];
    }

    /**
     * Explicit per-type counts (catalog types + an "untyped" bucket) over an
     * already-loaded collection of debriefs, sorted by count descending.
     *
     * @return array<int, array{type: array{id: ?int, slug: string, label: string}, count: int}>
     */
    private function countsByType(Collection $debriefs, Collection $types): array
    {
        $byTypeId = $debriefs->groupBy('type_id');

        $rows = $types->map(fn (EmployeeDebriefType $type) => [
            'type' => $type->only(['id', 'slug', 'label']),
            'count' => $byTypeId->get($type->id, collect())->count(),
        ]);

        $rows->push([
            'type' => ['id' => null, 'slug' => self::UNTYPED_SLUG, 'label' => self::UNTYPED_LABEL],
            'count' => $byTypeId->get(null, collect())->count(),
        ]);

        return $rows->sortByDesc('count')->values()->all();
    }

    // -------------------------------------------------------------------------
    // Trend (trailing weeks)
    // -------------------------------------------------------------------------

    private function buildTrend(string $storeId, CarbonImmutable $day, int $trendWeeks, Collection $types): array
    {
        $entries = collect();

        for ($i = $trendWeeks - 1; $i >= 0; $i--) {
            [$weekStart, $weekEnd] = $this->isoBusinessWeek($day->subWeeks($i));
            $entries->push($this->buildTrendWeek($storeId, $weekStart, $weekEnd, $types));
        }

        $averages = $this->averageTrendEntries($entries, $types);

        return [
            'weeks' => $entries->all(),
            'averages' => $averages,
        ];
    }

    private function buildTrendWeek(string $storeId, CarbonImmutable $weekStart, CarbonImmutable $weekEnd, Collection $types): array
    {
        $debriefs = $this->loadDebriefs($storeId, $weekStart, $weekEnd);

        return [
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'total_count' => $debriefs->count(),
            'by_type' => $this->countsByType($debriefs, $types),
        ];
    }

    private function averageTrendEntries(Collection $entries, Collection $types): array
    {
        $totalAvg = round($entries->avg('total_count'), 2);

        $byTypeAvg = $types->map(function (EmployeeDebriefType $type) use ($entries) {
            $counts = $entries->map(function (array $week) use ($type) {
                $row = collect($week['by_type'])->firstWhere('type.id', $type->id);

                return $row['count'] ?? 0;
            });

            return [
                'type' => $type->only(['id', 'slug', 'label']),
                'average_count' => round($counts->avg(), 2),
            ];
        })->values()->all();

        return [
            'total_count' => $totalAvg,
            'by_type' => $byTypeAvg,
        ];
    }

    // -------------------------------------------------------------------------
    // Roster — every employee at the store, with their debrief type counts
    // for the requested week made explicit.
    // -------------------------------------------------------------------------

    private function buildRoster(string $storeId, Collection $weekDebriefs, Collection $types): array
    {
        $debriefEmployeeIds = $weekDebriefs->pluck('employee_id')->filter()->unique();

        $employees = Employee::query()
            ->where('store_id', $storeId)
            ->orWhereIn('id', $debriefEmployeeIds)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'store_id', 'active']);

        $debriefsByEmployee = $weekDebriefs->groupBy('employee_id');

        return $employees->map(function (Employee $employee) use ($debriefsByEmployee, $types) {
            $ownDebriefs = $debriefsByEmployee->get($employee->id, collect());

            return [
                'employee_id' => $employee->id,
                'name' => $this->fullName($employee),
                'active' => $employee->active,
                'debriefs_this_week' => [
                    'total_count' => $ownDebriefs->count(),
                    'by_type' => $this->countsByType($ownDebriefs, $types),
                ],
            ];
        })->values()->all();
    }

    // -------------------------------------------------------------------------
    // Summary
    // -------------------------------------------------------------------------

    private function buildSummary(array $headcount, array $debriefs, array $trend): array
    {
        $topType = collect($debriefs['by_type'])->firstWhere('count', '>', 0);

        return [
            'active_employees' => $headcount['active'],
            'total_debriefs_this_week' => $debriefs['total_count'],
            'most_common_type_this_week' => $topType['type'] ?? null,
            'avg_weekly_debriefs_trailing' => $trend['averages']['total_count'] ?? null,
        ];
    }

    // -------------------------------------------------------------------------
    // Small utilities
    // -------------------------------------------------------------------------

    private function isoBusinessWeek(CarbonImmutable $date): array
    {
        $start = $date->startOfWeek(CarbonInterface::TUESDAY);

        return [$start, $start->addDays(6)];
    }

    private function fullName(Employee $employee): string
    {
        return trim(implode(' ', array_filter([
            $employee->first_name,
            $employee->middle_name,
            $employee->last_name,
        ])));
    }
}
