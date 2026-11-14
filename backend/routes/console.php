<?php

use Illuminate\Support\Facades\Schedule;

// Run by the "scheduler" container (php artisan schedule:work).
Schedule::command('generations:reap')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('payments:expire')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily();
// New bot models and prompts appear in the app catalog.
Schedule::command('neurogpt:sync-bot')->everyFiveMinutes()->withoutOverlapping();
