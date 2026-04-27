<?php

namespace App\Console\Commands;

use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Console\Command;

class InspectMirrorOrphansCommand extends Command
{
    protected $signature = 'financial:inspect-mirror-orphans';

    protected $description = 'Diagnostic: lists shipment-owned mirror PSIs without allocations whose PI/PO has no PI-wide counterpart at the same stage. For each, shows whether a shipment-specific canonical exists already (no-op) or is genuinely missing (needs intervention).';

    public function handle(): int
    {
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

        $noPiWide = collect();
        foreach ($orphans as $mirror) {
            if (! preg_match('#/\s*((?:PI|PO)-[\w-]+)\]#', (string) $mirror->label, $m)) {
                continue;
            }
            $docRef = $m[1];
            $isPi = str_starts_with($docRef, 'PI');
            $docClass = $isPi ? ProformaInvoice::class : PurchaseOrder::class;
            $docId = $docClass::where('reference', $docRef)->value('id');
            if (! $docId) {
                continue;
            }

            $piWide = PaymentScheduleItem::where('payable_type', $docClass)
                ->where('payable_id', $docId)
                ->whereNull('shipment_id')
                ->where('payment_term_stage_id', $mirror->payment_term_stage_id)
                ->first();

            if (! $piWide) {
                $noPiWide->push(['mirror' => $mirror, 'docRef' => $docRef, 'docClass' => $docClass, 'docId' => $docId]);
            }
        }

        $this->info("Found {$noPiWide->count()} noPiWide mirror(s).");
        $this->newLine();

        foreach ($noPiWide as $row) {
            $mirror = $row['mirror'];
            $docRef = $row['docRef'];
            $docClass = $row['docClass'];
            $docId = $row['docId'];
            $shipRef = Shipment::where('id', $mirror->payable_id)->value('reference');
            $mirrorStatus = is_object($mirror->status) ? $mirror->status->value : $mirror->status;

            $this->line('========================================');
            $this->line("Mirror PSI#{$mirror->id} on Shipment {$shipRef} (#{$mirror->payable_id})");
            $this->line("  stage={$mirror->payment_term_stage_id} | amount={$mirror->amount} | status={$mirrorStatus}");
            $this->line("  label={$mirror->label}");
            $this->line("  -> references {$docRef} (id={$docId})");

            $shipSpecific = PaymentScheduleItem::where('payable_type', $docClass)
                ->where('payable_id', $docId)
                ->where('shipment_id', $mirror->payable_id)
                ->where('payment_term_stage_id', $mirror->payment_term_stage_id)
                ->first();

            if ($shipSpecific) {
                $paid = $shipSpecific->allocations()->sum('allocated_amount_in_document_currency') ?? 0;
                $ssStatus = is_object($shipSpecific->status) ? $shipSpecific->status->value : $shipSpecific->status;
                $this->info('  [OK] Shipment-specific canonical EXISTS:');
                $this->line(sprintf(
                    '    PSI#%d | ship=%d | stage=%s | %s | amount=%d | paid=%d | allocs=%d',
                    $shipSpecific->id, $shipSpecific->shipment_id, $shipSpecific->payment_term_stage_id,
                    $ssStatus, $shipSpecific->amount, $paid, $shipSpecific->allocations()->count()
                ));
                if ($shipSpecific->amount === $mirror->amount) {
                    $this->line('    Amount matches mirror [OK]');
                } else {
                    $this->warn("    AMOUNT MISMATCH (canonical={$shipSpecific->amount} vs mirror={$mirror->amount})");
                }
            } else {
                $this->warn('  [MISS] NO shipment-specific canonical for this shipment+stage');
                $this->line("  All canonicals for {$docRef} at stage {$mirror->payment_term_stage_id}:");
                PaymentScheduleItem::where('payable_type', $docClass)
                    ->where('payable_id', $docId)
                    ->where('payment_term_stage_id', $mirror->payment_term_stage_id)
                    ->get()
                    ->each(function ($psi) {
                        $st = is_object($psi->status) ? $psi->status->value : $psi->status;
                        $this->line(sprintf(
                            '    PSI#%d | ship=%s | %s | amount=%d | label=%s',
                            $psi->id, $psi->shipment_id ?? 'null', $st, $psi->amount, $psi->label
                        ));
                    });
            }
            $this->newLine();
        }

        return self::SUCCESS;
    }
}
