<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

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
        JsonResource::withoutWrapping();

        $perUser = fn (int $perMinute) => fn (Request $r) => Limit::perMinute($perMinute)->by($r->user()?->id ?: $r->ip());
        RateLimiter::for('api', $perUser(120));
        RateLimiter::for('generate', $perUser(30));
        RateLimiter::for('pay', $perUser(10));
    }
}
