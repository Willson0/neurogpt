<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;

class ExpirePayments extends Command
{
    protected $signature = 'payments:expire {--hours=24}';

    protected $description = 'Re-check and close payments that were never finished';

    public function handle(PaymentService $payments): int
    {
        $this->info('Closed '.$payments->expireStale((int) $this->option('hours')).' stale payments.');

        return self::SUCCESS;
    }
}
