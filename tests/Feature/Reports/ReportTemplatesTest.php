<?php

namespace Tests\Feature\Reports;

use App\Models\ReportRun;
use App\Models\ReportTemplate;
use App\Reports\Contract\ReportDataset;
use App\Reports\Datasets\DailySalesDataset;
use App\Reports\Engine\ReportFileWriter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakesAuthServer;

/**
 * Saved templates: the caller's own plus every org-wide one, definitions
 * checked against the catalog, and update/delete for the owner only.
 */
class ReportTemplatesTest extends TestCase
{
    use FakesAuthServer;

    private const OTHER_USER = 77;

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
        ]);
    }

    private function definition(array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            'datasets' => [['alias' => 's', 'key' => 'data.daily_sales']],
            'grain' => ['store', 'date'],
            'columns' => [
                ['id' => 'c1', 'alias' => 's', 'column' => 'net_sales', 'agg' => 'sum', 'label' => 'Net sales'],
                ['id' => 'c2', 'kind' => 'formula', 'expr' => 'round(c1 / 2, 1)', 'label' => 'Half'],
            ],
            'period' => ['type' => 'relative', 'token' => 'last_week'],
            'stores' => ['mode' => 'mine'],
        ], $overrides);
    }

    private function template(int $owner, string $visibility, string $name = 'Sales'): ReportTemplate
    {
        return ReportTemplate::query()->create([
            'owner_user_id' => $owner,
            'name' => $name,
            'definition' => $this->definition(),
            'visibility' => $visibility,
        ]);
    }

    public function test_a_template_is_saved_for_its_owner(): void
    {
        $response = $this->postJson('/api/report-builder/templates', [
            'name' => 'Weekly sales',
            'description' => 'Net sales by store',
            'visibility' => 'private',
            'definition' => $this->definition(),
        ], $this->headers())->assertCreated();

        $this->assertEqualsCanonicalizing([
            'id', 'owner_user_id', 'name', 'description', 'definition', 'visibility', 'created_at', 'updated_at',
        ], array_keys($response->json()));
        $this->assertSame(9, $response->json('owner_user_id'));
        $this->assertSame('Weekly sales', $response->json('name'));
        $this->assertEquals($this->definition(), $response->json('definition'));
        $this->assertDatabaseHas('report_templates', ['name' => 'Weekly sales', 'owner_user_id' => 9, 'visibility' => 'private']);
    }

    public function test_a_definition_the_catalog_does_not_support_is_not_saved(): void
    {
        $definition = $this->definition();
        $definition['columns'][1]['expr'] = 'c1 + c9';

        $this->postJson('/api/report-builder/templates', [
            'name' => 'Broken',
            'visibility' => 'org',
            'definition' => $definition,
        ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['columns.1.expr' => ['c2 refers to unknown column c9 in: c1 + c9']]);

        $this->postJson('/api/report-builder/templates', [
            'visibility' => 'public',
            'definition' => $this->definition(),
        ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'visibility']);

        $this->assertSame(0, ReportTemplate::query()->count());
    }

    public function test_the_list_is_the_callers_own_and_every_org_template(): void
    {
        $this->template(9, 'private', 'B mine');
        $this->template(9, 'org', 'A mine shared');
        $this->template(self::OTHER_USER, 'org', 'C theirs shared');
        $this->template(self::OTHER_USER, 'private', 'D theirs');

        $response = $this->getJson('/api/report-builder/templates', $this->headers())->assertOk();

        $this->assertSame(['A mine shared', 'B mine', 'C theirs shared'], array_column($response->json(), 'name'));
    }

    public function test_someone_elses_private_template_does_not_exist_for_the_caller(): void
    {
        $private = $this->template(self::OTHER_USER, 'private');
        $shared = $this->template(self::OTHER_USER, 'org');

        $this->getJson("/api/report-builder/templates/{$private->id}", $this->headers())->assertNotFound();
        $this->getJson("/api/report-builder/templates/{$shared->id}", $this->headers())
            ->assertOk()
            ->assertJsonPath('id', $shared->id);
    }

    public function test_only_the_owner_updates_a_template_even_an_org_one(): void
    {
        $shared = $this->template(self::OTHER_USER, 'org');
        $mine = $this->template(9, 'private');
        $body = ['name' => 'Renamed', 'visibility' => 'org', 'definition' => $this->definition(['grain' => ['store']])];

        $this->putJson("/api/report-builder/templates/{$shared->id}", $body, $this->headers())->assertNotFound();
        $this->assertSame('Sales', $shared->fresh()->name);

        $this->putJson("/api/report-builder/templates/{$mine->id}", $body, $this->headers())
            ->assertOk()
            ->assertJsonPath('name', 'Renamed')
            ->assertJsonPath('visibility', 'org')
            ->assertJsonPath('definition.grain', ['store']);
    }

    public function test_an_update_is_checked_against_the_catalog_too(): void
    {
        $mine = $this->template(9, 'private');

        $this->putJson("/api/report-builder/templates/{$mine->id}", [
            'name' => 'Sales',
            'visibility' => 'private',
            'definition' => $this->definition(['columns' => [['id' => 'c1', 'alias' => 's', 'column' => 'nope', 'agg' => 'sum']]]),
        ], $this->headers())
            ->assertUnprocessable()
            ->assertJsonPath('errors', ['columns.0.column' => ['c1: Daily sales has no column nope.']]);
    }

    public function test_only_the_owner_deletes_and_the_runs_and_their_files_go_with_it(): void
    {
        $shared = $this->template(self::OTHER_USER, 'org');
        $mine = $this->template(9, 'private');
        $run = ReportRun::query()->create([
            'template_id' => $mine->id,
            'requested_by_user_id' => 9,
            'definition' => $mine->definition,
            'period_from' => '2026-10-21',
            'period_to' => '2026-10-27',
            'format' => 'xlsx',
            'status' => 'done',
            'expires_at' => now()->addDays(7),
        ]);
        $paths = ReportFileWriter::paths($run->id);
        Storage::disk('local')->put($paths['csv'], 'store');
        Storage::disk('local')->put($paths['xlsx'], 'xlsx');

        $this->deleteJson("/api/report-builder/templates/{$shared->id}", [], $this->headers())->assertNotFound();
        $this->assertNotNull($shared->fresh());

        $this->deleteJson("/api/report-builder/templates/{$mine->id}", [], $this->headers())->assertOk();
        $this->assertNull($mine->fresh());
        $this->assertSame(0, ReportRun::query()->count());
        Storage::disk('local')->assertMissing($paths['csv']);
        Storage::disk('local')->assertMissing($paths['xlsx']);
    }
}
