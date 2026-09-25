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
     * Read limiter for the Dough & Sauce plan endpoint.
     *
     * Named so it applies to that route only. Nothing else in this project is
     * throttled today, and quietly capping the import or export paths could break
     * a scheduled job that has always been allowed to run flat out.
     *
     * The plan endpoint is different because a browser calls it: 44 store screens
     * can be open at once, and each request also triggers a synchronous token
     * check against the auth server, so the load lands on two services. 120 a
     * minute per user is far above a screen changing day or week, and still caps a
     * client stuck in a refresh loop.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('dough-sauce', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
