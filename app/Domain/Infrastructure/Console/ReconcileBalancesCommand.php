<?php

namespace App\Domain\Infrastructure\Console;

use Illuminate\Console\Command;

class ReconcileBalancesCommand extends Command
{
    protected $signature = 'finance:reconcile-balances';
    protected $description = 'Reconcile payment balances (placeholder)';

    public function handle(): int
    {
        $this->info('Not yet implemented.');
        return self::SUCCESS;
    }
}
