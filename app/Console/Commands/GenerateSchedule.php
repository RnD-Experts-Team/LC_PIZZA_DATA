<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\KeyStoreRule;
use Carbon\Carbon;
use App\Services\DataEntry\ScheduleEvaluationService;

class DailyStoreKeySchedule extends Command
{
    protected $signature = 'schedule:store-keys
                            {--from= : Start date (Y-m-d)}
                            {--to= : End date (Y-m-d)}';

    protected $description = 'Generate a daily schedule for each store showing keys and roles due';

    public function handle()
    {
        $from = Carbon::parse($this->option('from') ?? '2026-05-01')->startOfDay();
        $to = Carbon::parse($this->option('to') ?? '2026-06-30')->endOfDay();

        $service = new ScheduleEvaluationService();
        $rules = KeyStoreRule::all();

        // Get distinct store_ids
        $storeIds = $rules->pluck('store_id')->unique();

        $current = $from->copy();
        $rows = [];

        while ($current->lte($to)) {
            foreach ($storeIds as $storeId) {
                $dueRules = $rules->filter(function ($rule) use ($service, $current, $storeId) {
                    return $rule->store_id === $storeId && $service->isDueOnDate($rule, $current);
                });

                $keyIds = $dueRules->pluck('key_id')->implode(', ');
                $roles = $dueRules->pluck('role_names')
                    ->filter()
                    ->flatten()
                    ->unique()
                    ->implode(', ');

                $rows[] = [
                    'date' => $current->toDateString(),
                    'store_id' => $storeId,
                    'key_ids' => $keyIds,
                    'roles' => $roles,
                ];
            }
            $current->addDay();
        }

        // Save CSV
        $filePath = storage_path('daily_store_key_schedule.csv');
        $fp = fopen($filePath, 'w');
        fputcsv($fp, ['Date', 'Store ID', 'Key IDs', 'Roles']);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        $this->info("Daily store key schedule generated: $filePath");
    }
}