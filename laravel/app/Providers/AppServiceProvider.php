<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

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
        $this->bootDatabaseSafety();

        $this->bootRoute();
    }

    /**
     * Block the artisan commands that drop or empty tables, so a stray
     * migrate:fresh or db:wipe cannot destroy real data. The test suite is
     * exempt because RefreshDatabase needs migrate:fresh, and tests/TestCase.php
     * already guarantees tests only ever run against in-memory sqlite.
     */
    public function bootDatabaseSafety(): void
    {
        $allowed = (bool) config('database.allow_destructive_commands');

        DB::prohibitDestructiveCommands(! $allowed && ! $this->app->runningUnitTests());
    }

    public function bootRoute(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

    }
}
