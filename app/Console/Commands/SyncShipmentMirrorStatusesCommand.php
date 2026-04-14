<?php

namespace App\Console\Commands;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Console\Command;

class SyncShipmentMirrorStatusesCommand extends Command
{
    protected $signature = 'financial:sync-shipment-mirror-statuses {--dry-run : Show changes without applying}';

    protected $description = 'Backfill status of shipment-owned schedule item mirrors based on allocations against their PI/PO counterparts';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $mirrors = PaymentScheduleItem::where('payable_type', Shipment::class)
            ->whereNotNull('shipment_id')
            ->whereNotNull('payment_term_stage_id')
            ->where('is_credit', false)
            ->get();

        $this->info("Found {$mirrors->count()} shipment-owned schedule items to check.");

        $updated = 0;
        foreach ($mirrors as $mirror) {
            if ($mirror->status === PaymentScheduleStatus::WAIVED) {
                continue;
            }

            $paid = $mirror->paid_amount;

            if ($mirror->is_paid_in_full) {
                $newStatus = PaymentScheduleStatus::PAID;
            } elseif ($paid > 0) {
                $newStatus = PaymentScheduleStatus::DUE;
            } else {
                $newStatus = $mirror->status; // Keep original when no payment (could be PENDING/DUE/OVERDUE)
            }

            if ($mirror->status !== $newStatus) {
                $this->line(sprintf(
                    'SH item #%d ("%s"): %s -> %s (paid=%d / %d)',
                    $mirror->id,
                    $mirror->label,
                    $mirror->status->value,
                    $newStatus->value,
                    $paid,
                    $mirror->amount,
                ));

                if (! $dryRun) {
                    $mirror->update(['status' => $newStatus]);
                }
                $updated++;
            }
        }

        if ($dryRun) {
            $this->warn("Dry run: {$updated} mirrors would be updated.");
        } else {
            $this->info("Updated {$updated} shipment mirror statuses.");
        }

        return self::SUCCESS;
    }
}
