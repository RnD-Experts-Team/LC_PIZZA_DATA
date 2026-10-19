<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * When pizzasys cannot judge a token, the caller gets a 503 - never a 401,
 * which the dashboard treats as "log out".
 *
 * Every answer here comes before the user lookup, so no database is needed:
 * this skips Tests\TestCase, whose RefreshDatabase wants the MySQL connections.
 */
class AuthServerUnavailableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.auth_server.base_url' => 'http://auth.test',
            'services.auth_server.verify_path' => '/api/v1/auth/token-verify',
            'services.auth_server.service_name' => 'LC_PIZZA_DATA',
            'services.auth_server.call_token' => 'service-token',
            'services.auth_server.retries' => 1,
            'services.auth_server.retry_ms' => 0,
        ]);

        Http::preventStrayRequests();
    }

    private function probe()
    {
        return $this->getJson('/api/tags', ['Authorization' => 'Bearer 1|test-token']);
    }

    public function test_a_rate_limited_auth_server_is_a_503_and_is_not_retried(): void
    {
        Http::fake(['auth.test/*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

        $this->probe()->assertStatus(503)->assertHeader('Retry-After', '5');

        Http::assertSentCount(1);
    }

    public function test_a_failing_auth_server_is_a_503(): void
    {
        Http::fake(['auth.test/*' => Http::response('', 500)]);

        $this->probe()->assertStatus(503);
    }

    public function test_an_unreachable_auth_server_is_a_503(): void
    {
        Http::fake(['auth.test/*' => fn () => throw new ConnectionException('refused')]);

        $this->probe()->assertStatus(503);
    }

    public function test_a_token_pizzasys_rejects_is_still_a_401(): void
    {
        Http::fake(['auth.test/*' => Http::response(['active' => false])]);

        $this->probe()->assertStatus(401);
    }
}
