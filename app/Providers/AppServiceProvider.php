<?php

namespace App\Providers;

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
    }
}
