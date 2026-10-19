<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom([
        database_path('migrations'),
        database_path('migrations/operational'),
        database_path('migrations/archive'),
        database_path('migrations/aggregation'),
    ]);
    if (app()->environment('production')) {
        URL::forceScheme('https');
    }

        $this->configureRateLimiting();
    }

    /**
     * Rate limits, applied by ObservableThrottleRequests (the `throttle` alias).
     *
     * Every throttle runs BEFORE auth.token.store - the framework sorts it ahead
     * of the api group's SubstituteBindings - so a throttled request never costs
     * a pizzasys round-trip. That also means no user id exists yet: limits are
     * keyed on a hash of the bearer token, or the IP when there is none.
     *
     * Phase 1: the real limits are generous and the `target:` limits are the
     * numbers we expect to end on - they only log when passed. Phase 2 sets the
     * real limits from those logs and drops the targets.
     */
    private function configureRateLimiting(): void
    {
        // Every route: per token, reads and writes counted apart, plus a
        // per-IP ceiling for floods of made-up tokens (a store's staff share
        // one NAT address, hence the size).
        RateLimiter::for('api', function (Request $request) {
            $token = self::tokenKey($request);
            $kind = $request->isMethodSafe() ? 'r:' : 'w:';
            $ip = 'ip:'.$request->ip();

            return [
                Limit::perMinute($request->isMethodSafe() ? 600 : 300)->by($kind.$token),
                Limit::perMinute(5000)->by($ip),
                Limit::perMinute($request->isMethodSafe() ? 120 : 60)->by('target:'.$kind.$token),
                Limit::perMinute(1200)->by('target:'.$ip),
            ];
        });

        // Replaces `api` on the routes it guards, so it carries its own per-IP
        // ceiling. That key is not "ip:", which is what tokenKey() falls back
        // to without a bearer (the secret-key progress routes in web.php):
        // the two limits would share one counter and halve the token limit.
        RateLimiter::for('api-poll', fn (Request $request) => [
            Limit::perMinute(1200)->by(self::tokenKey($request)),
            Limit::perMinute(5000)->by('ceiling:ip:'.$request->ip()),
            Limit::perMinute(300)->by('target:'.self::tokenKey($request)),
        ]);

        RateLimiter::for('api-heavy', fn (Request $request) => [
            Limit::perMinute(60)->by(self::tokenKey($request)),
            Limit::perMinute(20)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(200)->by('target:h:'.self::tokenKey($request)),
        ]);

        // Up to 100 stores (or all) over up to 366 days in one request.
        RateLimiter::for('api-heavy-multi', fn (Request $request) => [
            Limit::perMinute(20)->by(self::tokenKey($request)),
            Limit::perMinute(5)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(30)->by('target:h:'.self::tokenKey($request)),
        ]);

        RateLimiter::for('api-export', fn (Request $request) => [
            Limit::perMinute(10)->by('m:'.self::tokenKey($request)),
            Limit::perHour(100)->by('h:'.self::tokenKey($request)),
            Limit::perMinute(3)->by('target:m:'.self::tokenKey($request)),
            Limit::perHour(20)->by('target:h:'.self::tokenKey($request)),
            Limit::perDay(60)->by('target:d:'.self::tokenKey($request)),
        ]);

        RateLimiter::for('api-upload', fn (Request $request) => [
            Limit::perMinute(60)->by(self::tokenKey($request)),
            Limit::perMinute(20)->by('target:'.self::tokenKey($request)),
        ]);

        // auth.secret.key routes: per secret and IP. The secret is read the
        // way CheckSecretKeyMiddleware reads it, and only its hash is kept.
        RateLimiter::for('service-secret', function (Request $request) {
            $secret = (string) $request->header('X-Secret-Key', '');
            if ($secret === '') {
                $secret = (string) $request->input('X-Secret-Key', $request->input('x_secret_key', ''));
            }

            $key = 's:'.hash('sha256', $secret).'|'.$request->ip();

            return [
                Limit::perMinute(60)->by($key),
                Limit::perMinute(10)->by('target:m:'.$key),
                Limit::perHour(60)->by('target:h:'.$key),
            ];
        });

        // Unauthenticated pages: per IP.
        RateLimiter::for('api-public', fn (Request $request) => [
            Limit::perMinute(60)->by('ip:'.$request->ip()),
            Limit::perMinute(20)->by('target:m:ip:'.$request->ip()),
            Limit::perHour(100)->by('target:h:ip:'.$request->ip()),
        ]);
    }

    /**
     * The caller's bearer token, hashed - never stored or logged raw - or the
     * IP when there is no token.
     */
    private static function tokenKey(Request $request): string
    {
        $token = (string) $request->bearerToken();

        return $token !== '' ? 't:'.hash('sha256', $token) : 'ip:'.$request->ip();
    }
}
