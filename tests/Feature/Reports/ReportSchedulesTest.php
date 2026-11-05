<?php

namespace Tests\Feature\Reports;

use App\Jobs\PublishOutboxEventJob;
use App\Jobs\RunReportJob;
use App\Models\DataOutboxEvent;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\DailySalesDataset;
use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\Concerns\AnswersReportStreams;
use Tests\Concerns\FakesAuthServer;

/**
 * Report schedules: CRUD with the token minted in pizzasys on save and revoked
 * before delete, the next-run clock, reports:dispatch-due (one transaction per
 * schedule, pauses and their in-app notices), delivery through the outbox (in-app +
 * email, attachment rules, a failed delivery), recipients reading the run, and
 * a refused token pausing the schedule.
 */
class ReportSchedulesTest extends TestCase
{
    use AnswersReportStreams, FakesAuthServer;

    private const STRANGER = 77;

    /** pizzasys's answer to a mint, when a test sets one (default: a fresh token). */
    private ?Closure $mintAnswer = null;

    /** pizzasys's answer to a revoke, when a test sets one (default: 204). */
    private ?Closure $revokeAnswer = null;

    /** The daily sales query's answer, when a test sets one. */
    private ?Closure $salesAnswer = null;

    /** The catalog's answer, when a test sets one. */
    private ?Closure $catalogAnswer = null;

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
        (require base_path('database/migrations/2026_05_14_134816_create_data_outbox_events_table.php'))->up();
        (require base_path('database/migrations/2026_11_02_000001_create_report_templates_table.php'))->up();
        (require base_path('database/migrations/2026_11_02_000002_create_report_runs_table.php'))->up();
        (require base_path('database/migrations/2026_11_02_000003_create_report_schedules_table.php'))->up();

        config([
            'app.url' => 'https://data.example',
            'services.report_datasets.urls' => ['data' => 'http://data.test/api'],
            'services.report_datasets.dashboard_url' => 'https://portal.example/',
        ]);
        Storage::fake('local');

        // Before the auth server's catch-all: the first matching fake answers.
        Http::fake([
            'auth.test/api/v1/auth/report-schedule-tokens' => fn (Request $request) => $this->mintAnswer !== null
                ? ($this->mintAnswer)($request)
                : Http::response(['success' => true, 'data' => ['token' => "55|schedule-secret-{$request['schedule_id']}", 'expires_at' => '2027-11-02T00:00:00+00:00']], 201),
            'auth.test/api/v1/auth/report-schedule-tokens/*' => fn (Request $request) => $this->revokeAnswer !== null
                ? ($this->revokeAnswer)($request)
                : Http::response(null, 204),
        ]);
        $this->fakeAuthServer();
        Http::fake([
            'data.test/api/report-datasets' => fn () => $this->catalogAnswer !== null ? ($this->catalogAnswer)() : Http::response([
                'service' => 'Data',
                'contract_version' => 1,
                'contract_sha1' => ReportDataset::contractSha1(),
                'datasets' => [(new DailySalesDataset)->toArray()],
            ]),
            'data.test/api/report-datasets/data.daily_sales/query' => fn (Request $request) => $this->salesAnswer !== null
                ? ($this->salesAnswer)($request)
                : $this->answer($request, [['store' => '03795-00001', 'date' => '2026-10-28', 'net_sales' => 10], ['store' => '03795-00002', 'date' => '2026-10-28', 'net_sales' => 20]]),
        ]);

        User::query()->create(['id' => 21, 'name' => 'Sam Ortiz', 'email' => 'sam@example.com']);
        User::query()->create(['id' => 22, 'name' => 'Lee Park', 'email' => 'lee@example.com']);
        User::query()->create(['id' => 23, 'name' => 'No Mail', 'email' => '']);
        User::query()->create(['id' => self::STRANGER, 'name' => 'Other Person', 'email' => 'other@example.com']);

