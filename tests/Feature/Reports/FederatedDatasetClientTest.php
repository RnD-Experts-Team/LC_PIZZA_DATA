<?php

namespace Tests\Feature\Reports;

use App\Reports\Engine\FederatedDatasetClient;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\AnswersReportStreams;

/**
 * The client against a faked service: how each answer is mapped, what is
 * retried, where the request goes and how far it may run.
 */
class FederatedDatasetClientTest extends TestCase
{
    use AnswersReportStreams;

    private const URL = 'http://data.test/api/report-datasets/data.daily_sales/query';

    private array $dataset = ['key' => 'data.daily_sales', 'service' => 'data', 'path' => '/report-datasets/data.daily_sales/query'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.report_datasets.urls' => ['data' => 'http://data.test/api']]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function page(array $overrides = []): array
    {
        return array_replace([
            'columns' => [['key' => 'store', 'type' => 'string']],
            'rows' => [['03795-00001']],
            'next_cursor' => null,
            'truncated' => false,
            'meta' => ['row_count' => 1, 'elapsed_ms' => 1, 'contract_version' => 1],
        ], $overrides);
    }

    private function query(?float $deadline = null, int $maxRows = 200, ?array $dataset = null): array
    {
        return app(FederatedDatasetClient::class)->query($dataset ?? $this->dataset, ['stores' => []], '1|token', $deadline ?? microtime(true) + 15, $maxRows);
    }

    private function thrown(callable $call): HttpException
    {
        try {
            $call();
        } catch (HttpException $e) {
            return $e;
        }

        $this->fail('Nothing was thrown.');
    }

    public function test_it_posts_to_the_path_it_builds_from_the_key_not_the_one_the_catalog_gives(): void
    {
        Http::fake(['data.test/*' => Http::response($this->page())]);

        $result = $this->query(dataset: [...$this->dataset, 'path' => 'http://evil.test/steal?x=1']);

        $this->assertSame([['03795-00001']], $result['rows']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request->hasHeader('Authorization', 'Bearer 1|token')
            && ! isset($request['timeout_ms']));
    }

    public function test_a_key_with_path_characters_stays_one_path_segment(): void
    {
        Http::fake(['data.test/*' => Http::response($this->page())]);

        $this->query(dataset: [...$this->dataset, 'key' => '../auth/x']);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://data.test/api/report-datasets/..%2Fauth%2Fx/query');
    }

    public function test_a_401_is_a_502_so_the_user_is_not_logged_out(): void
    {
        Http::fake(['data.test/*' => Http::response(['message' => 'Unauthenticated, token abc'], 401)]);

        $e = $this->thrown(fn () => $this->query());

        $this->assertSame(502, $e->getStatusCode());
        $this->assertSame('data (data.daily_sales) could not answer.', $e->getMessage());
        // The answer rides along, for a scheduled run to see its token was refused.
        $this->assertInstanceOf(RequestException::class, $e->getPrevious());
        $this->assertSame(401, $e->getPrevious()->response->status());
    }

    public static function failures(): array
    {
        return ['500' => [500, 502], '502' => [502, 502], '504' => [504, 504], '418' => [418, 502]];
    }

    #[DataProvider('failures')]
    public function test_the_message_of_a_failing_service_is_never_passed_on(int $status, int $mapped): void
    {
        Http::fake(['data.test/*' => Http::response(['message' => 'SQLSTATE secret'], $status)]);

        $e = $this->thrown(fn () => $this->query());

        $this->assertSame($mapped, $e->getStatusCode());
        $this->assertStringNotContainsString('secret', $e->getMessage());
        $this->assertStringContainsString('data (data.daily_sales)', $e->getMessage());
    }

    public function test_a_403_is_passed_on_with_its_own_wording(): void
    {
        Http::fake(['data.test/*' => Http::response(['message' => 'You may not see store 03795-00009.'], 403)]);

        $e = $this->thrown(fn () => $this->query());

        $this->assertSame(403, $e->getStatusCode());
        $this->assertSame('data.daily_sales: You may not see store 03795-00009.', $e->getMessage());
    }

    public function test_a_422_names_the_dataset_in_each_field(): void
    {
        Http::fake(['data.test/*' => Http::response(['message' => 'x', 'errors' => ['filters.0.value' => ['Send one value.']]], 422)]);

        try {
            $this->query();
            $this->fail('Nothing was thrown.');
        } catch (ValidationException $e) {
            $this->assertSame(['data.daily_sales.filters.0.value' => ['Send one value.']], $e->errors());
        }
    }

    public function test_a_503_is_retried_after_its_retry_after_and_then_answers(): void
    {
        Http::fake(['data.test/*' => Http::sequence()
            ->push(['message' => 'busy'], 503, ['Retry-After' => '2'])
            ->push(['message' => 'busy'], 429, ['Retry-After' => '30'])
            ->push($this->page())]);

        $this->assertSame([['03795-00001']], $this->query()['rows']);

        Http::assertSentCount(3);
        // The second wait is cut to 10 s; a missing header would be 1 s.
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(10)->seconds()]);
    }

    public function test_a_429_is_retried_three_times_then_passed_on_with_its_retry_after(): void
    {
        Http::fake(['data.test/*' => Http::response(['message' => 'Too Many Attempts. internal'], 429, ['Retry-After' => '7'])]);

        $e = $this->thrown(fn () => $this->query());

        // The first try and three retries, each after the Retry-After.
        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
        Sleep::assertSequence([Sleep::for(7)->seconds(), Sleep::for(7)->seconds(), Sleep::for(7)->seconds()]);
        $this->assertSame(429, $e->getStatusCode());
        $this->assertSame('7', $e->getHeaders()['Retry-After']);
        $this->assertSame('data (data.daily_sales) is busy; try again shortly.', $e->getMessage());
    }

    public function test_a_503_that_never_clears_is_a_502(): void
    {
        Http::fake(['data.test/*' => Http::response(['message' => 'down for maintenance'], 503)]);

        $e = $this->thrown(fn () => $this->query());

        Http::assertSentCount(4);
        Sleep::assertSleptTimes(3);
        $this->assertSame(502, $e->getStatusCode());
        $this->assertSame('data (data.daily_sales) could not answer.', $e->getMessage());
    }

    public function test_a_service_on_another_contract_version_is_a_502(): void
    {
        Http::fake(['data.test/*' => Http::response($this->page(['meta' => ['row_count' => 1, 'contract_version' => 2]]))]);

        $e = $this->thrown(fn () => $this->query());

        $this->assertSame(502, $e->getStatusCode());
        $this->assertSame('data answered data.daily_sales in report contract v2, Data speaks v1.', $e->getMessage());
    }

    public function test_the_row_cap_limits_the_page_and_says_when_it_cut(): void
    {
        Http::fake(['data.test/*' => Http::response($this->page(['rows' => [['a'], ['b']], 'next_cursor' => 'more']))]);

        $result = $this->query(maxRows: 2);

        $this->assertSame([['a'], ['b']], $result['rows']);
        $this->assertTrue($result['truncated']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['limit'] === 2 && $request['cursor'] === null);
    }

    public function test_it_follows_the_cursor_until_the_service_is_done(): void
    {
        Http::fake(['data.test/*' => Http::sequence()
            ->push($this->page(['rows' => [['a']], 'next_cursor' => 'c2']))
            ->push($this->page(['rows' => [['b']]]))]);

        $result = $this->query(maxRows: 10);

        $this->assertSame([['a'], ['b']], $result['rows']);
        $this->assertFalse($result['truncated']);
        Http::assertSent(fn (Request $request) => $request['cursor'] === 'c2' && $request['limit'] === 9);
    }

    public function test_nothing_is_sent_once_the_deadline_has_passed(): void
    {
        Http::fake(['data.test/*' => Http::response($this->page())]);

        $e = $this->thrown(fn () => $this->query(deadline: microtime(true) - 1));

        $this->assertSame(504, $e->getStatusCode());
        $this->assertSame('The report ran out of time.', $e->getMessage());
        Http::assertNothingSent();
    }

    /** stream() with its rows gathered. */
    private function stream(array $body = ['stores' => []]): array
    {
        $rows = [];
        $count = app(FederatedDatasetClient::class)->stream($this->dataset, $body, '1|token', function (array $row) use (&$rows) {
            $rows[] = $row;
        });
        $this->assertSame(count($rows), $count);

        return $rows;
    }

    public function test_a_stream_hands_on_each_row_by_its_column_keys(): void
    {
        Http::fake(['data.test/*' => $this->ndjson(['store', 'c1'], [['03795-00001', 1.5], ['03795-00002', null]])]);

        $this->assertSame([['store' => '03795-00001', 'c1' => 1.5], ['store' => '03795-00002', 'c1' => null]], $this->stream());
        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request['stream'] === true
            && $request->hasHeader('Accept', 'application/x-ndjson')
            && ! isset($request['cursor']) && ! isset($request['limit']));
    }

    public function test_a_stream_reads_a_body_split_anywhere_and_a_last_line_without_its_newline(): void
    {
        $body = '{"columns":[{"key":"store","type":"string"}]}'."\n".'["a"]'."\n".'["b"]'."\n".'{"done":true,"row_count":2}';
        Http::fake(['data.test/*' => Http::response($body)]);

        $this->assertSame([['store' => 'a'], ['store' => 'b']], $this->stream());
    }

    public static function brokenStreams(): array
    {
        return [
            'no done line' => ['', 'data (data.daily_sales) stopped before it sent every row.', 502],
            'an error line' => ['{"error":"The dataset query failed before it sent every row."}'."\n", 'data (data.daily_sales) failed part-way through its rows.', 502],
            'a wrong count' => ['{"done":true,"row_count":5}'."\n", 'data (data.daily_sales) said it sent 5 rows but sent 1.', 502],
            'not json' => ["<html>\n", 'data (data.daily_sales) sent rows that are not NDJSON.', 502],
            'a row of another width' => ['["a","b","c"]'."\n", 'data (data.daily_sales) sent a row that does not match its columns.', 502],
        ];
    }

    #[DataProvider('brokenStreams')]
    public function test_a_stream_that_does_not_end_well_fails(string $end, string $message, int $status): void
    {
        Http::fake(['data.test/*' => $this->ndjson(['store', 'c1'], [['03795-00001', 1]], $end)]);

        $e = $this->thrown(fn () => $this->stream());

        $this->assertSame($status, $e->getStatusCode());
        $this->assertSame($message, $e->getMessage());
    }

    public function test_a_stream_without_its_columns_line_fails(): void
    {
        Http::fake(['data.test/*' => Http::response('["a"]'."\n".'{"done":true,"row_count":1}'."\n")]);

        $this->assertSame('data (data.daily_sales) sent rows without their columns.', $this->thrown(fn () => $this->stream())->getMessage());
    }

    public function test_a_stream_is_retried_on_429_and_503_before_its_first_row(): void
    {
        Http::fake(['data.test/*' => Http::sequence()
            ->push(['message' => 'busy'], 503, ['Retry-After' => '2'])
            ->push(['message' => 'busy'], 429, ['Retry-After' => '3'])
            ->pushResponse($this->ndjson(['store'], [['a']]))]);

        $this->assertSame([['store' => 'a']], $this->stream());
        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(3)->seconds()]);
    }

    public function test_a_stream_maps_failures_as_a_page_does(): void
    {
        Http::fake(['data.test/*' => Http::sequence()
            ->push(['message' => 'You may not see store 03795-00009.'], 403)
            ->push(['message' => 'Unauthenticated'], 401)]);

        $this->assertSame('data.daily_sales: You may not see store 03795-00009.', $this->thrown(fn () => $this->stream())->getMessage());
        $e = $this->thrown(fn () => $this->stream());
        $this->assertSame(502, $e->getStatusCode());
        $this->assertSame(401, $e->getPrevious()->response->status());
    }

    public function test_store_by_store_asks_every_store_five_at_a_time_and_skips_the_refused_ones(): void
    {
        $stores = array_map(fn (int $i) => sprintf('03795-%05d', $i), range(1, 12));
        $busy = [];
        Http::fake(['data.test/*' => function (Request $request) use (&$busy) {
            $store = $request['stores'][0];
            $n = (int) substr($store, -5);
            if ($n === 7 && ! isset($busy[$store])) {
                $busy[$store] = true;

                return Http::response(['message' => 'busy'], 503, ['Retry-After' => '1']);
            }

            return $n % 3 === 0 ? Http::response(['message' => 'Forbidden'], 403) : $this->ndjson(['store'], [[$store]]);
        }]);

        $rows = [];
        $skipped = app(FederatedDatasetClient::class)->streamByStore($this->dataset, ['stores' => $stores], $stores, '1|token', function (array $row) use (&$rows) {
            $rows[] = $row['store'];
        });

        $this->assertSame(['03795-00003', '03795-00006', '03795-00009', '03795-00012'], $skipped);
        $this->assertSame(array_values(array_diff($stores, $skipped)), $rows);
        // One request per store, and store 7 once more after its 503.
        Http::assertSentCount(13);
        Http::assertSent(fn (Request $request) => $request['stores'] === ['03795-00012'] && $request['stream'] === true);
    }

    public function test_a_retry_that_would_end_after_the_deadline_is_not_waited_for(): void
    {
        Http::fake(['data.test/*' => Http::response(['message' => 'busy'], 503, ['Retry-After' => '10'])]);

        $e = $this->thrown(fn () => $this->query(deadline: microtime(true) + 5));

        $this->assertSame(504, $e->getStatusCode());
        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }
}
