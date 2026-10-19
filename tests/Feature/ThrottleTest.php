<?php

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Concerns\FakesAuthServer;

/**
 * ObservableThrottleRequests and the limits in AppServiceProvider.
 *
 * The real limits are far too big to walk past in a test, so most cases swap
 * in a tiny limiter under the same name; the last few check the real numbers.
 *
 * Tests\TestCase migrates the MySQL connections; these need two tables, so
 * they build them on the in-memory sqlite connection and run anywhere.
 */
class ThrottleTest extends TestCase
{
    use FakesAuthServer;

    private const PROBE = '/api/tags';

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
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->fakeAuthServer();
    }

    private function tinyApiLimiter(int $real, int $target = 100): void
    {
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute($real)->by('t:'.$request->bearerToken()),
            Limit::perMinute($target)->by('target:t:'.$request->bearerToken()),
        ]);
    }

    public function test_passing_a_target_logs_once_and_blocks_nothing(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 100, target: 2);

        foreach (range(1, 5) as $_) {
            $this->getJson(self::PROBE, $this->headers())->assertOk();
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.usage', Mockery::on(fn ($ctx) => $ctx['limiter'] === 'api' && $ctx['max'] === 2))
            ->once();
    }

    public function test_record_only_mode_lets_a_breach_through_and_logs_it_once(): void
    {
        config(['app.throttle_enforce' => false]);
        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();
        $this->tinyApiLimiter(real: 2);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        foreach (range(1, 3) as $_) {
            $this->getJson(self::PROBE, $this->headers())
                ->assertOk()
                ->assertHeader('X-RateLimit-Remaining', '0');
        }

        Log::shouldHaveReceived('warning')
            ->with('throttle.breach', Mockery::on(fn ($ctx) => $ctx['enforced'] === false))
            ->once();
    }

    public function test_enforce_mode_answers_429_with_retry_after(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 2);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        $this->getJson(self::PROBE, $this->headers())
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_a_throttled_request_never_reaches_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_route_tier_also_runs_before_pizzasys(): void
    {
        config(['app.throttle_enforce' => true]);
        RateLimiter::for('api-poll', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));
        Cache::put('import_progress_abc', ['processed_files' => 1, 'total_files' => 2]);

        $this->getJson('/api/manual-import/progress/abc', $this->headers())->assertOk();
        $sent = count(Http::recorded());

        $this->getJson('/api/manual-import/progress/abc', $this->headers())->assertStatus(429);

        $this->assertCount($sent, Http::recorded());
    }

    public function test_a_poll_route_is_not_capped_by_the_general_limit(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);
        Cache::put('import_progress_abc', ['processed_files' => 1, 'total_files' => 2]);

        foreach (range(1, 3) as $_) {
            $this->getJson('/api/manual-import/progress/abc', $this->headers())->assertOk();
        }

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);
    }

    public function test_the_poll_ip_ceiling_never_shares_a_counter_with_the_token_limit(): void
    {
        $limits = RateLimiter::limiter('api-poll')(Request::create('/manual-import/progress/abc', 'GET', server: ['REMOTE_ADDR' => '10.1.2.3']));

        // No bearer: the token limit falls back to the IP, so the ceiling must not.
        $this->assertSame('ip:10.1.2.3', $limits[0]->key);
        $this->assertNotSame($limits[0]->key, $limits[1]->key);
        $this->assertSame([1200, 5000, 300], array_map(fn (Limit $l) => $l->maxAttempts, $limits));
    }

    public function test_two_tokens_do_not_share_a_bucket(): void
    {
        config(['app.throttle_enforce' => true]);
        $this->tinyApiLimiter(real: 1);

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertStatus(429);

        $this->getJson(self::PROBE, ['Authorization' => 'Bearer 2|other-token', 'Accept' => 'application/json'])
            ->assertOk();
    }

    public function test_an_unreachable_limiter_store_serves_the_request_unthrottled(): void
    {
        config(['app.throttle_enforce' => true]);

        $down = new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('redis down');
            }

            public function increment($key, $value = 1)
            {
                throw new \RuntimeException('redis down');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('redis down');
            }
        };

        $limiter = new CacheRateLimiter(new Repository($down));
        $limiter->for('api', fn (Request $request) => Limit::perMinute(1)->by('t:'.$request->bearerToken()));
        $this->app->instance(CacheRateLimiter::class, $limiter);

        Log::spy();
        Log::shouldReceive('channel')->with('throttle')->andReturnSelf();

        $this->getJson(self::PROBE, $this->headers())->assertOk();
        $this->getJson(self::PROBE, $this->headers())->assertOk();

        Log::shouldHaveReceived('error')->with('throttle.store_unavailable', Mockery::any())->twice();
    }

    public function test_a_secret_key_route_is_throttled_per_secret(): void
    {
        config(['app.throttle_enforce' => true, 'services.x_secret_key' => 'the-secret']);
        RateLimiter::for('service-secret', fn (Request $request) => Limit::perMinute(1)->by((string) $request->header('X-Secret-Key')));

        // Invalid body: answered 422 by the controller, after the secret check.
        $reaggregate = fn (string $secret) => $this->postJson('/manual-import/reaggregate', [], ['X-Secret-Key' => $secret]);

        $reaggregate('the-secret')->assertStatus(422);
        $reaggregate('the-secret')->assertStatus(429);
        $reaggregate('another-secret')->assertStatus(401);
    }

    public function test_a_public_page_is_limited_per_ip_whatever_the_token(): void
    {
        config(['app.throttle_enforce' => true]);

        $from = fn (string $ip, string $token) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('/manual-export', ['Authorization' => 'Bearer '.$token]);

        foreach (range(1, 60) as $i) {
            $from('10.0.0.1', 'token-'.$i)->assertOk();
        }

        $from('10.0.0.1', 'yet-another')->assertStatus(429);
        $from('10.0.0.2', 'token-1')->assertOk();
    }

    public function test_the_real_api_limits_split_reads_from_writes(): void
    {
        $limits = fn (string $method) => collect(RateLimiter::limiter('api')(
            Request::create(self::PROBE, $method, server: ['HTTP_AUTHORIZATION' => 'Bearer 1|test-token'])
        ));

        $real = fn ($all) => $all->reject(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();
        $target = fn ($all) => $all->filter(fn (Limit $l) => str_starts_with($l->key, 'target:'))->pluck('maxAttempts')->all();

        $this->assertSame([600, 5000], $real($limits('GET')));
        $this->assertSame([120, 1200], $target($limits('GET')));
        $this->assertSame([300, 5000], $real($limits('POST')));
        $this->assertSame([60, 1200], $target($limits('POST')));
    }

    public function test_the_multi_store_dashboard_has_its_own_tighter_limits(): void
    {
        $limits = collect(RateLimiter::limiter('api-heavy-multi')(
            Request::create('/api/reports/multi-dashboard', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer 1|test-token'])
        ));

        $this->assertSame([20, 5, 30], $limits->pluck('maxAttempts')->all());
        $this->assertSame([60, 60, 3600], $limits->pluck('decaySeconds')->all());
    }

    public function test_keys_hash_the_token_and_fall_back_to_the_ip(): void
    {
        $key = fn (array $server) => RateLimiter::limiter('api-upload')(Request::create('/x', 'POST', server: $server))[0]->key;

        $a = $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']);

        $this->assertSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|aaa']));
        $this->assertNotSame($a, $key(['HTTP_AUTHORIZATION' => 'Bearer 1|bbb']));
        $this->assertStringNotContainsString('aaa', $a);
        $this->assertSame('ip:10.1.2.3', $key(['REMOTE_ADDR' => '10.1.2.3']));
    }

    public function test_the_secret_key_is_read_like_the_middleware_and_only_hashed(): void
    {
        $key = fn (array $server, array $body = []) => RateLimiter::limiter('service-secret')(
            Request::create('/manual-import/upload', 'POST', $body, server: $server + ['REMOTE_ADDR' => '10.1.2.3'])
        )[0]->key;

        $fromHeader = $key(['HTTP_X_SECRET_KEY' => 'the-secret']);

        $this->assertSame($fromHeader, $key([], ['x_secret_key' => 'the-secret']));
        $this->assertSame($fromHeader, $key([], ['X-Secret-Key' => 'the-secret']));
        $this->assertNotSame($fromHeader, $key(['HTTP_X_SECRET_KEY' => 'other']));
        $this->assertStringNotContainsString('the-secret', $fromHeader);
        $this->assertStringEndsWith('|10.1.2.3', $fromHeader);
    }
}
