<?php

namespace Tests\Feature\Reports;

use App\Jobs\RunReportJob;
use App\Models\ReportRun;
use App\Models\ReportTemplate;
use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\DailySalesDataset;
use App\Reports\Engine\ReportFileWriter;
use App\Reports\Engine\ReportRowReader;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Concerns\AnswersReportStreams;
use Tests\Concerns\FakesAuthServer;

/**
 * Runs end to end: asking for one, RunReportJob against a faked dataset
 * service streaming its rows, the files it writes, the rows paged back out of
 * the CSV, the downloads, the signed file route, liveness and the prune.
 */
class ReportRunsTest extends TestCase
{
    use AnswersReportStreams, FakesAuthServer;

    private const SALES = [
        ['store' => '03795-00001', 'date' => '2026-10-21', 'net_sales' => 100.5],
        ['store' => '03795-00002', 'date' => '2026-10-21', 'net_sales' => -20],
        // A cell a spreadsheet would run as a formula.
        ['store' => '=HYPERLINK("http://evil.test")', 'date' => '2026-10-22', 'net_sales' => 7],
    ];

    /** Answers the daily sales query in place of the fixture one, when a test sets it. */
    private ?Closure $salesAnswer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('image_path')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('user_store_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('store_id')->nullable();
            $table->string('role_name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        (require base_path('database/migrations/2026_11_02_000001_create_report_templates_table.php'))->up();
        (require base_path('database/migrations/2026_11_02_000002_create_report_runs_table.php'))->up();
        (require base_path('database/migrations/2026_11_02_000003_create_report_schedules_table.php'))->up();

        config(['services.report_datasets.urls' => ['data' => 'http://data.test/api']]);
        Storage::fake('local');
        $this->fakeAuthServer();

        Http::fake([
            'data.test/api/report-datasets' => Http::response([
                'service' => 'Data',
                'contract_version' => 1,
                'contract_sha1' => ReportDataset::contractSha1(),
                'datasets' => [(new DailySalesDataset())->toArray()],
            ]),
            'data.test/api/report-datasets/data.daily_sales/query' => fn (Request $request) => $this->salesAnswer !== null
                ? ($this->salesAnswer)($request)
                : $this->answer($request),
        ]);
    }

    /** The contract's stream-mode answer from the fixture: group keys, then aggregates by alias. */
    private function answer(Request $request)
    {
        return $this->ndjson($this->keysOf($request), array_map(fn (array $row) => [
            ...array_map(fn (string $key) => $row[$key], $request['group_by']),
            ...array_map(fn (array $aggregate) => $row[$aggregate['column']] ?? null, $request['aggregates']),
        ], self::SALES));
    }

    /** Every sheet of an XLSX, each a list of its rows. */
    private function sheets(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $sheets[] = array_map(fn (Row $row) => $row, iterator_to_array($sheet->getRowIterator(), false));
        }
        $reader->close();

        return $sheets;
    }

    private function template(array $definition = [], int $owner = 9, string $visibility = 'private'): ReportTemplate
    {
        return ReportTemplate::query()->create([
            'owner_user_id' => $owner,
            'name' => 'Weekly Sales',
            'visibility' => $visibility,
            'definition' => array_replace([
                'v' => 1,
                'datasets' => [['alias' => 's', 'key' => 'data.daily_sales']],
                'grain' => ['store', 'date'],
                'columns' => [
                    ['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum', 'label' => 'Net sales'],
                    ['id' => 'c2', 'kind' => 'formula', 'expr' => 'c1 * 2', 'label' => 'Double', 'format' => 'money'],
                ],
                'sort' => [['id' => 'c1', 'dir' => 'desc']],
                'period' => ['type' => 'fixed', 'from' => '2026-10-21', 'to' => '2026-10-22'],
                'stores' => ['mode' => 'all'],
            ], $definition),
        ]);
    }

    /** Asks for a run over HTTP and returns it with the job that was queued for it. */
    private function ask(ReportTemplate $template, string $format = 'csv'): array
    {
        Queue::fake();
        $id = $this->postJson('/api/report-builder/run', ['template_id' => $template->id, 'format' => $format], $this->headers())
            ->assertCreated()
            ->json('id');

        $job = null;
        Queue::assertPushed(RunReportJob::class, function (RunReportJob $pushed) use ($id, &$job) {
            $job = $pushed;

            return $pushed->runId === $id;
        });

        return [ReportRun::query()->findOrFail($id), $job];
    }

    private function execute(RunReportJob $job): ReportRun
    {
        app()->call([$job, 'handle']);

        return ReportRun::query()->findOrFail($job->runId);
    }

    /** A finished run with $count rows written by the real writer. */
    private function finished(int $count, array $overrides = []): ReportRun
    {
        $columns = [
            ['id' => 'store', 'label' => 'Store', 'type' => 'string', 'format' => null],
            ['id' => 'c1', 'label' => 'Net sales', 'type' => 'money', 'format' => null],
        ];
        $run = ReportRun::query()->create([
            'template_id' => $this->template()->id,
            'requested_by_user_id' => 9,
            'definition' => [],
            'period_from' => '2026-10-21',
            'period_to' => '2026-10-27',
            'format' => 'csv',
            'status' => 'done',
            'row_count' => $count,
            'meta' => ['columns' => $columns, 'truncated' => false, 'skipped_stores' => []],
            'expires_at' => now()->addDays(7),
            ...$overrides,
        ]);
        $files = (new ReportFileWriter())->write($run->id, $columns, array_map(fn (int $i) => [sprintf('03795-%05d', $i), $i], $count > 0 ? range(1, $count) : []), false);
        $run->update(['csv_path' => $files['csv_path'], 'file_size' => $files['csv_size']]);

        return $run;
    }

