<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's throttle, plus two things the rollout needs. Aliased as `throttle`.
 *
 * - A limit whose key starts with "target:" is never enforced. It counts, and
 *   the first request past it in a window writes one `throttle.usage` line:
 *   that is the record the final numbers are set from.
 * - A real limit that is exceeded writes one `throttle.breach` line per key per
 *   window, and blocks only when app.throttle_enforce is on. Off, the request
 *   goes through with X-RateLimit-Remaining: 0.
 *
 * If the limiter's store (Redis) cannot be reached, the request is served
 * unthrottled: throttling must never be the thing that takes the API down.
 *
 * Everything it writes goes to the `throttle` log channel
 * (storage/logs/throttle.log), not laravel.log.
 *
 * Copied identically into every service. It only overrides the named-limiter
 * path and then hands the real limits to the parent, so it works on every
 * framework version in the fleet.
 */
class ObservableThrottleRequests extends ThrottleRequests
{
    protected function handleRequestUsingNamedLimiter($request, Closure $next, $limiterName, Closure $limiter)
    {
        $limits = $limiter($request);

        if ($limits instanceof Response) {
            return $limits;
        }

        if ($limits instanceof Unlimited) {
            return $next($request);
        }

        [$targets, $real] = Collection::wrap($limits)
            ->partition(fn ($limit) => str_starts_with((string) $limit->key, 'target:'));

        $real = $real->map(fn ($limit) => (object) [
            'key' => md5($limiterName.$limit->key),
            'label' => (string) $limit->key,
            'maxAttempts' => $limit->maxAttempts,
            'decaySeconds' => $limit->decaySeconds,
            'afterCallback' => $limit->afterCallback ?? null,
            'responseCallback' => $limit->responseCallback ?? null,
        ])->values()->all();

        $breached = [];

        try {
            foreach ($targets as $target) {
                $hits = $this->limiter->hit(md5($limiterName.$target->key), $target->decaySeconds);

                if ($hits === $target->maxAttempts + 1) {
                    Log::channel('throttle')->warning('throttle.usage', $this->context($request, $limiterName, $target->key, $target->maxAttempts));
                }
            }

            foreach ($real as $i => $limit) {
                if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                    // Counted separately so a flood logs one line, not one per request.
                    $limit->firstInWindow = $this->limiter->hit($limit->key.':breach', $limit->decaySeconds) === 1;
                    $breached[$i] = $limit;
                }
            }
        } catch (\Throwable $e) {
            Log::channel('throttle')->error('throttle.store_unavailable', ['limiter' => $limiterName, 'error' => $e->getMessage()]);

            return $next($request);
        }

        $enforce = (bool) config('app.throttle_enforce');

        foreach ($breached as $limit) {
            if ($limit->firstInWindow) {
                Log::channel('throttle')->warning('throttle.breach', $this->context($request, $limiterName, $limit->label, $limit->maxAttempts) + ['enforced' => $enforce]);
            }

            if ($enforce) {
                throw $this->buildException($request, $limit->key, $limit->maxAttempts, $limit->responseCallback);
            }
        }

        $response = $this->handleRequest($request, $next, array_values(array_diff_key($real, $breached)));

        foreach ($breached as $limit) {
            $response = $this->addHeaders($response, $limit->maxAttempts, 0);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function context($request, string $limiterName, string $key, int $max): array
    {
        return [
            'limiter' => $limiterName,
            'key' => $key,
            'max' => $max,
            'method' => $request->method(),
            'path' => $request->path(),
            'route' => $request->route()?->getName(),
            'ip' => $request->ip(),
        ];
    }
}
