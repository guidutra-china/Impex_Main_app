<?php

namespace App\Console\Commands;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PromoteRemainingToShipmentCommand extends Command
{
    protected $signature = 'financial:promote-remaining-to-shipment
                            {--dry-run : Show changes without applying}
                            {--document= : Limit to a single document reference (e.g. PO-2026-00009 or PI-2026-00014)}';

    protected $description = 'Detecta canonicals [remaining] (shipment_id=null) que receberam alocação ANTES do shipment ser criado e estão duplicados com um ship-specific vazio. Promove o [remaining] a ship-specific (preservando ID/alocação/histórico) e remove o duplicado vazio.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $docFilter = $this->option('document');

        $candidates = PaymentScheduleItem::query()
            ->whereIn('payable_type', [ProformaInvoice::class, PurchaseOrder::class])
            ->whereNull('shipment_id')
            ->where('label', 'LIKE', '%[remaining]%')
            ->where(function ($q) {
                $q->whereHas('allocations')
                    ->orWhere('status', 'paid');
            })
            ->get();

        $applicable = [];
        $multiShipment = [];
        $noShipSpecific = [];
        $shipSpecificHasAllocations = [];

        foreach ($candidates as $remaining) {
            $docRef = $remaining->payable?->reference;
            if ($docFilter && $docRef !== $docFilter) {
                continue;
            }

            $shipmentIds = PaymentScheduleItem::where('payable_type', $remaining->payable_type)
                ->where('payable_id', $remaining->payable_id)
                ->whereNotNull('shipment_id')
                ->where('payment_term_stage_id', $remaining->payment_term_stage_id)
                ->pluck('shipment_id')
                ->unique()
                ->values();

            if ($shipmentIds->isEmpty()) {
                $noShipSpecific[] = $remaining;

                continue;
            }

            if ($shipmentIds->count() > 1) {
                $multiShipment[] = ['remaining' => $remaining, 'shipmentIds' => $shipmentIds];

                continue;
            }

            $shipId = $shipmentIds->first();

            $duplicate = PaymentScheduleItem::where('payable_type', $remaining->payable_type)
                ->where('payable_id', $remaining->payable_id)
                ->where('shipment_id', $shipId)
                ->where('payment_term_stage_id', $remaining->payment_term_stage_id)
                ->where('id', '!=', $remaining->id)
                ->first();

            if (! $duplicate) {
                $noShipSpecific[] = $remaining;

                continue;
            }

            if ($duplicate->allocations()->exists()) {
                $shipSpecificHasAllocations[] = ['remaining' => $remaining, 'duplicate' => $duplicate];

                continue;
            }

            $applicable[] = ['remaining' => $remaining, 'duplicate' => $duplicate, 'shipmentId' => $shipId];
        }

        $this->info("Inspected {$candidates->count()} [remaining] candidate(s) with allocation/paid status.");
        $this->newLine();

        $this->info('Applicable promotions:');
        foreach ($applicable as $row) {
            $shipRef = Shipment::where('id', $row['shipmentId'])->value('reference');
            $this->line(sprintf(
                '  PSI#%d ("%s") → shipment_id=%d (%s); delete duplicate PSI#%d (empty)',
                $row['remaining']->id,
                $row['remaining']->label,
                $row['shipmentId'],
                $shipRef,
                $row['duplicate']->id,
            ));
        }
        $this->info('  Total: '.count($applicable));

        if (! empty($multiShipment)) {
            $this->newLine();
            $this->warn('Skipped (multiple shipments at this stage — manual decision needed):');
            foreach ($multiShipment as $row) {
                $this->line(sprintf(
                    '  PSI#%d ("%s") — %d shipments: %s',
                    $row['remaining']->id,
                    $row['remaining']->label,
                    $row['shipmentIds']->count(),
                    $row['shipmentIds']->implode(','),
                ));
            }
        }

        if (! empty($shipSpecificHasAllocations)) {
            $this->newLine();
            $this->warn('Skipped (ship-specific duplicate already has allocations — payment was split, manual review needed):');
            foreach ($shipSpecificHasAllocations as $row) {
                $this->line(sprintf(
                    '  Remaining PSI#%d vs ship-specific PSI#%d (both have allocations)',
                    $row['remaining']->id,
                    $row['duplicate']->id,
                ));
            }
        }

        if (! empty($noShipSpecific)) {
            $this->newLine();
            $this->info('No-op (no ship-specific duplicate at this stage; [remaining] is the only canonical): '.count($noShipSpecific));
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run: '.count($applicable).' promotion(s) would be applied. Re-run without --dry-run to apply.');

            return self::SUCCESS;
        }

        if (empty($applicable)) {
            $this->info('Nothing to promote.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Applying promotions...');

        DB::transaction(function () use ($applicable) {
            foreach ($applicable as $row) {
                $remaining = $row['remaining'];
                $duplicate = $row['duplicate'];
                $shipId = $row['shipmentId'];
                $shipRef = Shipment::where('id', $shipId)->value('reference');
                $docRef = $remaining->payable?->reference;

                $newLabel = $this->rewriteLabelForShipment($remaining->label, $shipRef, $docRef);

                $remaining->update([
                    'shipment_id' => $shipId,
                    'label' => $newLabel,
                    'amount' => $duplicate->amount,
                ]);

                $duplicate->delete();

                $remaining->refresh();
                $remaining->recalculateStatus();
            }
        });

        $this->info('Done. '.count($applicable).' [remaining] item(s) promoted; duplicates removed.');

        return self::SUCCESS;
    }

    protected function rewriteLabelForShipment(string $label, ?string $shipRef, ?string $docRef): string
    {
        $label = trim(preg_replace('/\s*\[remaining\]\s*$/u', '', $label));

        if ($shipRef && $docRef) {
            $label = preg_replace(
                '/\[\s*'.preg_quote($docRef, '/').'\s*\]$/u',
                '['.$shipRef.' / '.$docRef.']',
                $label
            );
        }

        return $label;
    }
}
