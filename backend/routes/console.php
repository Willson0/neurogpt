<?php

use App\Services\Payments\RenewSubscriptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('payments:renew', function (RenewSubscriptions $renewals) {
    $renewals->run();
})->purpose('Renew subscriptions with a verified saved YooKassa card');

// Run by the "scheduler" container (php artisan schedule:work).
Schedule::command('generations:reap')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('payments:expire')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily();
// New bot models and prompts appear in the app catalog.
Schedule::command('neurogpt:sync-bot')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('payments:renew')->everyFiveMinutes()->withoutOverlapping();
