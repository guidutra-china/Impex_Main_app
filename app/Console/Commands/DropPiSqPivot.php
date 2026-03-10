<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DropPiSqPivot extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'fix:drop-pi-sq-pivot';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drops the proforma_invoice_supplier_quotation pivot table if it exists';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Schema::dropIfExists('proforma_invoice_supplier_quotation');
        $this->info('Table dropped. Run php artisan migrate to recreate.');
    }
}
