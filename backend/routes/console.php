<?php

use App\Services\Payments\RenewSubscriptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Schedule;

Artisan::command('payments:renew', function (RenewSubscriptions $renewals) {
    $renewals->run();
})->purpose('Renew subscriptions with a verified saved YooKassa card');

Artisan::command('providers:prune-events', function () {
    if (! Schema::hasTable('wa_provider_events')) {
        return;
    }
    DB::table('wa_provider_events')->where('created_at', '<', now()->subDays(14))->select('id')
        ->chunkById(500, fn ($rows) => DB::table('wa_provider_events')->whereIn('id', $rows->pluck('id'))->delete());
})->purpose('Remove AI provider diagnostics older than 14 days');

// Run by the "scheduler" container (php artisan schedule:work).
Schedule::command('generations:reap')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('payments:expire')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily();
// New bot models and prompts appear in the app catalog.
Schedule::command('neurogpt:sync-bot')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('payments:renew')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('providers:prune-events')->daily()->withoutOverlapping();
