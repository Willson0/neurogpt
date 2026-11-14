<?php

namespace App\Providers;

use App\Services\Admin\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        JsonResource::withoutWrapping();
        // The database is shared with the bot: migrate:fresh, migrate:reset, migrate:refresh and db:wipe would drop
        // the bot tables too, so they are refused everywhere except in tests (an in-memory SQLite database).
        DB::prohibitDestructiveCommands(! $this->app->runningUnitTests());
        // Values saved in the admin panel win over config/neurogpt.php and .env.
        Settings::apply();

        $perUser = fn (int $perMinute) => fn (Request $r) => Limit::perMinute($perMinute)->by($r->user()?->id ?: $r->ip());
        $perIp = fn (int $perMinute) => fn (Request $r) => Limit::perMinute($perMinute)->by('ip:'.$r->ip());

        RateLimiter::for('guard', $perIp(600));     // before authentication: stops floods of unsigned requests
        RateLimiter::for('webhook', $perIp(1200));  // Telegram and YooKassa send bursts from a few addresses
        RateLimiter::for('api', $perUser(180));     // the app polls a running generation about once every 2 s
        RateLimiter::for('generate', $perUser(20));
        RateLimiter::for('improve', $perUser(10));
        RateLimiter::for('pay', $perUser(10));
        RateLimiter::for('events', $perUser(60));  // the app sends a batch every few seconds at most
    }
}
