<?php

namespace App\Console\Commands;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;

class BackfillPiWideShipmentIdCommand extends Command
{
    protected $signature = 'financial:backfill-piwide-shipment-id
                            {--dry-run : Show changes without applying}';

    protected $description = 'Link PI/PO-wide schedule items (shipment_id=null) to their shipment when there is exactly one shipment associated, so shipment-owned mirrors can sync paid status.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Shipment-owned mirrors with no allocations whose label points to a PI/PO
        $orphans = PaymentScheduleItem::query()
            ->where('payable_type', Shipment::class)
            ->where('is_credit', false)
            ->whereNull('source_type')
            ->where('status', '!=', 'paid')
            ->whereDoesntHave('allocations')
            ->where(function ($q) {
                $q->where('label', 'LIKE', '%/%PI-%]')
                    ->orWhere('label', 'LIKE', '%/%PO-%]');
            })
            ->get();

        $this->info("Inspecting {$orphans->count()} shipment-owned mirror(s) without allocations.");

        $applicable = [];
        $alreadyLinked = [];
        $multiShipment = [];
        $amountMismatch = [];
        $noPiWide = [];
        $noDocRef = [];

        foreach ($orphans as $mirror) {
            if (! preg_match('#/\s*((?:PI|PO)-[\w-]+)\]#', (string) $mirror->label, $m)) {
                $noDocRef[] = $mirror;

                continue;
            }

            $docRef = $m[1];
            $isPi = str_starts_with($docRef, 'PI');
            $docClass = $isPi ? ProformaInvoice::class : PurchaseOrder::class;
            $docId = $docClass::where('reference', $docRef)->value('id');
            if (! $docId) {
                $noDocRef[] = $mirror;

                continue;
            }

            // If a shipment-specific canonical already exists for this mirror's
            // shipment+stage, no backfill is needed — getMirrorPaidAmount() will
            // sync via that canonical when payments are allocated. This is the
            // normal case for split PIs and should not be flagged for action.
            $shipSpecific = PaymentScheduleItem::where('payable_type', $docClass)
                ->where('payable_id', $docId)
                ->where('shipment_id', $mirror->payable_id)
                ->where('payment_term_stage_id', $mirror->payment_term_stage_id)
                ->first();

            if ($shipSpecific) {
                $alreadyLinked[] = compact('mirror', 'shipSpecific');

                continue;
            }

            // PI-wide candidate: same doc, same stage, shipment_id=null
            $piWide = PaymentScheduleItem::where('payable_type', $docClass)
                ->where('payable_id', $docId)
                ->whereNull('shipment_id')
                ->where('payment_term_stage_id', $mirror->payment_term_stage_id)
                ->first();

            if (! $piWide) {
                $noPiWide[] = $mirror;

                continue;
            }

            $shipmentCount = PaymentScheduleItem::where('payable_type', $docClass)
                ->where('payable_id', $docId)
                ->whereNotNull('shipment_id')
                ->distinct()
                ->count('shipment_id');

            // Backfill is safe only when this is the doc's single shipment
            // and the amounts match (mirror = PI-wide).
            if ($shipmentCount > 1) {
                $multiShipment[] = compact('mirror', 'piWide', 'shipmentCount');

                continue;
            }

            if ($piWide->amount !== $mirror->amount) {
                $amountMismatch[] = compact('mirror', 'piWide');

                continue;
            }

            $applicable[] = compact('mirror', 'piWide');
        }

        $this->newLine();
        $this->info('Applicable backfills (PI-wide → shipment_id):');
        foreach ($applicable as $row) {
            $this->line(sprintf(
                '  PSI#%d ("%s") shipment_id: null → %d  (mirror PSI#%d will sync to paid=%.2f)',
                $row['piWide']->id,
                $row['piWide']->label,
                $row['mirror']->payable_id,
                $row['mirror']->id,
                $row['piWide']->paid_amount / 10000
            ));
        }
        $this->info('  Total: '.count($applicable));

        if (! empty($multiShipment)) {
            $this->newLine();
            $this->warn('Skipped (PI/PO has multiple shipments — manual decision needed):');
            foreach ($multiShipment as $row) {
                $this->line(sprintf(
                    '  Mirror PSI#%d (SH#%d, %s) — PI-wide PSI#%d covers %d shipments',
                    $row['mirror']->id,
                    $row['mirror']->payable_id,
                    $row['mirror']->label,
                    $row['piWide']->id,
                    $row['shipmentCount']
                ));
            }
        }

        if (! empty($amountMismatch)) {
            $this->newLine();
            $this->warn('Skipped (amount mismatch between mirror and PI-wide):');
            foreach ($amountMismatch as $row) {
                $this->line(sprintf(
                    '  Mirror PSI#%d amount=%.2f vs PI-wide PSI#%d amount=%.2f',
                    $row['mirror']->id, $row['mirror']->amount / 10000,
                    $row['piWide']->id, $row['piWide']->amount / 10000,
                ));
            }
        }

        if (! empty($noPiWide)) {
            $this->newLine();
            $this->warn('Skipped (no PI-wide counterpart at same stage): '.count($noPiWide));
        }
        if (! empty($noDocRef)) {
            $this->newLine();
            $this->warn('Skipped (label has no parseable doc ref): '.count($noDocRef));
        }
        if (! empty($alreadyLinked)) {
            $this->newLine();
            $this->info('No-op (shipment-specific canonical already exists; mirror will sync once paid): '.count($alreadyLinked));
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run: '.count($applicable).' backfill(s) would be applied. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        if (empty($applicable)) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Applying backfills...');
        foreach ($applicable as $row) {
            $row['piWide']->update(['shipment_id' => $row['mirror']->payable_id]);
            $row['mirror']->recalculateStatus();
        }

        $this->info('Done. '.count($applicable).' PI/PO item(s) linked to shipment, mirrors recalculated.');

        return self::SUCCESS;
    }
}