    // ── Asking for a run ────────────────────────────────────────────────────────────────

    public function test_a_run_is_queued_on_the_reports_queue_with_its_period_fixed_now(): void
    {
        $template = $this->template(['period' => ['type' => 'relative', 'token' => 'yesterday']]);
        // 09:00 in New York, where the period is read.
        $this->travelTo(now()->setDate(2026, 10, 28)->setTime(13, 0));

        Queue::fake();
        $response = $this->postJson('/api/report-builder/run', ['template_id' => $template->id, 'format' => 'xlsx'], $this->headers())->assertCreated();

        $this->assertEqualsCanonicalizing([
            'id', 'template_id', 'schedule_id', 'requested_by_user_id', 'definition', 'period_from', 'period_to', 'format',
            'status', 'row_count', 'file_size', 'error', 'meta', 'started_at', 'heartbeat_at', 'finished_at', 'expires_at', 'created_at', 'updated_at',
        ], array_keys($response->json()));
        $this->assertSame(26, strlen($response->json('id')));
        $this->assertSame('queued', $response->json('status'));
        $this->assertSame('xlsx', $response->json('format'));
        $this->assertSame(9, $response->json('requested_by_user_id'));
        $this->assertSame(['2026-10-27', '2026-10-27'], [$response->json('period_from'), $response->json('period_to')]);
        $this->assertSame($template->definition, $response->json('definition'));
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, ReportRun::query()->first()->expires_at->timestamp, 1);

