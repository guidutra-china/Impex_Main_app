<?php

namespace App\Console\Commands;

use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MigrateOrphanShipmentAllocationsCommand extends Command
{
    protected $signature = 'financial:migrate-orphan-shipment-allocations
                            {--dry-run : Show changes without applying}';

    protected $description = 'Re-aim payment allocations from shipment-owned mirror items to their PI/PO canonical counterpart so PI payment schedules reflect paid status.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $orphans = PaymentAllocation::query()
            ->whereHas('scheduleItem', fn ($q) => $q->where('payable_type', Shipment::class))
            ->with(['scheduleItem', 'payment'])
            ->get();

        $this->info("Found {$orphans->count()} allocation(s) on shipment-owned schedule items.");

        $migrated = 0;
        $skippedNoMatch = 0;
        $skippedAdditionalCost = 0;
        $touchedItemIds = collect();

        foreach ($orphans as $alloc) {
            $source = $alloc->scheduleItem;
            if (! $source) {
                continue;
            }

            // AdditionalCost-backed shipment items are legitimately Shipment-owned
            // (forwarder/supplier costs); they have no PI/PO canonical equivalent.
            if ($source->source_type === \App\Domain\Financial\Models\AdditionalCost::class) {
                $skippedAdditionalCost++;

                continue;
            }

            $target = $this->findCanonicalCounterpart($source);

            if (! $target) {
                $skippedNoMatch++;
                $this->warn(sprintf(
                    'Allocation #%d → PSI#%d ("%s"): no PI/PO counterpart found, skipping.',
                    $alloc->id, $source->id, $source->label
                ));

                continue;
            }

            $this->line(sprintf(
                'Allocation #%d (payment #%d, %.2f) : PSI#%d (%s) → PSI#%d (%s)',
                $alloc->id,
                $alloc->payment_id,
                $alloc->allocated_amount_in_document_currency / 10000,
                $source->id,
                "Shipment#{$source->payable_id}",
                $target->id,
                class_basename($target->payable_type).'#'.$target->payable_id
            ));

            if (! $dryRun) {
                DB::transaction(function () use ($alloc, $target) {
                    $alloc->update(['payment_schedule_item_id' => $target->id]);
                });
            }

            $touchedItemIds->push($source->id, $target->id);
            $migrated++;
        }

        if (! $dryRun && $touchedItemIds->isNotEmpty()) {
            $this->info('Recalculating status of '.$touchedItemIds->unique()->count().' affected schedule items...');
            PaymentScheduleItem::query()
                ->whereIn('id', $touchedItemIds->unique())
                ->get()
                ->each
                ->recalculateStatus();
        }

        $this->newLine();
        if ($dryRun) {
            $this->warn("Dry run: {$migrated} allocation(s) would be re-aimed.");
        } else {
            $this->info("Migrated {$migrated} allocation(s).");
        }
        $this->info("Skipped (additional-cost, legit shipment items): {$skippedAdditionalCost}");
        $this->info("Skipped (no PI/PO counterpart found): {$skippedNoMatch}");

        return self::SUCCESS;
    }

    protected function findCanonicalCounterpart(PaymentScheduleItem $source): ?PaymentScheduleItem
    {
        if (! preg_match('#/\s*((?:PI|PO)-[\w-]+)\]#', (string) $source->label, $m)) {
            return null;
        }

        $docRef = $m[1];
        $isPi = str_starts_with($docRef, 'PI');
        $docClass = $isPi ? ProformaInvoice::class : PurchaseOrder::class;

        $docId = $docClass::where('reference', $docRef)->value('id');
        if (! $docId) {
            return null;
        }

        $shipmentId = $source->shipment_id ?? $source->payable_id;

        $query = PaymentScheduleItem::query()
            ->where('payable_type', $docClass)
            ->where('payable_id', $docId)
            ->where('shipment_id', $shipmentId)
            ->where('is_credit', false)
            ->where('id', '!=', $source->id);

        if ($source->payment_term_stage_id) {
            $query->where('payment_term_stage_id', $source->payment_term_stage_id);
        }

        $candidates = $query->get();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        // Multiple candidates → narrow by exact label match (after stripping the doc-ref bracket)
        $cleanLabel = trim(preg_replace('#\s*\x{2014}?\s*\[[^\]]+\]\s*$#u', '', (string) $source->label));
        if ($cleanLabel === '') {
            return null;
        }

        $byLabel = $candidates->first(fn (PaymentScheduleItem $c) => str_starts_with((string) $c->label, $cleanLabel));

        return $byLabel;
    }
}
