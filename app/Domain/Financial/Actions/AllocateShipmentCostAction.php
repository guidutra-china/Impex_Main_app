<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

class AllocateShipmentCostAction
{
    /**
     * Allocate a shipment-level additional cost proportionally across PIs
     * based on weight or volume (CBM) of each PI's items in the shipment.
     *
     * @param  string  $criterion  'weight' or 'volume'
     * @return array<int, array{pi_id: int, pi_reference: string, proportion: float, amount: int}>
     */
    public function calculate(Shipment $shipment, AdditionalCost $cost, string $criterion = 'weight'): array
    {
        $items = ShipmentItem::where('shipment_id', $shipment->id)
            ->with('proformaInvoiceItem.proformaInvoice')
            ->get();

        // Group by PI and sum the criterion value
        $piTotals = [];

        foreach ($items as $item) {
            $pi = $item->proformaInvoiceItem?->proformaInvoice;
            if (! $pi) {
                continue;
            }

            $piId = $pi->id;
            if (! isset($piTotals[$piId])) {
                $piTotals[$piId] = [
                    'pi_id' => $piId,
                    'pi_reference' => $pi->reference,
                    'total' => 0,
                ];
            }

            $value = $criterion === 'volume'
                ? (float) ($item->total_volume ?? 0)
                : (float) ($item->total_weight ?? 0);

            $piTotals[$piId]['total'] += $value;
        }

        $grandTotal = array_sum(array_column($piTotals, 'total'));

        if ($grandTotal <= 0) {
            // Fallback: equal split
            $count = count($piTotals);
            if ($count === 0) {
                return [];
            }

            $perPi = (int) round($cost->amount / $count);
            $result = [];
            foreach ($piTotals as $piData) {
                $result[] = [
                    'pi_id' => $piData['pi_id'],
                    'pi_reference' => $piData['pi_reference'],
                    'proportion' => round(1 / $count, 4),
                    'amount' => $perPi,
                ];
            }

            return $result;
        }

        $result = [];
        $allocatedSoFar = 0;
        $piList = array_values($piTotals);

        foreach ($piList as $index => $piData) {
            $proportion = $piData['total'] / $grandTotal;
            $isLast = ($index === count($piList) - 1);

            // Last item gets the remainder to avoid rounding errors
            $amount = $isLast
                ? ($cost->amount - $allocatedSoFar)
                : (int) round($cost->amount * $proportion);

            $allocatedSoFar += $amount;

            $result[] = [
                'pi_id' => $piData['pi_id'],
                'pi_reference' => $piData['pi_reference'],
                'proportion' => round($proportion, 4),
                'amount' => $amount,
            ];
        }

        return $result;
    }

    /**
     * Apply allocation: replace a single shipment-cost line item with
     * multiple PI-specific line items on the debit note.
     */
    public function apply(DebitNote $debitNote, AdditionalCost $cost, array $allocations): void
    {
        // Remove existing line items for this cost
        $debitNote->lineItems()
            ->where('additional_cost_id', $cost->id)
            ->delete();

        $costable = $cost->costable;
        $shipmentId = $costable instanceof Shipment ? $costable->id : null;

        foreach ($allocations as $alloc) {
            DebitNoteLineItem::create([
                'debit_note_id' => $debitNote->id,
                'additional_cost_id' => $cost->id,
                'proforma_invoice_id' => $alloc['pi_id'],
                'shipment_id' => $shipmentId,
                'description' => $cost->cost_type->getLabel() . ' — ' . ($costable?->reference ?? '')
                    . ' (' . ($alloc['pi_reference'] ?? '') . ' '
                    . round($alloc['proportion'] * 100, 1) . '%)',
                'amount' => $alloc['amount'],
                'currency_code' => $cost->currency_code,
            ]);
        }

        $debitNote->recalculateTotal();
    }
}
