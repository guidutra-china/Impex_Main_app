<?php

namespace App\Console\Commands;

use App\Domain\Financial\Actions\CleanupDuplicatePaymentScheduleItemsAction;
use Illuminate\Console\Command;

class CleanupDuplicatePaymentScheduleItemsCommand extends Command
{
    protected $signature = 'payment-schedule:cleanup-duplicates
                            {--dry-run : Show what would be deleted without actually deleting}';

    protected $description = 'Remove duplicate payment schedule items created by the regenerate bug';

    public function handle(CleanupDuplicatePaymentScheduleItemsAction $action): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('Running in DRY-RUN mode — no records will be deleted.');
        }

        $this->info('Scanning for duplicate payment schedule items...');

        $result = $action->execute($dryRun);

        if ($result['duplicates_found'] === 0) {
            $this->info('No duplicates found. Database is clean.');

            return self::SUCCESS;
        }

        $this->warn("Found {$result['duplicates_found']} duplicate groups.");

        // Show details table
        $rows = collect($result['details'])->map(fn ($d) => [
            $d['action'],
            $d['item_id'],
            $d['kept_item_id'],
            $d['payable'] ?? '',
            $d['stage_id'] ?? '',
            $d['shipment_id'] ?? '-',
            $d['status'] ?? '',
            isset($d['amount']) ? number_format($d['amount'] / 10000, 2) : '-',
            $d['label'] ?? $d['reason'] ?? '',
        ]);

        $this->table(
            ['Action', 'Item ID', 'Kept ID', 'Payable', 'Stage', 'Shipment', 'Status', 'Amount', 'Label/Reason'],
            $rows
        );

        $this->info("Total items " . ($dryRun ? 'that would be ' : '') . "deleted: {$result['items_deleted']}");

        if ($dryRun) {
            $this->newLine();
            $this->info('To actually delete, run without --dry-run:');
            $this->comment('  php artisan payment-schedule:cleanup-duplicates');
        }

        return self::SUCCESS;
    }
}
