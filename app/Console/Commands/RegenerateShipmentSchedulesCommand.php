<?php

namespace App\Console\Commands;

use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk repair for the SH-2026-00025 / PO-2026-00005 case: shipments created
 * before regenerateForShipment() covered the PO side never got their PO
 * ship-specific installments — the shipped value stayed locked inside the
 * PO [remaining] rows and never surfaced in Accounts Payable.
 *
 * Runs the same regeneration as the "Regenerar" button on the shipment page
 * (PI side + PO side + additional costs + status reconciliation) across all
 * active shipments, so nobody has to open them one by one.
 *
 * Paid/waived/allocated items always survive regeneration; only pending
 * rows are rebuilt, so re-running is safe and idempotent.
 */
class RegenerateShipmentSchedulesCommand extends Command
{
    protected $signature = 'financial:regenerate-shipment-schedules
        {--dry-run : Run inside a transaction and roll back, reporting what would change}
        {--shipment=* : Limit to specific shipment references or ids}';

    protected $description = 'Regenerate payment schedules (PI and PO side) for all active shipments, as if Regenerar was clicked on each one.';

    public function handle(GeneratePaymentScheduleAction $action): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $query = Shipment::query()
            ->whereNotIn('status', [ShipmentStatus::DRAFT, ShipmentStatus::CANCELLED])
            ->orderBy('id');

        $filters = array_filter((array) $this->option('shipment'));

        if ($filters !== []) {
            $query->where(function ($q) use ($filters) {
                $q->whereIn('reference', $filters)
                    ->orWhereIn('id', array_filter($filters, 'is_numeric'));
            });
        }

        $shipments = $query->get();

        if ($shipments->isEmpty()) {
            $this->warn('No shipments matched.');

            return self::SUCCESS;
        }

        $this->info("Regenerating schedules for {$shipments->count()} shipments...");

        if ($dryRun) {
            DB::beginTransaction();
        }

        $failures = 0;

        foreach ($shipments as $shipment) {
            $before = PaymentScheduleItem::count();

            try {
                $action->regenerateForShipment($shipment);
            } catch (Throwable $e) {
                $failures++;
                $this->error("  {$shipment->reference}: FAILED — {$e->getMessage()}");

                continue;
            }

            $delta = PaymentScheduleItem::count() - $before;
            $deltaLabel = $delta === 0 ? 'no net change' : sprintf('%+d items', $delta);

            $this->line("  {$shipment->reference}: regenerated ({$deltaLabel})");
        }

        if ($dryRun) {
            DB::rollBack();
            $this->warn('Dry run — nothing persisted. Re-run without --dry-run to apply.');
        }

        if ($failures > 0) {
            $this->error("{$failures} shipment(s) failed. See errors above.");

            return self::FAILURE;
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
