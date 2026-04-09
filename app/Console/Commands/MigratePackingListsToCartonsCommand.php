<?php

namespace App\Console\Commands;

use App\Domain\Logistics\Actions\MigratePackingListItemsToCartonsAction;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Console\Command;

class MigratePackingListsToCartonsCommand extends Command
{
    protected $signature = 'packing-list:migrate-to-cartons
                            {--shipment= : Migrate only one shipment by reference}
                            {--dry-run : Print plan without writing}';

    protected $description = 'One-shot migration: convert packing_list_items to cartons + carton_contents';

    public function handle(MigratePackingListItemsToCartonsAction $action): int
    {
        $query = Shipment::query()->whereHas('packingListItems');

        if ($ref = $this->option('shipment')) {
            $query->where('reference', $ref);
        }

        $shipments = $query->get();

        if ($shipments->isEmpty()) {
            $this->info('No shipments with packing_list_items found.');

            return self::SUCCESS;
        }

        $this->info("Found {$shipments->count()} shipment(s) to migrate.");

        if ($this->option('dry-run')) {
            foreach ($shipments as $shipment) {
                $itemCount = $shipment->packingListItems()->count();
                $this->line("  - {$shipment->reference}: {$itemCount} legacy items");
            }
            $this->warn('Dry run: no changes written.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($shipments->count());
        $bar->start();

        $errors = [];
        foreach ($shipments as $shipment) {
            try {
                $action->execute($shipment);
            } catch (\Throwable $e) {
                $errors[] = "{$shipment->reference}: {$e->getMessage()}";
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($errors) {
            $this->error('Errors:');
            foreach ($errors as $err) {
                $this->line('  - '.$err);
            }

            return self::FAILURE;
        }

        $this->info('Migration completed successfully.');

        return self::SUCCESS;
    }
}