        Queue::assertPushed(RunReportJob::class, fn (RunReportJob $job) => $job->connection === 'reports'
            && $job->queue === 'reports'
            && $job->runId === $response->json('id')
            // The token is in the payload, encrypted only.
            && ! str_contains(serialize($job), 'test-token'));
    }

    public function test_a_second_run_while_one_is_active_is_a_409(): void
    {
        $template = $this->template();
        [$run] = $this->ask($template);

        $this->postJson('/api/report-builder/run', ['template_id' => $template->id, 'format' => 'csv'], $this->headers())
            ->assertStatus(409);

        $run->update(['status' => 'running']);
        $this->postJson('/api/report-builder/run', ['template_id' => $template->id, 'format' => 'csv'], $this->headers())
            ->assertStatus(409);

        $run->update(['status' => 'done']);
        $this->postJson('/api/report-builder/run', ['template_id' => $template->id, 'format' => 'csv'], $this->headers())
            ->assertCreated();
        $this->assertSame(2, ReportRun::query()->count());
    }

    public function test_only_a_template_the_caller_can_read_is_run(): void
    {
        Queue::fake();
        $private = $this->template(owner: 77);
        $shared = $this->template(owner: 77, visibility: 'org');

        $this->postJson('/api/report-builder/run', ['template_id' => $private->id, 'format' => 'csv'], $this->headers())->assertNotFound();
        $this->postJson('/api/report-builder/run', ['template_id' => $shared->id, 'format' => 'pdf'], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('format');
        $this->postJson('/api/report-builder/run', ['template_id' => $shared->id, 'format' => 'csv'], $this->headers())->assertCreated();
    }

    public function test_run_creation_is_throttled_as_an_export(): void
    {
        $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create('/api/report-builder/run', 'POST'));

        $this->assertContains('throttle:api-export', $route->gatherMiddleware());
        $this->assertContains('auth.token.store', $route->gatherMiddleware());
    }

    // ── The job ─────────────────────────────────────────────────────────────────────────

    public function test_the_job_writes_the_csv_and_finishes_the_run(): void
    {
        [$run, $job] = $this->ask($this->template());

        $run = $this->execute($job);

        $this->assertSame('done', $run->status);
        $this->assertNull($run->error);
        $this->assertSame(3, $run->row_count);
        $this->assertSame('reports/'.$run->id.'.csv', $run->csv_path);
        $this->assertNull($run->xlsx_path);
        $this->assertSame(Storage::disk('local')->size($run->csv_path), $run->file_size);
        $this->assertFalse($run->meta['truncated']);
        $this->assertSame([], $run->meta['skipped_stores']);
        $this->assertSame(['store', 'date', 'c1', 'c2'], array_column($run->meta['columns'], 'id'));
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->heartbeat_at);
        $this->assertNotNull($run->finished_at);
        // The staging database is gone; the index is beside the CSV.
        $this->assertSame([], Storage::disk('local')->files('reports/tmp'));
        Storage::disk('local')->assertExists("reports/{$run->id}.csv.idx");

        // Header = column labels; sorted by net sales desc; the formula cell is computed.
        $this->assertSame(
            "Store,\"Business date\",\"Net sales\",Double\n03795-00001,2026-10-21,100.5,201\n\"'=HYPERLINK(\"\"http://evil.test\"\")\",2026-10-22,7,14\n03795-00002,2026-10-21,-20,-40\n",
            Storage::disk('local')->get($run->csv_path),
        );
        // The bearer went to the dataset service, which got the run's fixed period.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), 'data.daily_sales/query')
            && $request->hasHeader('Authorization', 'Bearer 1|test-token')
            && $request['stream'] === true && ! isset($request['limit']) && ! isset($request['cursor'])
            && $request['date_from'] === '2026-10-21' && $request['date_to'] === '2026-10-22');
    }

    public function test_the_job_writes_an_xlsx_too_when_asked_with_every_cell_typed(): void
    {
        [$run, $job] = $this->ask($this->template(), 'xlsx');

        $run = $this->execute($job);

        $this->assertSame('done', $run->status);
        Storage::disk('local')->assertExists($run->csv_path);
        $this->assertSame('reports/'.$run->id.'.xlsx', $run->xlsx_path);
        $this->assertSame(Storage::disk('local')->size($run->xlsx_path), $run->file_size);

        $sheets = $this->sheets(Storage::disk('local')->path($run->xlsx_path));
        $this->assertCount(1, $sheets);
        $this->assertEquals([
            ['Store', 'Business date', 'Net sales', 'Double'],
            ['03795-00001', '2026-10-21', 100.5, 201],
            ['=HYPERLINK("http://evil.test")', '2026-10-22', 7, 14],
            ['03795-00002', '2026-10-21', -20, -40],
        ], array_map(fn (Row $row) => $row->toArray(), $sheets[0]));
        // In the file itself: text as an inline string, never a formula; numbers as numbers.
        $zip = new \ZipArchive();
        $zip->open(Storage::disk('local')->path($run->xlsx_path));
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertMatchesRegularExpression('/<c r="A3"[^>]* t="inlineStr"><is><t>=HYPERLINK/', $xml);
        $this->assertMatchesRegularExpression('/<c r="C2"[^>]*><v>100.5<\/v>/', $xml);
        $this->assertInstanceOf(NumericCell::class, $sheets[0][1]->cells[2]);
        $this->assertInstanceOf(StringCell::class, $sheets[0][1]->cells[0]);
    }

    public function test_the_writer_beats_just_before_and_after_it_closes_the_xlsx(): void
    {
        $path = Storage::disk('local')->path(ReportFileWriter::paths('beats')['xlsx']);
        // The XLSX's size at each beat: still unwritten before close(), whole after it.
        $sizes = [];
        $beat = function () use ($path, &$sizes) {
            clearstatcache();
            $sizes[] = is_file($path) ? filesize($path) : 0;
        };

        $files = (new ReportFileWriter())->write('beats', [['id' => 'a', 'label' => 'N', 'type' => 'integer']], [[1], [2]], true, $beat);

        $this->assertCount(2, $sizes);
        $this->assertLessThan($files['xlsx_size'], $sizes[0]);
        $this->assertSame($files['xlsx_size'], $sizes[1]);

        // No XLSX, nothing to close: no beat.
        $sizes = [];
        (new ReportFileWriter())->write('quiet', [['id' => 'a', 'label' => 'N', 'type' => 'integer']], [[1]], false, $beat);
        $this->assertSame([], $sizes);
    }

    public function test_an_xlsx_starts_a_new_sheet_with_the_header_again_when_one_is_full_and_cuts_overlong_text(): void
    {
        $writer = new class extends ReportFileWriter
        {
            protected const SHEET_ROWS = 3;
        };
        $rows = (function () {
            foreach (range(1, 7) as $i) {
                yield [$i, $i === 7 ? str_repeat('x', 40000) : "r{$i}"];
            }
        })();

        $files = $writer->write('roll', [['id' => 'a', 'label' => 'N', 'type' => 'integer'], ['id' => 'b', 'label' => 'Text', 'type' => 'string']], $rows, true);

        $this->assertSame(7, $files['row_count']);
        $sheets = array_map(fn (array $sheet) => array_map(fn (Row $row) => $row->toArray(), $sheet), $this->sheets(Storage::disk('local')->path($files['xlsx_path'])));
        $this->assertEquals([
            [['N', 'Text'], [1, 'r1'], [2, 'r2'], [3, 'r3']],
            [['N', 'Text'], [4, 'r4'], [5, 'r5'], [6, 'r6']],
            [['N', 'Text'], [7, str_repeat('x', 32767)]],
        ], $sheets);
        // Excel's own most: 1,048,576 rows a sheet, the header among them.
        $this->assertSame(1048575, (new \ReflectionClassConstant(ReportFileWriter::class, 'SHEET_ROWS'))->getValue());
        // The CSV keeps the whole text.
        $this->assertStringContainsString(str_repeat('x', 40000), Storage::disk('local')->get($files['csv_path']));
    }

    public function test_a_run_cut_at_its_limit_says_it_was_truncated(): void
    {
        [$run, $job] = $this->ask($this->template(['limit' => 2]));

        $run = $this->execute($job);

        $this->assertSame(2, $run->row_count);
        $this->assertTrue($run->meta['truncated']);
    }

    public function test_a_failing_run_records_a_fixed_message_and_leaves_no_files(): void
    {
        $this->salesAnswer = fn () => Http::response(['message' => 'SQLSTATE[HY000] secret internals'], 500);
        [$run, $job] = $this->ask($this->template(), 'xlsx');
        // Left over by an earlier attempt that died half-way.
        Storage::disk('local')->put("reports/{$run->id}.csv", 'partial');

        $run = $this->execute($job);

        $this->assertSame('failed', $run->status);
        $this->assertSame('data (data.daily_sales) could not answer.', $run->error);
        $this->assertNull($run->csv_path);
        $this->assertNull($run->row_count);
        $this->assertNotNull($run->finished_at);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_an_unexpected_error_is_not_shown_to_the_user(): void
    {
        $this->salesAnswer = fn () => throw new \RuntimeException('secret internals at /var/www/html/app.php:12');
        [$run, $job] = $this->ask($this->template());

        $run = $this->execute($job);

        $this->assertSame('failed', $run->status);
        $this->assertSame('The report could not be produced.', $run->error);
    }

    private function salesRequests(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), 'data.daily_sales/query'))
            ->map(fn (array $pair) => $pair[0])->all();
    }

    public function test_a_run_of_120000_rows_across_two_datasets_streams_through_in_constant_memory(): void
    {
        // s holds stores 1..80000, t stores 40001..120000: the outer merge has both gaps.
        $this->salesAnswer = function (Request $request) {
            [$from, $to] = $request['aggregates'][0]['alias'] === 'c1' ? [1, 80000] : [40001, 120000];

            return $this->ndjson($this->keysOf($request), (function () use ($from, $to) {
                for ($i = $from; $i <= $to; $i++) {
                    yield [sprintf('S%06d', $i), $i + 0.5];
                }
            })());
        };
        [$run, $job] = $this->ask($this->template([
            'datasets' => [['alias' => 's', 'key' => 'data.daily_sales'], ['alias' => 't', 'key' => 'data.daily_sales']],
            'grain' => ['store'],
            'columns' => [
                ['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum', 'label' => 'S'],
                ['id' => 'c2', 'alias' => 't', 'column' => 'net_sales', 'agg' => 'sum', 'label' => 'T'],
            ],
            'sort' => [],
        ]));

        $before = memory_get_usage();
        memory_reset_peak_usage();
        $run = $this->execute($job);
        $growth = memory_get_peak_usage() - $before;

        $this->assertSame('done', $run->status, (string) $run->error);
        $this->assertSame(120000, $run->row_count);
        $this->assertFalse($run->meta['truncated']);
        $this->assertLessThan(64 * 1024 * 1024, $growth, 'Peak memory grew by '.round($growth / 1048576, 1).' MB.');
        $this->assertSame([], Storage::disk('local')->files('reports/tmp'));

        // Paged deep into the CSV through its index, gaps where a dataset has no row.
        $read = fn (int $offset, int $count) => (new ReportRowReader())->read(Storage::disk('local')->path($run->csv_path), $run->meta['columns'], $offset, $count);
        $this->assertSame([['S000001', 1.5, null], ['S000002', 2.5, null]], $read(0, 2));
        $this->assertSame([['S040000', 40000.5, null], ['S040001', 40001.5, 40001.5]], $read(39999, 2));
        $this->assertSame([['S080000', 80000.5, 80000.5], ['S080001', null, 80001.5]], $read(79999, 2));
        $this->assertSame([['S120000', null, 120000.5]], $read(119999, 5));
        $this->assertSame([], $read(120000, 5));

        $this->getJson("/api/report-builder/runs/{$run->id}/rows?page=2400&per=50", $this->headers())
            ->assertOk()
            ->assertJsonCount(50, 'rows')
            ->assertJsonPath('rows.0', ['S119951', null, 119951.5])
            ->assertJsonPath('page', ['current_page' => 2400, 'per_page' => 50, 'total' => 120000, 'last_page' => 2400]);
        // One streamed request per dataset, no paging.
        $this->assertCount(2, $this->salesRequests());
    }

    public function test_a_run_has_no_cap_on_period_datasets_columns_formulas_or_mine_stores(): void
    {
        DB::table('user_store_roles')->insert(array_map(
            fn (int $i) => ['id' => $i, 'user_id' => 9, 'store_id' => sprintf('03795-%05d', $i), 'role_name' => 'Store Manager', 'active' => true],
            range(1, 40),
        ));
        // Every store together is refused; one at a time, every fourth store is.
        $this->salesAnswer = function (Request $request) {
            if (count($request['stores']) > 1) {
                return Http::response(['message' => 'Forbidden'], 403);
            }
            $n = (int) substr($request['stores'][0], -5);
            if ($n % 4 === 0) {
                return Http::response(['message' => 'Forbidden'], 403);
            }

            return $this->ndjson($this->keysOf($request), [[$request['stores'][0], '2024-06-01', ...array_fill(0, count($request['aggregates']), $n)]]);
        };

        $columns = [];
        foreach (range(1, 60) as $i) {
            $columns[] = ['id' => "c{$i}", 'alias' => 's'.(($i - 1) % 6 + 1), 'column' => 'net_sales', 'agg' => 'sum'];
        }
        foreach (range(61, 90) as $i) {
            $columns[] = ['id' => "c{$i}", 'kind' => 'formula', 'expr' => 'c'.($i - 1).' + 1'];
        }
        [$run, $job] = $this->ask($this->template([
            'datasets' => array_map(fn (int $i) => ['alias' => "s{$i}", 'key' => 'data.daily_sales'], range(1, 6)),
            'columns' => $columns,
            'sort' => [],
            'period' => ['type' => 'fixed', 'from' => '2023-11-01', 'to' => '2026-10-31'],
            'stores' => ['mode' => 'mine'],
        ]));

        $run = $this->execute($job);

        $this->assertSame('done', $run->status, (string) $run->error);
        $this->assertSame(30, $run->row_count);
        $this->assertCount(92, $run->meta['columns']);
        $skipped = array_map(fn (int $i) => sprintf('03795-%05d', $i), range(4, 40, 4));
        $this->assertSame(array_fill_keys(array_map(fn (int $i) => "s{$i}", range(1, 6)), $skipped), $run->meta['skipped_stores']);

        $rows = (new ReportRowReader())->read(Storage::disk('local')->path($run->csv_path), $run->meta['columns'], 0, 50);
        $this->assertSame(['03795-00001', '2024-06-01', ...array_fill(0, 60, 1), ...range(2, 31)], $rows[0]);
        $this->assertSame('03795-00039', end($rows)[0]);

        // Each dataset: once with every store, then once per store, five at a time.
        $requests = array_values($this->salesRequests());
        $this->assertCount(6 * 41, $requests);
        $this->assertSame(['2023-11-01', '2026-10-31'], [$requests[0]['date_from'], $requests[0]['date_to']]);
    }

    public function test_a_stream_that_ends_without_its_done_line_fails_the_run(): void
    {
        $this->salesAnswer = fn (Request $request) => $this->ndjson($this->keysOf($request), [['03795-00001', '2026-10-21', 1]], '');
        [$run, $job] = $this->ask($this->template(), 'xlsx');

        $run = $this->execute($job);

        $this->assertSame('failed', $run->status);
        $this->assertSame('data (data.daily_sales) stopped before it sent every row.', $run->error);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_stream_that_reports_an_error_part_way_fails_the_run(): void
    {
        $this->salesAnswer = fn (Request $request) => $this->ndjson($this->keysOf($request), [['03795-00001', '2026-10-21', 1]], '{"error":"The dataset query failed before it sent every row."}'."\n");
        [$run, $job] = $this->ask($this->template());

        $run = $this->execute($job);

        $this->assertSame('failed', $run->status);
        $this->assertSame('data (data.daily_sales) failed part-way through its rows.', $run->error);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_running_run_without_a_heartbeat_for_30_minutes_is_failed_and_its_files_removed(): void
    {
        $template = $this->template();
        $make = fn (array $values) => ReportRun::query()->create([
            'template_id' => $template->id, 'requested_by_user_id' => 9, 'definition' => [],
            'period_from' => '2026-10-21', 'period_to' => '2026-10-21', 'format' => 'csv',
            'status' => 'running', 'expires_at' => now()->addDays(7), ...$values,
        ]);
        $quiet = $make(['started_at' => now()->subHours(3), 'heartbeat_at' => now()->subMinutes(31)]);
        $neverBeat = $make(['started_at' => now()->subMinutes(31)]);
        $alive = $make(['started_at' => now()->subHours(3), 'heartbeat_at' => now()->subMinutes(5)]);
        foreach ([$quiet, $neverBeat, $alive] as $run) {
            Storage::disk('local')->put("reports/{$run->id}.csv", 'partial');
            Storage::disk('local')->put("reports/tmp/{$run->id}.sqlite", 'staging');
        }

        Artisan::call('reports:dispatch-due');

        $this->assertSame('', Artisan::output());
        foreach ([$quiet, $neverBeat] as $run) {
            $this->assertSame('failed', $run->fresh()->status);
            $this->assertSame('The report stopped before it finished.', $run->fresh()->error);
            Storage::disk('local')->assertMissing("reports/{$run->id}.csv");
            Storage::disk('local')->assertMissing("reports/tmp/{$run->id}.sqlite");
        }
        $this->assertSame('running', $alive->fresh()->status);
        Storage::disk('local')->assertExists("reports/tmp/{$alive->id}.sqlite");
    }

    public function test_the_job_has_no_timeout_and_the_reports_queue_never_hands_a_running_job_out_again(): void
    {
        $this->assertSame(0, (new RunReportJob('x', 'token'))->timeout);
        $this->assertSame(604800, config('queue.connections.reports.retry_after'));
        $this->assertStringContainsString('queue:work reports --queue=reports --sleep=3 --tries=1 --timeout=0 --memory=1024', (string) file_get_contents(base_path('supervisord-worker.conf')));
    }

    public function test_a_job_killed_by_its_timeout_fails_the_run_and_removes_its_files(): void
    {
        [$run, $job] = $this->ask($this->template());
        $run->update(['status' => 'running']);
        Storage::disk('local')->put("reports/{$run->id}.csv", 'partial');
        Storage::disk('local')->put("reports/tmp/{$run->id}.sqlite", 'staging');

        $job->failed(new \RuntimeException('timed out'));

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame('The report stopped before it finished.', $run->error);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_failure_is_recorded_once_and_never_overwrites_a_finished_run(): void
    {
        [$run, $job] = $this->ask($this->template());
        $run->update(['status' => 'running']);
        Storage::disk('local')->put("reports/{$run->id}.csv", 'partial');

        $job->failed(new \RuntimeException('timed out'));
        $first = $run->fresh();
        $this->travel(5)->minutes();
        $job->failed(new \RuntimeException('again'));

        $this->assertEquals($first->finished_at, $run->fresh()->finished_at);
        $this->assertSame('failed', $run->fresh()->status);

        // A run that finished keeps its status and its files.
        $done = $this->finished(2);
        $doneJob = new RunReportJob($done->id, 'token');
        $doneJob->failed(new \RuntimeException('late'));
        $this->assertSame('done', $done->fresh()->status);
        Storage::disk('local')->assertExists($done->csv_path);
    }

    public function test_a_job_that_dies_past_retry_after_fails_the_run_once_through_the_real_worker(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        config(['queue.connections.reports.connection' => null, 'queue.failed.driver' => 'database-uuids', 'queue.failed.database' => config('database.default')]);

        $template = $this->template();
        $id = $this->postJson('/api/report-builder/run', ['template_id' => $template->id, 'format' => 'csv'], $this->headers())->assertCreated()->json('id');
        $this->assertSame(1, DB::table('jobs')->where('queue', 'reports')->count());
        // The payload holds the token encrypted only.
        $this->assertStringNotContainsString('test-token', (string) DB::table('jobs')->value('payload'));

        // A worker took it, started it and died: reserved long ago, run still marked running.
        ReportRun::query()->whereKey($id)->update(['status' => 'running']);
        Storage::disk('local')->put("reports/{$id}.csv", 'partial');
        DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->subSeconds(604800 + 60)->timestamp]);

        Artisan::call('queue:work', ['connection' => 'reports', '--queue' => 'reports', '--once' => true, '--tries' => 1]);

        $run = ReportRun::query()->findOrFail($id);
        $this->assertSame('failed', $run->status);
        $this->assertSame('The report stopped before it finished.', $run->error);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        // Not a word of the token in the run row.
        $this->assertStringNotContainsString('test-token', json_encode($run->toArray()));

        // Nothing is left to hand out again.
        Artisan::call('queue:work', ['connection' => 'reports', '--queue' => 'reports', '--once' => true, '--tries' => 1]);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_run_that_is_no_longer_queued_is_ignored_by_the_job(): void
    {
        foreach (['running', 'failed', 'done'] as $status) {
            [$run, $job] = $this->ask($this->template());
            $run->update(['status' => $status, 'error' => 'kept']);

            $this->execute($job);

            $this->assertSame($status, $run->fresh()->status);
            $this->assertSame('kept', $run->fresh()->error);
            $run->delete();
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'report-datasets'));
    }

    public function test_a_job_handed_out_again_does_not_run_twice(): void
    {
        [$run, $job] = $this->ask($this->template());
        $this->execute($job);
        $this->assertSame('done', $run->refresh()->status);

        $this->execute($job);

        Http::assertSentCount(1 + 1 + 1); // auth, catalog, the one stream of one run
    }

    // ── Rows ────────────────────────────────────────────────────────────────────────────

    public function test_rows_are_paged_out_of_the_csv(): void
    {
        $run = $this->finished(60);

        $response = $this->getJson("/api/report-builder/runs/{$run->id}/rows?page=3&per=25", $this->headers())->assertOk();

        $this->assertSame([
            ['id' => 'store', 'label' => 'Store', 'type' => 'string', 'format' => null],
            ['id' => 'c1', 'label' => 'Net sales', 'type' => 'money', 'format' => null],
        ], $response->json('columns'));
        $this->assertSame(array_map(fn (int $i) => [sprintf('03795-%05d', $i), $i], range(51, 60)), $response->json('rows'));
        $this->assertSame(['current_page' => 3, 'per_page' => 25, 'total' => 60, 'last_page' => 3], $response->json('page'));
        $this->assertSame(['row_count' => 60, 'truncated' => false, 'skipped_stores' => []], $response->json('meta'));

        $this->getJson("/api/report-builder/runs/{$run->id}/rows", $this->headers())
            ->assertOk()
            ->assertJsonCount(50, 'rows')
            ->assertJsonPath('rows.0', ['03795-00001', 1])
            ->assertJsonPath('page', ['current_page' => 1, 'per_page' => 50, 'total' => 60, 'last_page' => 2]);
        $this->getJson("/api/report-builder/runs/{$run->id}/rows?page=4&per=25", $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('page', ['current_page' => 4, 'per_page' => 25, 'total' => 60, 'last_page' => 3]);
    }

    public function test_an_empty_report_has_one_page_and_zero_rows(): void
    {
        $run = $this->finished(0);

        $this->getJson("/api/report-builder/runs/{$run->id}/rows", $this->headers())
            ->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('page', ['current_page' => 1, 'per_page' => 50, 'total' => 0, 'last_page' => 1]);
    }

    public function test_rows_are_matched_to_the_stored_columns_by_position_not_by_the_csv_header(): void
    {
        $run = $this->finished(3);
        // Even a header that names another column's id changes nothing.
        file_put_contents(Storage::disk('local')->path($run->csv_path), "c1,store\n03795-00001,5\n");
        file_put_contents(Storage::disk('local')->path($run->csv_path).'.idx', pack('J', 9));

        $response = $this->getJson("/api/report-builder/runs/{$run->id}/rows?per=25", $this->headers())->assertOk();

        $this->assertSame(['store', 'c1'], array_column($response->json('columns'), 'id'));
        $this->assertSame([['03795-00001', 5]], $response->json('rows'));
    }

    public function test_rows_per_page_are_25_to_200(): void
    {
        $run = $this->finished(5);

        foreach (['per=24', 'per=201', 'page=0', 'per=abc'] as $query) {
            $this->getJson("/api/report-builder/runs/{$run->id}/rows?{$query}", $this->headers())->assertUnprocessable();
        }
        $this->getJson("/api/report-builder/runs/{$run->id}/rows?per=200", $this->headers())->assertOk()->assertJsonCount(5, 'rows');
    }

    public function test_rows_of_someone_elses_run_an_expired_run_or_an_unfinished_one_are_refused(): void
    {
        $theirs = $this->finished(5, ['requested_by_user_id' => 77]);
        $expired = $this->finished(5, ['expires_at' => now()->subMinute()]);
        $queued = $this->finished(5, ['status' => 'queued']);

        $this->getJson("/api/report-builder/runs/{$theirs->id}/rows", $this->headers())->assertNotFound();
        $this->getJson("/api/report-builder/runs/{$theirs->id}", $this->headers())->assertNotFound();
        $this->getJson('/api/report-builder/runs/01HZZZZZZZZZZZZZZZZZZZZZZZ/rows', $this->headers())->assertNotFound();
        $this->getJson("/api/report-builder/runs/{$expired->id}/rows", $this->headers())->assertStatus(410);
        $this->getJson("/api/report-builder/runs/{$queued->id}/rows", $this->headers())->assertStatus(409);
    }

    public function test_a_label_a_spreadsheet_would_run_as_a_formula_is_prefixed_in_the_header_too(): void
    {
        $files = (new ReportFileWriter())->write('labels', [['id' => 'a', 'label' => '=1+1', 'type' => 'string'], ['id' => 'b', 'label' => 'Net, "sales"', 'type' => 'string']], [['x', 'y']], false);

        $this->assertSame("'=1+1,\"Net, \"\"sales\"\"\"\nx,y\n", Storage::disk('local')->get($files['csv_path']));
    }

    public function test_the_injection_prefix_is_written_to_the_csv_and_taken_off_when_reading(): void
    {
        $columns = [
            ['id' => 'a', 'label' => 'A', 'type' => 'string'],
            ['id' => 'b', 'label' => 'B', 'type' => 'string'],
            ['id' => 'c', 'label' => 'C', 'type' => 'string'],
            ['id' => 'd', 'label' => 'D', 'type' => 'string'],
            ['id' => 'e', 'label' => 'E', 'type' => 'number'],
            ['id' => 'f', 'label' => 'F', 'type' => 'string'],
            ['id' => 'g', 'label' => 'G', 'type' => 'boolean'],
            ['id' => 'h', 'label' => 'H', 'type' => 'number'],
        ];
        $row = ['=1+2', '+cmd', '-cmd', '@SUM(A1)', '-5.5', "two\nlines", false, null];

        $files = (new ReportFileWriter())->write('inject', $columns, [$row], false);

        $this->assertSame(
            "A,B,C,D,E,F,G,H\n'=1+2,'+cmd,'-cmd,'@SUM(A1),-5.5,\"two\nlines\",0,\n",
            Storage::disk('local')->get($files['csv_path']),
        );
        $read = (new ReportRowReader())->read(Storage::disk('local')->path($files['csv_path']), $columns, 0, 10);
        $this->assertSame([['=1+2', '+cmd', '-cmd', '@SUM(A1)', -5.5, "two\nlines", false, null]], $read);
    }

    // ── Downloads and the signed file route ─────────────────────────────────────────────

    public function test_a_download_is_the_runs_file_and_a_missing_xlsx_is_a_404(): void
    {
        $run = $this->finished(2);

        $response = $this->get("/api/report-builder/runs/{$run->id}/download", $this->headers())->assertOk();
        $this->assertSame('attachment; filename=weekly-sales-2026-10-21-2026-10-27.csv', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertSame(Storage::disk('local')->get($run->csv_path), $response->streamedContent());

        $this->getJson("/api/report-builder/runs/{$run->id}/download?format=xlsx", $this->headers())
            ->assertNotFound()
            ->assertJsonPath('message', 'This report has no XLSX file.');
        $this->getJson("/api/report-builder/runs/{$run->id}/download?format=pdf", $this->headers())->assertUnprocessable();
    }

    public function test_a_download_of_someone_elses_run_is_a_404_even_for_an_org_template(): void
    {
        $theirs = $this->finished(2, ['requested_by_user_id' => 77, 'template_id' => $this->template(owner: 77, visibility: 'org')->id]);

        $this->get("/api/report-builder/runs/{$theirs->id}/download", $this->headers())->assertNotFound();
    }

    public function test_the_download_name_cannot_hold_a_path_separator_or_a_control_character(): void
    {
        foreach (['../../etc/passwd', "a\\b/c", "x\r\nSet-Cookie: a=b", "\x00\x1f\x7f.csv", 'تقرير', "..\\..\\", '/'] as $name) {
            $template = $this->template();
            $template->update(['name' => $name]);
            $run = $this->finished(1, ['template_id' => $template->id]);

            $disposition = $this->get("/api/report-builder/runs/{$run->id}/download", $this->headers())->assertOk()->headers->get('Content-Disposition');

            $this->assertMatchesRegularExpression('/^attachment; filename=[a-z0-9-]+-2026-10-21-2026-10-27\.csv$/', $disposition, $name);
        }
    }

    public function test_the_report_builder_routes_carry_only_throttle_and_authentication_middleware(): void
    {
        $allowed = fn ($middleware) => is_string($middleware)
            && ($middleware === 'api' || $middleware === 'auth.token.store' || $middleware === 'signed:relative' || str_starts_with($middleware, 'throttle:'));
        $checked = 0;
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/report-builder')) {
                continue;
            }
            $checked++;
            $this->assertSame([], array_values(array_filter($route->middleware(), fn ($m) => ! $allowed($m))), $route->uri());
        }
        $this->assertSame(18, $checked);
    }

    public function test_the_file_route_takes_a_signed_url_only(): void
    {
        $run = $this->finished(2);
        $signed = fn () => URL::temporarySignedRoute('report-builder.files', now()->addDay(), ['run' => $run->id], absolute: false);

        // A relative URL: the path and the query, no host to disagree with a proxy.
        $this->assertStringStartsWith("/api/report-builder/files/{$run->id}?expires=", $signed());

        $this->getJson("/api/report-builder/files/{$run->id}")->assertForbidden();
        $this->getJson($signed().'x')->assertForbidden();
        $this->getJson(str_replace($run->id, $this->finished(1)->id, $signed()))->assertForbidden();

        $response = $this->get($signed())->assertOk();
        $this->assertSame(Storage::disk('local')->get($run->csv_path), $response->streamedContent());
        // No token was asked for.
        Http::assertNothingSent();

        // The signature's own 24 hours.
        $link = $signed();
        $this->travel(25)->hours();
        $this->getJson($link)->assertForbidden();
    }

    public function test_the_signature_holds_behind_a_proxy_that_changes_the_host_and_scheme(): void
    {
        $run = $this->finished(2);
        $path = URL::temporarySignedRoute('report-builder.files', now()->addDay(), ['run' => $run->id], absolute: false);

        $this->get("https://reports.public.example{$path}")->assertOk();
        $this->get("http://data:8080{$path}")->assertOk();
        // A tampered query still fails.
        $this->get("https://reports.public.example{$path}&format=xlsx")->assertForbidden();
    }

    public function test_the_file_route_is_gone_once_the_run_has_expired(): void
    {
        $run = $this->finished(2, ['expires_at' => now()->addHours(2)]);
        $link = URL::temporarySignedRoute('report-builder.files', now()->addDay(), ['run' => $run->id], absolute: false);

        $this->get($link)->assertOk();

        $this->travel(3)->hours();
        $this->getJson($link)->assertStatus(410);
    }

    // ── Listing ─────────────────────────────────────────────────────────────────────────

    public function test_the_list_is_the_callers_runs_newest_first_optionally_for_one_template(): void
    {
        $first = $this->finished(1);
        $this->travel(1)->minute();
        $second = $this->finished(1);
        $this->finished(1, ['requested_by_user_id' => 77]);

        $response = $this->getJson('/api/report-builder/runs', $this->headers())->assertOk();
        $this->assertSame([$second->id, $first->id], array_column($response->json('data'), 'id'));
        $this->assertSame(2, $response->json('total'));
        $this->assertArrayNotHasKey('csv_path', $response->json('data.0'));

        $this->getJson("/api/report-builder/runs?template_id={$first->template_id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('data.*.id', [$first->id]);
    }

    // ── Prune ───────────────────────────────────────────────────────────────────────────

    public function test_the_prune_deletes_expired_runs_and_their_files_and_keeps_the_rest(): void
    {
        $expired = $this->finished(2, ['expires_at' => now()->subMinute()]);
        Storage::disk('local')->put("reports/{$expired->id}.xlsx", 'xlsx');
        $kept = $this->finished(2);
        $running = $this->finished(0, ['status' => 'running']);
        // Left by a worker that was killed, and one still in use.
        Storage::disk('local')->put('reports/tmp/01JORPHANORPHANORPHANORPHA.sqlite', 'staging');
        Storage::disk('local')->put("reports/tmp/{$kept->id}.sqlite", 'staging');
        Storage::disk('local')->put("reports/tmp/{$running->id}.sqlite", 'staging');

        Artisan::call('reports:prune-runs');

        $this->assertSame('', Artisan::output());
        $this->assertNull($expired->fresh());
        Storage::disk('local')->assertMissing("reports/{$expired->id}.csv");
        Storage::disk('local')->assertMissing("reports/{$expired->id}.csv.idx");
        Storage::disk('local')->assertMissing("reports/{$expired->id}.xlsx");
        $this->assertNotNull($kept->fresh());
        Storage::disk('local')->assertExists($kept->csv_path);
        $this->assertSame(["reports/tmp/{$running->id}.sqlite"], Storage::disk('local')->files('reports/tmp'));
    }
}