        // Monday 2026-11-02, 08:00 in New York (EST since 2026-11-01).
        $this->travelTo(Carbon::parse('2026-11-02 13:00:00', 'UTC'));
    }

    /** The contract's stream-mode answer: group keys, then aggregates by alias. */
    private function answer(Request $request, array $rows)
    {
        return $this->ndjson($this->keysOf($request), array_map(fn (array $row) => [
            ...array_map(fn (string $key) => $row[$key], $request['group_by']),
            ...array_map(fn (array $aggregate) => $row[$aggregate['column']] ?? null, $request['aggregates']),
        ], $rows));
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
                'columns' => [['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum', 'label' => 'Net sales']],
                'period' => ['type' => 'fixed', 'from' => '2026-10-27', 'to' => '2026-11-02'],
                'stores' => ['mode' => 'all'],
            ], $definition),
        ]);
    }

    private function body(array $overrides = []): array
    {
        return array_replace([
            'template_id' => $this->template()->id,
            'frequency' => 'weekly',
            'run_time' => '07:30',
            'weekday' => 2,
            'recipients' => ['users' => [21, 22]],
            'format' => 'xlsx',
            'delivery' => 'both',
        ], $overrides);
    }

    /** A schedule straight in the table, due now unless told otherwise. */
    private function schedule(array $overrides = []): ReportSchedule
    {
        return ReportSchedule::query()->create([
            'template_id' => $overrides['template_id'] ?? $this->template()->id,
            'owner_user_id' => 9,
            'frequency' => 'daily',
            'run_time' => '08:00:00',
            'timezone' => 'America/New_York',
            'recipients' => ['users' => [21, 22, 23]],
            'format' => 'csv',
            'delivery' => 'both',
            'run_token' => '55|schedule-secret',
            'next_run_at' => now()->subMinute(),
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function mints(): array
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/report-schedule-tokens'))->values()->all();
    }

    private function revokes(): array
    {
        return Http::recorded(fn (Request $request) => $request->method() === 'DELETE' && str_contains($request->url(), '/report-schedule-tokens/'))->values()->all();
    }

    /** Dispatches what is due and runs the one job it queued. */
    private function dispatchAndRun(): ReportRun
    {
        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $job = null;
        Queue::assertPushed(RunReportJob::class, function (RunReportJob $pushed) use (&$job) {
            $job = $pushed;

            return true;
        });
        app()->call([$job, 'handle']);

        return ReportRun::query()->findOrFail($job->runId);
    }

    /** @return array<string, array> outbox payload data by subject, in order */
    private function outbox(): array
    {
        return DataOutboxEvent::query()->orderBy('id')->get()
            ->map(fn (DataOutboxEvent $event) => [$event->subject, $event->payload['data']])
            ->all();
    }

    // ── Create ──────────────────────────────────────────────────────────────────────────

    public function test_a_schedule_is_created_with_its_token_minted_by_the_caller_and_kept_encrypted(): void
    {
        $response = $this->postJson('/api/report-builder/schedules', $this->body(), $this->headers())->assertCreated();
        $id = $response->json('id');

        $this->assertSame([
            'id' => $id,
            'template_id' => $response->json('template_id'),
            'template_name' => 'Weekly Sales',
            'owner_user_id' => 9,
            'frequency' => 'weekly',
            'run_time' => '07:30',
            'weekday' => 2,
            'day_of_month' => null,
            'timezone' => 'America/New_York',
            'recipients' => ['users' => [21, 22]],
            'format' => 'xlsx',
            'delivery' => 'both',
            // Tuesday 07:30 in New York.
            'next_run_at' => '2026-11-03T12:30:00.000000Z',
            'last_run_at' => null,
            'is_active' => true,
            'created_at' => '2026-11-02T13:00:00.000000Z',
            'updated_at' => '2026-11-02T13:00:00.000000Z',
        ], $response->json());

        [[$mint]] = $this->mints();
        $this->assertSame('Bearer 1|test-token', $mint->header('Authorization')[0]);
        $this->assertSame(['schedule_id' => $id], $mint->data());

        $raw = DB::table('report_schedules')->where('id', $id)->value('run_token');
        $this->assertStringNotContainsString('schedule-secret', $raw);
        $this->assertSame("55|schedule-secret-{$id}", Crypt::decryptString($raw));
        $this->assertSame("55|schedule-secret-{$id}", ReportSchedule::query()->find($id)->run_token);
        $this->assertStringNotContainsString('schedule-secret', $response->getContent());
        // The token's end, from pizzasys's answer.
        $this->assertSame('2027-11-02 00:00:00', ReportSchedule::query()->find($id)->token_expires_at->toDateTimeString());
    }

    public function test_the_token_routes_are_found_when_the_auth_base_url_ends_in_api_v1(): void
    {
        // The testing .env shape: base URL with /api/v1, verify path without it.
        config(['services.auth_server.base_url' => 'http://auth.test/api/v1', 'services.auth_server.verify_path' => '/auth/token-verify']);

        $id = $this->postJson('/api/report-builder/schedules', $this->body(), $this->headers())->assertCreated()->json('id');
        $this->assertSame('http://auth.test/api/v1/auth/report-schedule-tokens', $this->mints()[0][0]->url());

        $this->deleteJson("/api/report-builder/schedules/{$id}", [], $this->headers())->assertOk();
        $this->assertSame("http://auth.test/api/v1/auth/report-schedule-tokens/{$id}", $this->revokes()[0][0]->url());
    }

    public function test_a_schedule_is_checked_before_any_token_is_asked_for(): void
    {
        $theirs = $this->template(owner: self::STRANGER)->id;
        $cases = [
            'weekday' => ['weekday' => null],
            'day_of_month' => ['frequency' => 'monthly', 'day_of_month' => null],
            'timezone' => ['timezone' => 'Mars/Olympus'],
            'run_time' => ['run_time' => '25:00'],
            'recipients.users' => ['recipients' => ['users' => []]],
            'recipients.users.0' => ['recipients' => ['users' => [999]]],
            'recipients' => ['recipients' => ['users' => [21], 'roles' => ['x']]],
            'format' => ['format' => 'pdf'],
            'delivery' => ['delivery' => 'fax'],
            'template_id' => ['template_id' => $theirs],
        ];
        foreach ($cases as $field => $overrides) {
            $this->postJson('/api/report-builder/schedules', $this->body($overrides), $this->headers())
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
        $this->assertSame([], $this->mints());
        $this->assertSame(0, ReportSchedule::query()->count());

        // An org-wide template someone else owns can be scheduled; weekday is dropped for monthly.
        $org = $this->template(owner: self::STRANGER, visibility: 'org')->id;
        $this->postJson('/api/report-builder/schedules', $this->body(['template_id' => $org, 'frequency' => 'monthly', 'day_of_month' => 31, 'timezone' => 'Asia/Tokyo']), $this->headers())
            ->assertCreated()
            ->assertJsonPath('weekday', null)
            ->assertJsonPath('day_of_month', 31)
            ->assertJsonPath('timezone', 'Asia/Tokyo')
            // 2026-11-30 07:30 in Tokyo.
            ->assertJsonPath('next_run_at', '2026-11-29T22:30:00.000000Z');
    }

    public function test_no_schedule_is_kept_when_pizzasys_hands_out_no_token(): void
    {
        foreach ([
            fn () => Http::response(['message' => 'down'], 500),
            fn () => Http::failedConnection(),
            fn () => Http::response(['success' => true, 'data' => []], 201),
            fn () => Http::response(['message' => 'Unauthenticated.'], 401),
        ] as $answer) {
            $this->mintAnswer = $answer;

            $this->postJson('/api/report-builder/schedules', $this->body(), $this->headers())
                ->assertStatus(503)
                ->assertJsonPath('message', 'Could not get a token for this schedule from the auth server; try again shortly.');
            $this->assertSame(0, ReportSchedule::query()->count());
        }
    }

    public function test_a_mint_that_timed_out_may_have_made_a_token_so_it_is_given_back(): void
    {
        $this->mintAnswer = fn () => Http::failedConnection();

        $this->postJson('/api/report-builder/schedules', $this->body(), $this->headers())
            ->assertStatus(503)
            ->assertJsonPath('message', 'Could not get a token for this schedule from the auth server; try again shortly.');

        $this->assertSame(0, ReportSchedule::query()->count());
        [$mint] = $this->mints()[0];
        [$revoke] = $this->revokes()[0];
        $this->assertSame($mint->data()['schedule_id'], (int) basename($revoke->url()));
        $this->assertSame('Bearer 1|test-token', $revoke->header('Authorization')[0]);
    }

    public function test_a_token_made_for_a_row_that_cannot_be_saved_is_given_back(): void
    {
        ReportSchedule::updating(fn () => throw new RuntimeException('disk full'));

        $this->postJson('/api/report-builder/schedules', $this->body(), $this->headers())
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Could not save this schedule; try again shortly.']);

        ReportSchedule::flushEventListeners();
        $this->assertSame(0, ReportSchedule::query()->count());
        $this->assertCount(1, $this->mints());
        [[$revoke]] = $this->revokes();
        $this->assertSame('Bearer 1|test-token', $revoke->header('Authorization')[0]);
        $this->assertSame($this->mints()[0][0]->data()['schedule_id'], (int) basename($revoke->url()));
    }

    public function test_a_revoke_that_fails_after_a_failed_save_is_ignored(): void
    {
        ReportSchedule::updating(fn () => throw new RuntimeException('disk full'));
        $this->revokeAnswer = fn () => Http::failedConnection();

        $this->postJson('/api/report-builder/schedules', $this->body(), $this->headers())->assertStatus(503);

        ReportSchedule::flushEventListeners();
        $this->assertSame(0, ReportSchedule::query()->count());
    }

    public function test_an_update_that_minted_a_token_but_cannot_be_saved_gives_it_back_and_leaves_the_schedule_paused(): void
    {
        // Active but without a token: any update mints one.
        $schedule = $this->schedule(['run_token' => null]);
        ReportSchedule::updating(fn () => throw new RuntimeException('disk full'));

        $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['format' => 'xlsx'], $this->headers())
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Could not save this schedule; try again shortly.']);

        ReportSchedule::flushEventListeners();
        $fresh = $schedule->fresh();
        $this->assertFalse($fresh->is_active);
        $this->assertSame('csv', $fresh->format);
        $this->assertCount(1, $this->mints());
        $this->assertCount(1, $this->revokes());
        $this->assertStringEndsWith("/report-schedule-tokens/{$schedule->id}", $this->revokes()[0][0]->url());
    }

    public function test_an_update_that_fails_without_a_new_token_just_fails(): void
    {
        $schedule = $this->schedule();
        ReportSchedule::updating(fn () => throw new RuntimeException('disk full'));

        $this->withoutExceptionHandling();
        try {
            $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['format' => 'xlsx'], $this->headers());
            $this->fail('The failure should surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        } finally {
            ReportSchedule::flushEventListeners();
        }

        $this->assertSame([], $this->revokes());
        $this->assertTrue($schedule->fresh()->is_active);
    }

    // ── Read ────────────────────────────────────────────────────────────────────────────

    public function test_the_list_and_show_are_the_callers_own_schedules_with_their_latest_run(): void
    {
        $mine = $this->schedule();
        $theirs = $this->schedule(['owner_user_id' => self::STRANGER]);
        foreach (['2026-10-30 12:00:00', '2026-11-01 12:00:00'] as $at) {
            $this->travelTo(Carbon::parse($at, 'UTC'));
            ReportRun::query()->create([
                'template_id' => $mine->template_id, 'schedule_id' => $mine->id, 'requested_by_user_id' => 9, 'definition' => [],
                'period_from' => '2026-10-27', 'period_to' => '2026-10-27', 'format' => 'csv', 'status' => 'done', 'expires_at' => now()->addDays(7),
            ]);
        }
        $this->travelBack();

        $list = $this->getJson('/api/report-builder/schedules', $this->headers())->assertOk();
        $this->assertSame([$mine->id], array_column($list->json(), 'id'));
        $this->assertSame('2026-11-01T12:00:00.000000Z', $list->json('0.last_run_at'));
        $this->assertSame('Weekly Sales', $list->json('0.template_name'));
        $this->assertArrayNotHasKey('run_token', $list->json('0'));

        $this->getJson("/api/report-builder/schedules/{$mine->id}", $this->headers())->assertOk()->assertJsonPath('id', $mine->id);
        $this->getJson("/api/report-builder/schedules/{$theirs->id}", $this->headers())->assertNotFound();
    }

    // ── Update ──────────────────────────────────────────────────────────────────────────

    public function test_an_update_takes_the_full_body_is_checked_again_and_moves_the_next_run_without_a_new_token(): void
    {
        $id = $this->postJson('/api/report-builder/schedules', $this->body(), $this->headers())->assertCreated()->json('id');
        $other = $this->template(owner: self::STRANGER, visibility: 'org');
        $full = fn (array $overrides) => [
            'template_id' => $other->id, 'frequency' => 'monthly', 'run_time' => '07:30', 'weekday' => null, 'day_of_month' => 31,
            'timezone' => 'America/New_York', 'recipients' => ['users' => [22]], 'format' => 'csv', 'delivery' => 'link', 'is_active' => true,
            ...$overrides,
        ];

        $this->putJson("/api/report-builder/schedules/{$id}", $full(['day_of_month' => null]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('day_of_month');
        $this->putJson("/api/report-builder/schedules/{$id}", $full(['template_id' => $this->template(owner: self::STRANGER)->id]), $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('template_id');

        $response = $this->putJson("/api/report-builder/schedules/{$id}", $full([]), $this->headers())->assertOk();

        $this->assertSame([$other->id, 'Weekly Sales', 'monthly', null, 31, ['users' => [22]], 'csv', 'link', true], [
            $response->json('template_id'), $response->json('template_name'), $response->json('frequency'), $response->json('weekday'),
            $response->json('day_of_month'), $response->json('recipients'), $response->json('format'), $response->json('delivery'), $response->json('is_active'),
        ]);
        // November has 30 days: 2026-11-30 07:30 in New York.
        $this->assertSame('2026-11-30T12:30:00.000000Z', $response->json('next_run_at'));
        // An active schedule that has a token keeps it.
        $this->assertCount(1, $this->mints());
        $this->assertSame("55|schedule-secret-{$id}", ReportSchedule::query()->find($id)->run_token);
    }

    public function test_pausing_keeps_the_token_and_resuming_mints_a_fresh_one(): void
    {
        $schedule = $this->schedule();
        $this->mintAnswer = fn () => Http::response(['success' => true, 'data' => ['token' => '56|renewed', 'expires_at' => '2027-12-01T00:00:00+00:00']], 201);

        $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['is_active' => false], $this->headers())->assertOk()->assertJsonPath('is_active', false);
        $this->assertSame([], $this->mints());
        $this->assertSame('55|schedule-secret', $schedule->fresh()->run_token);

        $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['is_active' => true], $this->headers())->assertOk()->assertJsonPath('is_active', true);
        $this->assertCount(1, $this->mints());
        $this->assertSame('56|renewed', $schedule->fresh()->run_token);
        $this->assertSame('2027-12-01 00:00:00', $schedule->fresh()->token_expires_at->toDateTimeString());

        // A schedule without a token gets one on any update.
        $schedule->update(['run_token' => null]);
        $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['format' => 'xlsx'], $this->headers())->assertOk();
        $this->assertCount(2, $this->mints());
    }

    public function test_a_resume_that_gets_no_token_changes_nothing_and_only_the_owner_updates(): void
    {
        $schedule = $this->schedule(['is_active' => false, 'next_run_at' => '2026-11-03 13:00:00']);
        $theirs = $this->schedule(['owner_user_id' => self::STRANGER]);
        $this->mintAnswer = fn () => Http::response(['message' => 'down'], 503);

        // The 503 is JSON even when the caller did not ask for it.
        $this->put("/api/report-builder/schedules/{$schedule->id}", ['is_active' => true, 'format' => 'xlsx', 'run_time' => '09:15'], ['Authorization' => 'Bearer 1|test-token'])
            ->assertStatus(503)
            ->assertExactJson(['message' => 'Could not get a token for this schedule from the auth server; try again shortly.']);

        $fresh = $schedule->fresh();
        $this->assertSame([false, 'csv', '08:00:00', '55|schedule-secret'], [$fresh->is_active, $fresh->format, $fresh->run_time, $fresh->run_token]);
        $this->assertSame('2026-11-03 13:00:00', $fresh->next_run_at->toDateTimeString());

        $this->mintAnswer = null;
        $this->putJson("/api/report-builder/schedules/{$theirs->id}", ['format' => 'xlsx'], $this->headers())->assertNotFound();
        $this->assertSame('csv', $theirs->fresh()->format);
    }

    public function test_a_recipient_and_a_stranger_can_neither_change_nor_delete_a_schedule(): void
    {
        $schedule = $this->schedule(['recipients' => ['users' => [21]]]);

        foreach ([21, self::STRANGER] as $id) {
            $this->authUser = User::query()->find($id);

            $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['format' => 'xlsx', 'is_active' => false], $this->headers())->assertNotFound();
            $this->deleteJson("/api/report-builder/schedules/{$schedule->id}", [], $this->headers())->assertNotFound();
            $this->getJson("/api/report-builder/schedules/{$schedule->id}", $this->headers())->assertNotFound();
        }

        $fresh = $schedule->fresh();
        $this->assertSame(['csv', true], [$fresh->format, $fresh->is_active]);
        $this->assertSame([], $this->mints());
        $this->assertSame([], $this->revokes());
    }

    // ── Delete ──────────────────────────────────────────────────────────────────────────

    public function test_a_delete_revokes_the_token_first_and_keeps_the_runs(): void
    {
        $schedule = $this->schedule();
        $run = ReportRun::query()->create([
            'template_id' => $schedule->template_id, 'schedule_id' => $schedule->id, 'requested_by_user_id' => 9, 'definition' => [],
            'period_from' => '2026-10-27', 'period_to' => '2026-10-27', 'format' => 'csv', 'status' => 'done', 'expires_at' => now()->addDays(7),
        ]);

        $this->deleteJson("/api/report-builder/schedules/{$schedule->id}", [], $this->headers())->assertOk();

        [[$revoke]] = $this->revokes();
        $this->assertSame("http://auth.test/api/v1/auth/report-schedule-tokens/{$schedule->id}", $revoke->url());
        $this->assertSame('Bearer 1|test-token', $revoke->header('Authorization')[0]);
        $this->assertNull($schedule->fresh());
        $this->assertNull($run->fresh()->schedule_id);
    }

    public function test_a_delete_pizzasys_cannot_confirm_keeps_the_schedule_and_a_404_counts_as_revoked(): void
    {
        $schedule = $this->schedule();
        $theirs = $this->schedule(['owner_user_id' => self::STRANGER]);

        foreach ([fn () => Http::response(['message' => 'down'], 500), fn () => Http::failedConnection()] as $answer) {
            $this->revokeAnswer = $answer;
            $this->deleteJson("/api/report-builder/schedules/{$schedule->id}", [], $this->headers())
                ->assertStatus(503)
                ->assertExactJson(['message' => 'Could not revoke this schedule\'s token at the auth server; try again shortly.']);
            $this->assertNotNull($schedule->fresh());
        }

        $this->revokeAnswer = fn () => Http::response(['message' => 'Not found.'], 404);
        $this->deleteJson("/api/report-builder/schedules/{$schedule->id}", [], $this->headers())->assertOk();
        $this->assertNull($schedule->fresh());

        $this->deleteJson("/api/report-builder/schedules/{$theirs->id}", [], $this->headers())->assertNotFound();
        $this->assertNotNull($theirs->fresh());
    }

    public function test_deleting_a_template_revokes_the_owners_schedule_tokens_first(): void
    {
        $template = $this->template();
        $schedule = $this->schedule(['template_id' => $template->id]);

        $this->revokeAnswer = fn () => Http::response(['message' => 'down'], 500);
        $this->deleteJson("/api/report-builder/templates/{$template->id}", [], $this->headers())->assertStatus(503);
        $this->assertNotNull($template->fresh());
        $this->assertNotNull($schedule->fresh());

        $this->revokeAnswer = null;
        $this->deleteJson("/api/report-builder/templates/{$template->id}", [], $this->headers())->assertOk();
        $this->assertNull($template->fresh());
        $this->assertNull($schedule->fresh());
        $this->assertCount(2, $this->revokes());
    }

    public function test_a_template_with_another_users_schedule_cannot_be_deleted_until_it_is_gone(): void
    {
        $template = $this->template();
        $mine = $this->schedule(['template_id' => $template->id]);
        $theirs = $this->schedule(['template_id' => $template->id, 'owner_user_id' => self::STRANGER]);

        $this->deleteJson("/api/report-builder/templates/{$template->id}", [], $this->headers())
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Other users still have schedules on this template; they must delete them first.']);

        // Nothing was touched: no token revoked, nothing deleted.
        $this->assertSame([], $this->revokes());
        $this->assertNotNull($template->fresh());
        $this->assertNotNull($mine->fresh());
        $this->assertNotNull($theirs->fresh());

        // Their schedule goes (they delete it themselves).
        $this->authUser = User::query()->find(self::STRANGER);
        $this->deleteJson("/api/report-builder/schedules/{$theirs->id}", [], $this->headers())->assertOk();

        $this->authUser = User::query()->find(9);
        $this->deleteJson("/api/report-builder/templates/{$template->id}", [], $this->headers())->assertOk();
        $this->assertNull($template->fresh());
        $this->assertNull($mine->fresh());
        // Theirs, then the owner's own.
        $this->assertCount(2, $this->revokes());
    }

    // ── The clock ───────────────────────────────────────────────────────────────────────

    public function test_the_next_run_is_the_first_slot_after_the_given_instant_in_the_schedules_timezone(): void
    {
        $next = fn (array $attributes, string $from) => (new ReportSchedule(['timezone' => 'America/New_York', 'run_time' => '07:30:00', ...$attributes]))
            ->computeNextRunAt(Carbon::parse($from, 'UTC'))->toIso8601String();

        // Daily: later today, or tomorrow once today's slot has passed (or is now).
        $this->assertSame('2026-11-02T12:30:00+00:00', $next(['frequency' => 'daily'], '2026-11-02 12:00:00'));
        $this->assertSame('2026-11-03T12:30:00+00:00', $next(['frequency' => 'daily'], '2026-11-02 12:30:00'));
        // The wall clock holds across the DST change (EDT before 2026-11-01).
        $this->assertSame('2026-11-01T12:30:00+00:00', $next(['frequency' => 'daily'], '2026-10-31 12:00:00'));
        $this->assertSame('2026-10-31T11:30:00+00:00', $next(['frequency' => 'daily'], '2026-10-30 12:00:00'));

        // Weekly (0 = Sunday): this Tuesday, or next week's once it has passed.
        $this->assertSame('2026-11-03T12:30:00+00:00', $next(['frequency' => 'weekly', 'weekday' => 2], '2026-11-02 13:00:00'));
        $this->assertSame('2026-11-10T12:30:00+00:00', $next(['frequency' => 'weekly', 'weekday' => 2], '2026-11-03 13:00:00'));
        $this->assertSame('2026-11-08T12:30:00+00:00', $next(['frequency' => 'weekly', 'weekday' => 0], '2026-11-02 13:00:00'));

        // Monthly: day 31 falls on the last day of a shorter month.
        $this->assertSame('2026-11-30T12:30:00+00:00', $next(['frequency' => 'monthly', 'day_of_month' => 31], '2026-11-02 13:00:00'));
        $this->assertSame('2026-12-31T12:30:00+00:00', $next(['frequency' => 'monthly', 'day_of_month' => 31], '2026-11-30 13:00:00'));
        $this->assertSame('2027-02-28T12:30:00+00:00', $next(['frequency' => 'monthly', 'day_of_month' => 31], '2027-01-31 13:00:00'));
        $this->assertSame('2026-12-01T12:30:00+00:00', $next(['frequency' => 'monthly', 'day_of_month' => 1], '2026-11-02 13:00:00'));

        // Read in the schedule's own timezone: 23:30 UTC on the 2nd is already the 3rd in Tokyo.
        $this->assertSame('2026-11-03T22:30:00+00:00', $next(['frequency' => 'daily', 'timezone' => 'Asia/Tokyo'], '2026-11-02 23:30:00'));
        $this->assertSame('2026-11-02T22:30:00+00:00', $next(['frequency' => 'daily', 'timezone' => 'Asia/Tokyo'], '2026-11-02 21:00:00'));
    }

    // ── Dispatch ────────────────────────────────────────────────────────────────────────

    public function test_a_due_schedule_starts_a_run_for_its_owner_with_its_period_and_moves_on(): void
    {
        // 05:00 on the 3rd in Tokyo, still the 2nd in New York.
        $this->travelTo(Carbon::parse('2026-11-02 20:00:00', 'UTC'));
        $template = $this->template(['period' => ['type' => 'relative', 'token' => 'yesterday']]);
        $due = $this->schedule(['template_id' => $template->id, 'timezone' => 'Asia/Tokyo', 'format' => 'xlsx', 'run_time' => '05:00:00']);
        $later = $this->schedule(['next_run_at' => now()->addMinute()]);
        $paused = $this->schedule(['is_active' => false]);

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertSame('', Artisan::output());
        $run = ReportRun::query()->sole();
        $this->assertSame([$template->id, $due->id, 9, 'xlsx', 'queued'], [$run->template_id, $run->schedule_id, $run->requested_by_user_id, $run->format, $run->status]);
        $this->assertSame(['2026-11-02', '2026-11-02'], [$run->period_from->toDateString(), $run->period_to->toDateString()]);
        $this->assertSame($template->definition, $run->definition);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $run->expires_at->timestamp, 1);
        Queue::assertPushed(RunReportJob::class, 1);
        Queue::assertPushed(RunReportJob::class, fn (RunReportJob $job) => $job->runId === $run->id && $job->connection === 'reports' && $job->queue === 'reports');

        // Tomorrow 05:00 in Tokyo.
        $this->assertSame('2026-11-03 20:00:00', $due->fresh()->next_run_at->toDateTimeString());
        $this->assertSame(now()->addMinute()->toDateTimeString(), $later->fresh()->next_run_at->toDateTimeString());
        $this->assertSame(now()->subMinute()->toDateTimeString(), $paused->fresh()->next_run_at->toDateTimeString());
    }

    public function test_a_due_schedule_whose_owner_has_a_run_going_is_skipped_and_stays_due(): void
    {
        $schedule = $this->schedule();
        $busy = ReportRun::query()->create([
            'template_id' => $schedule->template_id, 'requested_by_user_id' => 9, 'definition' => [],
            'period_from' => '2026-10-27', 'period_to' => '2026-10-27', 'format' => 'csv', 'status' => 'running', 'expires_at' => now()->addDays(7),
        ]);

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertSame([$busy->id], ReportRun::query()->pluck('id')->all());
        Queue::assertNothingPushed();
        // Not moved on: it is tried again next minute.
        $this->assertSame(now()->subMinute()->toDateTimeString(), $schedule->fresh()->next_run_at->toDateTimeString());
        $this->assertTrue($schedule->fresh()->is_active);

        // The run ends; a minute later it starts.
        $busy->update(['status' => 'done']);
        $this->travelTo(now()->addMinute());
        Artisan::call('reports:dispatch-due');

        $this->assertSame(2, ReportRun::query()->count());
        Queue::assertPushed(RunReportJob::class, 1);
        $this->assertSame('2026-11-03 13:00:00', $schedule->fresh()->next_run_at->toDateTimeString());
    }

    public function test_a_held_lock_skips_the_schedule_and_leaves_it_due(): void
    {
        $schedule = $this->schedule();
        $lock = Cache::lock('report-builder:run:9', 30);
        $this->assertTrue($lock->get());

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertSame(0, ReportRun::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame(now()->subMinute()->toDateTimeString(), $schedule->fresh()->next_run_at->toDateTimeString());

        $lock->release();
        Artisan::call('reports:dispatch-due');
        $this->assertSame(1, ReportRun::query()->count());
    }

    public function test_two_due_schedules_of_one_owner_both_run_the_second_a_minute_later(): void
    {
        $first = $this->schedule(['next_run_at' => now()->subMinutes(2)]);
        $second = $this->schedule();

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertSame([$first->id], ReportRun::query()->pluck('schedule_id')->all());
        // The second is still due; the first moved on.
        $this->assertSame(now()->subMinute()->toDateTimeString(), $second->fresh()->next_run_at->toDateTimeString());
        $this->assertSame('2026-11-03 13:00:00', $first->fresh()->next_run_at->toDateTimeString());

        ReportRun::query()->update(['status' => 'done']);
        $this->travelTo(now()->addMinute());
        Artisan::call('reports:dispatch-due');

        $this->assertEqualsCanonicalizing([$first->id, $second->id], ReportRun::query()->pluck('schedule_id')->all());
        Queue::assertPushed(RunReportJob::class, 2);
        $this->assertSame('2026-11-03 13:00:00', $second->fresh()->next_run_at->toDateTimeString());
    }

    public function test_a_schedule_whose_token_cannot_be_read_is_paused_and_never_blocks_the_others(): void
    {
        $poison = $this->schedule(['next_run_at' => now()->subMinutes(2), 'recipients' => ['users' => [21]]]);
        DB::table('report_schedules')->where('id', $poison->id)->update(['run_token' => 'not-a-payload']);
        $good = $this->schedule(['owner_user_id' => self::STRANGER, 'template_id' => $this->template(owner: self::STRANGER)->id]);

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertSame('', Artisan::output());
        $this->assertFalse($poison->fresh()->is_active);
        $this->assertSame(0, ReportRun::query()->where('schedule_id', $poison->id)->count());
        $this->assertSame([$good->id], ReportRun::query()->pluck('schedule_id')->all());
        Queue::assertPushed(RunReportJob::class, 1);
        $this->assertSame([['notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [['id' => 9, 'data' => [
                'type' => 'report_schedule_paused',
                'title' => 'Weekly Sales',
                'body' => 'Schedule paused: its token could not be read. Resume it to renew.',
                'action_url' => 'https://portal.example/dashboard/report-builder?tab=schedules',
            ]]],
        ]]], $this->outbox());

        // Paused, it is not looked at again.
        $this->travelTo(now()->addDay());
        Artisan::call('reports:dispatch-due');
        $this->assertSame(0, ReportRun::query()->where('schedule_id', $poison->id)->count());
        $this->assertSame(1, DataOutboxEvent::query()->count());
    }

    public function test_a_paused_schedule_never_runs(): void
    {
        $this->schedule(['is_active' => false, 'next_run_at' => now()->subDay()]);

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertSame(0, ReportRun::query()->count());
        Queue::assertNothingPushed();
        $this->assertSame(0, DataOutboxEvent::query()->count());
    }

    public function test_a_schedule_whose_token_expires_within_a_week_is_paused_and_its_owner_told(): void
    {
        $expiring = $this->schedule(['token_expires_at' => now()->addDays(7)->subMinute()]);
        $fine = $this->schedule(['owner_user_id' => self::STRANGER, 'template_id' => $this->template(owner: self::STRANGER)->id, 'token_expires_at' => now()->addDays(8)]);

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertFalse($expiring->fresh()->is_active);
        $this->assertSame([$fine->id], ReportRun::query()->pluck('schedule_id')->all());
        $this->assertSame([['notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [['id' => 9, 'data' => [
                'type' => 'report_schedule_paused',
                'title' => 'Weekly Sales',
                'body' => 'Schedule paused: its token expires soon. Resume it to renew.',
                'action_url' => 'https://portal.example/dashboard/report-builder?tab=schedules',
            ]]],
        ]]], $this->outbox());
    }

    public function test_resuming_a_schedule_paused_for_its_expiring_token_mints_a_new_one(): void
    {
        $schedule = $this->schedule(['is_active' => false, 'token_expires_at' => now()->addDays(2)]);
        $this->mintAnswer = fn () => Http::response(['success' => true, 'data' => ['token' => '57|fresh', 'expires_at' => now()->addYear()->toIso8601String()]], 201);

        $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['is_active' => true], $this->headers())->assertOk();

        $this->assertSame('57|fresh', $schedule->fresh()->run_token);
        $this->assertTrue($schedule->fresh()->token_expires_at->isAfter(now()->addDays(300)));

        Queue::fake();
        $schedule->fresh()->update(['next_run_at' => now()->subMinute()]);
        Artisan::call('reports:dispatch-due');
        $this->assertSame(1, ReportRun::query()->count());
    }

    public function test_a_schedule_whose_template_the_owner_can_no_longer_read_is_paused(): void
    {
        $schedule = $this->schedule(['template_id' => $this->template(owner: self::STRANGER)->id]);

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertSame(0, ReportRun::query()->count());
        Queue::assertNotPushed(RunReportJob::class);
        $this->assertFalse($schedule->fresh()->is_active);
        // The private template's name is not given away.
        $this->assertSame([['notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [['id' => 9, 'data' => [
                'type' => 'report_schedule_paused',
                'title' => 'Report',
                'body' => 'Schedule paused: the template is no longer available to you.',
                'action_url' => 'https://portal.example/dashboard/report-builder?tab=schedules',
            ]]],
        ]]], $this->outbox());
    }

    public function test_a_schedule_can_be_paused_after_its_template_went_private_but_not_moved_to_one(): void
    {
        $template = $this->template(owner: self::STRANGER, visibility: 'org');
        $schedule = $this->schedule(['template_id' => $template->id]);
        $template->update(['visibility' => 'private']);

        $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['is_active' => false], $this->headers())->assertOk()->assertJsonPath('is_active', false);
        $this->assertFalse($schedule->fresh()->is_active);

        $this->putJson("/api/report-builder/schedules/{$schedule->id}", ['template_id' => $this->template(owner: self::STRANGER)->id], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('template_id');
    }

    public function test_the_token_never_sits_in_plain_text_in_the_jobs_table_or_the_schedule_row(): void
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
        config(['queue.connections.reports.connection' => null]);
        $this->schedule();

        Artisan::call('reports:dispatch-due');

        $payload = DB::table('jobs')->where('queue', 'reports')->sole()->payload;
        $this->assertStringContainsString('RunReportJob', $payload);
        $this->assertStringNotContainsString('schedule-secret', $payload);
        $this->assertStringNotContainsString('schedule-secret', DB::table('report_schedules')->value('run_token'));
    }

    // ── Delivery ────────────────────────────────────────────────────────────────────────

    public function test_a_finished_run_is_delivered_in_app_to_every_recipient_and_by_email_with_the_file(): void
    {
        $schedule = $this->schedule();

        $run = $this->dispatchAndRun();

        $this->assertSame('done', $run->status);
        $url = "https://portal.example/dashboard/report-builder/runs/{$run->id}";
        [$web, $email] = $this->outbox();

        $this->assertSame(['notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => array_map(fn (int $id) => ['id' => $id, 'data' => [
                'type' => 'report_ready',
                'title' => 'Weekly Sales',
                'body' => '2026-10-27 – 2026-11-02 · 2 rows',
                'action_url' => $url,
            ]], [21, 22, 23]),
        ]], $web);

        $this->assertSame('notifications.v1.email.send', $email[0]);
        $attachment = $email[1]['attachments'][0];
        $this->assertSame([
            'template' => 'report_ready',
            'subject' => 'Weekly Sales · 2026-10-27 – 2026-11-02',
            // 23 has no address.
            'users' => array_map(fn (string $address) => ['email' => $address, 'data' => [
                'report_name' => 'Weekly Sales',
                'period_label' => '2026-10-27 – 2026-11-02',
                'row_count' => 2,
                'run_url' => $url,
                'expires_on' => '2026-11-09',
            ]], ['sam@example.com', 'lee@example.com']),
            'attachments' => [[
                'url' => $attachment['url'],
                'name' => 'weekly-sales-2026-10-27-2026-11-02.csv',
                'mime' => 'text/csv',
                'size' => Storage::disk('local')->size($run->csv_path),
            ]],
        ], $email[1]);
        $this->assertSame(2, DataOutboxEvent::query()->count());
        Queue::assertPushed(PublishOutboxEventJob::class, 2);

        // A 24 h signed link to the file, on Data's own URL.
        $this->assertStringStartsWith("https://data.example/api/report-builder/files/{$run->id}?expires=", $attachment['url']);
        $this->assertStringContainsString('format=csv', $attachment['url']);
        $path = substr($attachment['url'], strlen('https://data.example'));
        $this->assertSame(Storage::disk('local')->get($run->csv_path), $this->get($path)->assertOk()->streamedContent());

        $this->assertSame(['notifications.v1.notification.send', 'notifications.v1.email.send'], DataOutboxEvent::query()->orderBy('id')->pluck('type')->all());
    }

    public function test_a_link_only_schedule_sends_no_attachment(): void
    {
        $this->schedule(['delivery' => 'link', 'format' => 'xlsx']);

        $run = $this->dispatchAndRun();

        $this->assertSame('xlsx', $run->format);
        [, [, $email]] = $this->outbox();
        $this->assertArrayNotHasKey('attachments', $email);
        $this->assertArrayNotHasKey('attachment_skipped', $email['users'][0]['data']);
    }

    public function test_an_attachment_only_schedule_attaches_the_xlsx(): void
    {
        $this->schedule(['delivery' => 'attachment', 'format' => 'xlsx']);

        $run = $this->dispatchAndRun();

        [, [, $email]] = $this->outbox();
        $this->assertSame('weekly-sales-2026-10-27-2026-11-02.xlsx', $email['attachments'][0]['name']);
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $email['attachments'][0]['mime']);
        $this->assertSame(Storage::disk('local')->size($run->xlsx_path), $email['attachments'][0]['size']);
        $this->assertStringContainsString('format=xlsx', $email['attachments'][0]['url']);
    }

    public function test_a_file_over_15_mb_is_not_attached_and_the_email_says_so(): void
    {
        $this->schedule();
        $this->salesAnswer = fn (Request $request) => $this->answer($request, [['store' => str_repeat('x', 15 * 1024 * 1024 + 1), 'date' => '2026-10-28', 'net_sales' => 1]]);

        $run = $this->dispatchAndRun();

        $this->assertGreaterThan(15 * 1024 * 1024, $run->file_size);
        [, [, $email]] = $this->outbox();
        $this->assertArrayNotHasKey('attachments', $email);
        $this->assertTrue($email['users'][0]['data']['attachment_skipped']);
    }

    public function test_a_failed_scheduled_run_tells_only_its_owner_in_app(): void
    {
        $this->schedule();
        $this->salesAnswer = fn () => Http::response(['message' => 'boom'], 500);

        $run = $this->dispatchAndRun();

        $this->assertSame('failed', $run->status);
        $this->assertSame([['notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [['id' => 9, 'data' => [
                'type' => 'report_failed',
                'title' => 'Weekly Sales',
                'body' => 'data (data.daily_sales) could not answer.',
                'action_url' => "https://portal.example/dashboard/report-builder/runs/{$run->id}",
            ]]],
        ]]], $this->outbox());
    }

    public function test_any_number_of_recipients_is_taken(): void
    {
        foreach (range(100, 179) as $id) {
            User::query()->create(['id' => $id, 'name' => "User {$id}", 'email' => "u{$id}@example.com"]);
        }

        $this->postJson('/api/report-builder/schedules', $this->body(['recipients' => ['users' => range(100, 179)]]), $this->headers())
            ->assertCreated()
            ->assertJsonCount(80, 'recipients.users');
    }

    public function test_a_scheduled_run_gone_quiet_is_failed_and_its_owner_told(): void
    {
        $schedule = $this->schedule(['next_run_at' => now()->addDay()]);
        $run = ReportRun::query()->create([
            'template_id' => $schedule->template_id, 'schedule_id' => $schedule->id, 'requested_by_user_id' => 9,
            'definition' => [], 'period_from' => '2026-11-01', 'period_to' => '2026-11-01', 'format' => 'csv',
            'status' => 'running', 'started_at' => now()->subHour(), 'heartbeat_at' => now()->subMinutes(45),
            'expires_at' => now()->addDays(7),
        ]);

        Queue::fake();
        Artisan::call('reports:dispatch-due');

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame([['notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [['id' => 9, 'data' => [
                'type' => 'report_failed',
                'title' => 'Weekly Sales',
                'body' => 'The report stopped before it finished.',
                'action_url' => "https://portal.example/dashboard/report-builder/runs/{$run->id}",
            ]]],
        ]]], $this->outbox());
    }

    public function test_a_refused_token_fails_the_run_pauses_the_schedule_and_tells_the_owner(): void
    {
        $schedule = $this->schedule();
        $this->salesAnswer = fn () => Http::response(['message' => 'Unauthorized'], 401);

        $run = $this->dispatchAndRun();

        $this->assertSame('failed', $run->status);
        $this->assertSame('The schedule was paused: its token is no longer valid. Resume it to renew.', $run->error);
        $this->assertFalse($schedule->fresh()->is_active);
        $this->assertSame([['notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [['id' => 9, 'data' => [
                'type' => 'report_schedule_paused',
                'title' => 'Weekly Sales',
                'body' => 'Schedule paused: its token is no longer valid. Resume it to renew.',
                'action_url' => "https://portal.example/dashboard/report-builder/runs/{$run->id}",
            ]]],
        ]]], $this->outbox());
    }

    public function test_a_401_from_a_service_the_template_does_not_use_does_not_pause_the_schedule(): void
    {
        $schedule = $this->schedule();
        config(['services.report_datasets.urls' => ['data' => 'http://data.test/api', 'operations' => 'http://ops.test/api']]);
        Http::fake(['ops.test/api/report-datasets' => Http::response(['message' => 'Unauthorized'], 401)]);

        $run = $this->dispatchAndRun();

        $this->assertSame('done', $run->status);
        $this->assertTrue($schedule->fresh()->is_active);
        $this->assertSame('report_ready', $this->outbox()[0][1]['users'][0]['data']['type']);
        $this->assertSame(['notifications.v1.notification.send', 'notifications.v1.email.send'], array_column($this->outbox(), 0));
    }

    public function test_only_a_refused_dataset_query_pauses_the_schedule_not_a_refused_catalog(): void
    {
        $schedule = $this->schedule();
        $this->catalogAnswer = fn () => Http::response(['message' => 'Unauthorized'], 401);

        $run = $this->dispatchAndRun();

        $this->assertSame('failed', $run->status);
        $this->assertTrue($schedule->fresh()->is_active);
        $this->assertSame('report_failed', $this->outbox()[0][1]['users'][0]['data']['type']);
    }

    public function test_a_manual_run_refused_with_a_401_just_fails_with_no_notification(): void
    {
        $template = $this->template();
        $this->salesAnswer = fn () => Http::response(['message' => 'Unauthorized'], 401);

        Queue::fake();
        $id = $this->postJson('/api/report-builder/run', ['template_id' => $template->id, 'format' => 'csv'], $this->headers())->assertCreated()->json('id');
        Queue::assertPushed(RunReportJob::class, function (RunReportJob $job) {
            app()->call([$job, 'handle']);

            return true;
        });

        $this->assertSame('data (data.daily_sales) could not answer.', ReportRun::query()->find($id)->error);
        $this->assertSame(0, DataOutboxEvent::query()->count());
    }

    public function test_a_delivery_that_fails_leaves_the_run_done_and_tells_the_owner(): void
    {
        $this->schedule();
        // The email step looks the recipients up: with no users table it throws, after the in-app notice went out.
        Schema::drop('users');

        $run = $this->dispatchAndRun();

        $this->assertSame('done', $run->status);
        $this->assertNotNull($run->csv_path);
        $this->assertNull($run->error);
        $url = "https://portal.example/dashboard/report-builder/runs/{$run->id}";
        $this->assertSame([
            ['notifications.v1.notification.send', ['channels' => ['web'], 'users' => array_map(fn (int $id) => ['id' => $id, 'data' => [
                'type' => 'report_ready', 'title' => 'Weekly Sales', 'body' => '2026-10-27 – 2026-11-02 · 2 rows', 'action_url' => $url,
            ]], [21, 22, 23])]],
            ['notifications.v1.notification.send', ['channels' => ['web'], 'users' => [['id' => 9, 'data' => [
                'type' => 'report_delivery_failed',
                'title' => 'Weekly Sales',
                'body' => 'The report finished, but it could not be delivered to its recipients.',
                'action_url' => $url,
            ]]]]],
        ], $this->outbox());
    }

    public function test_a_run_without_the_dashboard_url_fails_loudly(): void
    {
        $this->schedule();
        config(['services.report_datasets.dashboard_url' => '']);
        Exceptions::fake();

        $run = $this->dispatchAndRun();

        $this->assertSame('failed', $run->status);
        $this->assertSame('The report could not be produced.', $run->error);
        $this->assertNull($run->csv_path);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'REPORTS_DASHBOARD_URL is not set');
        // Nothing went to the recipients; the owner is told, with a link that has no host to start from.
        $this->assertSame([['notifications.v1.notification.send', [
            'channels' => ['web'],
            'users' => [['id' => 9, 'data' => [
                'type' => 'report_failed',
                'title' => 'Weekly Sales',
                'body' => 'The report could not be produced.',
                'action_url' => "/dashboard/report-builder/runs/{$run->id}",
            ]]],
        ]]], $this->outbox());
    }

    public function test_recipients_without_an_email_get_the_in_app_notice_and_no_email(): void
    {
        $this->schedule(['recipients' => ['users' => [23]]]);

        $run = $this->dispatchAndRun();

        $this->assertSame('done', $run->status);
        $this->assertSame(['notifications.v1.notification.send'], array_column($this->outbox(), 0));
        $this->assertSame([23], array_column($this->outbox()[0][1]['users'], 'id'));
    }

    public function test_a_signed_file_link_that_is_tampered_with_or_expired_is_refused(): void
    {
        $this->schedule();
        $run = $this->dispatchAndRun();
        $link = URL::temporarySignedRoute('report-builder.files', now()->addDay(), ['run' => $run->id, 'format' => 'csv'], absolute: false);

        $this->get($link)->assertOk();
        $this->get(str_replace('format=csv', 'format=xlsx', $link))->assertForbidden();
        $this->get(preg_replace('#/files/[^?]+#', '/files/'.str_repeat('0', 26), $link))->assertForbidden();
        $this->get(preg_replace('/signature=[^&]+/', 'signature='.str_repeat('a', 64), $link))->assertForbidden();
        $this->get("/api/report-builder/files/{$run->id}?format=csv")->assertForbidden();

        $this->travelTo(now()->addDay()->addMinute());
        $this->get($link)->assertForbidden();
    }

    // ── Who reads a scheduled run ───────────────────────────────────────────────────────

    public function test_a_recipient_reads_the_scheduled_run_and_a_stranger_does_not(): void
    {
        $this->authUser = User::query()->find(21);
        $schedule = $this->schedule(['owner_user_id' => 9, 'recipients' => ['users' => [21]]]);
        $run = $this->dispatchAndRun();
        $other = $this->schedule(['recipients' => ['users' => [22]]]);
        $notMine = ReportRun::query()->create([
            'template_id' => $other->template_id, 'schedule_id' => $other->id, 'requested_by_user_id' => 9, 'definition' => [],
            'period_from' => '2026-10-27', 'period_to' => '2026-10-27', 'format' => 'csv', 'status' => 'done', 'expires_at' => now()->addDays(7),
        ]);

        $this->getJson("/api/report-builder/runs/{$run->id}", $this->headers())->assertOk()->assertJsonPath('schedule_id', $schedule->id);
        $this->getJson("/api/report-builder/runs/{$run->id}/rows", $this->headers())->assertOk()->assertJsonCount(2, 'rows');
        $this->get("/api/report-builder/runs/{$run->id}/download", $this->headers())->assertOk();
        $this->assertSame([$run->id], array_column($this->getJson('/api/report-builder/runs', $this->headers())->assertOk()->json('data'), 'id'));
        $this->getJson("/api/report-builder/runs/{$notMine->id}", $this->headers())->assertNotFound();

        $this->authUser = User::query()->find(self::STRANGER);
        $this->getJson("/api/report-builder/runs/{$run->id}", $this->headers())->assertNotFound();
        $this->getJson("/api/report-builder/runs/{$run->id}/rows", $this->headers())->assertNotFound();
        $this->get("/api/report-builder/runs/{$run->id}/download", $this->headers())->assertNotFound();
        $this->assertSame([], $this->getJson('/api/report-builder/runs', $this->headers())->assertOk()->json('data'));
    }
}
