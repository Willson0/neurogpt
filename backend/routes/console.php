<?php

use Illuminate\Support\Facades\Schedule;

// Run by the "scheduler" container (php artisan schedule:work).
Schedule::command('generations:reap')->everyTwoMinutes()->withoutOverlapping();
Schedule::command('payments:expire')->hourly()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=168')->daily();
