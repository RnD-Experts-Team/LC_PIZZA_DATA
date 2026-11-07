<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | The proxies whose X-Forwarded-For / -Proto / -Host / -Port headers are
    | believed (TRUSTED_PROXIES: comma-separated IPs or CIDRs). Only then does
    | $request->ip() - and every rate limit and log keyed on it - see the real
    | client instead of the last hop. Empty = trust none.
    |
    | Laravel's TrustProxies middleware reads this key on each request. It is
    | not passed to trustProxies(at:) in bootstrap/app.php because .env is not
    | loaded yet when that file runs; the headers are set there.
    |
    | '*' trusts whoever connects, so any caller could forge its IP: never use it.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
