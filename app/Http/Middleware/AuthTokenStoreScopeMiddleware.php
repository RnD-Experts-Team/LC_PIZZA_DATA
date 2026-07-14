<?php

namespace App\Http\Middleware;

use App\Models\Employee;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

class AuthTokenStoreScopeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1) Extract USER bearer token (what we are verifying)
        $userToken = $this->extractBearerToken($request);
        if ($userToken === '') {
            abort(401, 'Missing Bearer token');
        }

        // 2) Read auth server config from config/services.php
        $cfg = (array) config('services.auth_server', []);

        $baseUrl = (string) ($cfg['base_url'] ?? '');
        $verifyPath = (string) ($cfg['verify_path'] ?? '');
        $serviceName = (string) ($cfg['service_name'] ?? '');
        $callToken = (string) ($cfg['call_token'] ?? '');

        $timeout = (int) ($cfg['timeout'] ?? 3);
        $retries = (int) ($cfg['retries'] ?? 1);
        $retryMs = (int) ($cfg['retry_ms'] ?? 100);
        $cacheTtl = (int) ($cfg['cache_ttl'] ?? 30);

        if ($baseUrl === '' || $verifyPath === '' || $serviceName === '' || $callToken === '') {
            abort(500, 'Auth server config missing: services.auth_server.*');
        }

        // 3) Build store_context EXACTLY as TokenVerifyController expects
        $storeContext = $this->buildStoreContext($request);

        // // 4) Redis cache: key includes token+route+method+store_context signature
        // $cache = Cache::store('redis');
        // $cacheKey = $this->verifyCacheKey($serviceName, $userToken, $request, $storeContext);

        $verify =
            // $cache->remember($cacheKey, $cacheTtl, function () use (
            //     $baseUrl,
            //     $verifyPath,
            //     $serviceName,
            //     $callToken,
            //     $timeout,
            //     $retries,
            //     $retryMs,
            //     $userToken,
            //     $request,
            //     $storeContext
            // ) {
            //     return 
            $this->verifyWithAuthServer(
                $baseUrl,
                $verifyPath,
                $serviceName,
                $callToken,
                $timeout,
                $retries,
                $retryMs,
                $userToken,
                $request,
                $storeContext
            );
        // });

        // 5) Enforce BOTH token validity + authorization decision
        $active = (bool) ($verify['active'] ?? false);
        $authorized = (bool) data_get($verify, 'ext.authorized', false);

        if (!$active) {
            abort(401, 'Unauthorized');
        }

        if (!$authorized) {
            // Optional: surface required permissions for debugging
            // $required = (array) data_get($verify, 'ext.required_permissions', []);
            abort(403, 'Forbidden');
        }

        $userId = (int) data_get($verify, 'user.id', 0);
        if ($userId <= 0) {
            abort(401, 'Unauthorized: missing user id');
        }

        // 6) Who does this token belong to? Auth-system users and hiring-system
        // employees live in DIFFERENT id spaces — user.id from the verify
        // response is an employee id when subject_type=employee, and must never
        // be looked up in the users table (ids can collide). Older auth-server
        // responses don't send subject_type → default to 'user'.
        $subjectType = (string) (
            $verify['subject_type']
            ?? data_get($verify, 'ext.subject_type')
            ?? 'user'
        );

        // Expose roles/perms/ext to downstream middlewares/controllers
        $request->attributes->set('authz_subject_type', $subjectType);
        $request->attributes->set('authz_roles', (array) ($verify['roles'] ?? []));
        $request->attributes->set('authz_permissions', (array) ($verify['permissions'] ?? []));
        $request->attributes->set('authz_ext', (array) ($verify['ext'] ?? []));

        if ($subjectType === 'employee') {
            // Employees are replicated from hiring.v1.employee.* events into
            // the local employees table (same hiring id).
            $employee = Employee::query()->find($userId);
            if (!$employee) {
                abort(401, 'Unauthorized: employee not synced yet');
            }

            if (!$employee->active) {
                abort(401, 'Unauthorized: employee inactive');
            }

            // Employee is NOT an Authenticatable here — no Auth::login().
            // Employee-accessible controllers must read these attributes
            // instead of Auth::user().
            $request->attributes->set('authz_employee_id', (int) $employee->id);
            $request->attributes->set('authz_employee', $employee);

            return $next($request);
        }

        // 7) DO NOT REPLICATE USERS HERE.
        $user = User::query()->find($userId);
        if (!$user) {
            abort(401, 'Unauthorized: user not synced yet');
        }

        // 8) Login for session-based parts of this app
        Auth::login($user);

        return $next($request);
    }

    private function extractBearerToken(Request $request): string
    {
        $h = (string) $request->header('Authorization', '');
        if ($h === '')
            return '';

        if (stripos($h, 'Bearer ') === 0) {
            return trim(substr($h, 7));
        }

        return '';
    }

    /**
     * TokenVerifyController expects:
     * store_context: { path: {}, query: {}, body: {} }
     *
     * - path: route params (normalized: objects -> id)
     * - query: query string
     * - body: json/form body (keep small)
     */
    private function buildStoreContext(Request $request): array
    {
        $rawPath = (array) ($request->route()?->parameters() ?? []);
        $path = $this->normalizeRouteParams($rawPath);

        $query = (array) ($request->query->all() ?? []);

        $body = [];
        if ($request->isJson()) {
            $body = (array) ($request->json()->all() ?? []);
        } elseif (!$request->isMethod('GET')) {
            $body = (array) ($request->except(['entities', 'file', 'files']) ?? []);
        }

        // Add any request headers you want authz to inspect here
        $headerKeys = [
            'X-Store-Id',
            'X-Store-Ids',
            'X-StoreId',
            'X-StoreIds',
            'store_id',
            'store_ids',
            'storeId',
            'storeIds',
            'store',
        ];

        $headers = [];
        foreach ($headerKeys as $key) {
            $value = $request->header($key);

            if ($value !== null && $value !== '') {
                $headers[$key] = $value;
            }
        }

        $ctx = [
            'path' => $path,
            'query' => $query,
            'body' => $body,
            'header' => $headers,
        ];

        $this->ksortRecursive($ctx);

        return $ctx;
    }
    private function normalizeRouteParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            // Route model binding: keep it small + deterministic
            if (is_object($v) && isset($v->id)) {
                $out[$k] = (int) $v->id;
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private function ksortRecursive(array &$arr): void
    {
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
        unset($v);
        ksort($arr);
    }

    private function verifyCacheKey(string $serviceName, string $userToken, Request $request, array $storeContext): string
    {
        $tokenHash = hash('sha256', $userToken);

        $method = strtoupper((string) $request->method());
        $path = '/' . ltrim((string) $request->path(), '/');
        $routeName = (string) ($request->route()?->getName() ?? '');

        $ctxJson = json_encode($storeContext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $ctxSig = hash('sha256', $ctxJson);

        return 'qa:authz:verify:' . hash('sha256', $serviceName . '|' . $tokenHash . '|' . $method . '|' . $path . '|' . $routeName . '|' . $ctxSig);
    }

    /**
     * Calls Auth Server TokenVerifyController endpoint.
     * - Authorization header MUST be the SERVICE call token
     * - user token is sent in JSON body as "token"
     */
    private function verifyWithAuthServer(
        string $baseUrl,
        string $verifyPath,
        string $serviceName,
        string $callToken,
        int $timeout,
        int $retries,
        int $retryMs,
        string $userToken,
        Request $request,
        array $storeContext
    ): array {
        $endpoint = rtrim($baseUrl, '/') . '/' . ltrim($verifyPath, '/');

        $payload = [
            'service' => $serviceName,
            'token' => $userToken,
            'method' => strtoupper((string) $request->method()),
            'path' => '/' . ltrim((string) $request->path(), '/'),
            'route_name' => (string) ($request->route()?->getName() ?? null),
            'store_context' => $storeContext,
        ];

        try {
            $http = Http::timeout($timeout)
                ->acceptJson()
                ->withToken($callToken);

            if ($retries > 0) {
                $http = $http->retry($retries, $retryMs);
            }

            $resp = $http->post($endpoint, $payload);

            if (!$resp->ok()) {
                return ['active' => false];
            }

            $data = $resp->json();
            return is_array($data) ? $data : ['active' => false];
        } catch (\Throwable $e) {
            return ['active' => false];
        }
    }
}
