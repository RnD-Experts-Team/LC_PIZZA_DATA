<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\KeyStoreRule;
use Carbon\Carbon;
use App\Services\DataEntry\ScheduleEvaluationService;

class GenerateSchedule extends Command
{
    protected $signature = 'schedule:generate
                            {--from= : Start date in Y-m-d format}
                            {--to= : End date in Y-m-d format}';

    protected $description = 'Generate daily schedule for stores based on KeyStoreRule logic';

    public function handle()
    {
        $from = Carbon::parse($this->option('from') ?? '2026-05-01')->startOfDay();
        $to = Carbon::parse($this->option('to') ?? '2026-06-30')->endOfDay();

        $service = new ScheduleEvaluationService();

        $rules = KeyStoreRule::with('store')->get(); // assumes KeyStoreRule has a store relation

        $current = $from->copy();
        $output = [];

        while ($current->lte($to)) {
            foreach ($rules as $rule) {
                if ($service->isDueOnDate($rule, $current)) {
                    $storeName = $rule->store->name ?? 'Unknown Store';
                    $output[] = [
                        'date' => $current->toDateString(),
                        'store' => $storeName,
                        'rule_id' => $rule->id,
                        'rule_description' => $rule->description ?? null,
                    ];
                }
            }
            $current->addDay();
        }

        // Display as table in console
        $this->table(
            ['Date', 'Store', 'Rule ID', 'Description'],
            $output
        );

        // Optional: save to CSV for reporting
        $fileName = storage_path('schedule_due_may_june.csv');
        $fp = fopen($fileName, 'w');
        fputcsv($fp, ['Date', 'Store', 'Rule ID', 'Description']);
        foreach ($output as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        $this->info("Schedule CSV saved to: $fileName");
    }
}