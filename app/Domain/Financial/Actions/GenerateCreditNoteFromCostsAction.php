<?php

namespace App\Domain\Financial\Actions;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

class GenerateCreditNoteFromCostsAction
{
    /**
     * Collect supplier-billable additional costs (quality deductions,
     * claims, shortages charged back to the supplier) that haven't been
     * included in a credit note yet and create a DRAFT credit note.
     */
    public function execute(Company $supplier, string $currencyCode, ?PurchaseOrder $po = null, ?Shipment $shipment = null): ?CreditNote
    {
        $costs = $this->getUnbilledCosts($supplier, $po, $shipment);

        if ($costs->isEmpty()) {
            return null;
        }

        $creditNote = CreditNote::create([
            'company_id' => $supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'purchase_order_id' => $po?->id,
            'shipment_id' => $shipment?->id,
            'currency_code' => $currencyCode,
            'status' => CreditNoteStatus::DRAFT,
        ]);

        foreach ($costs as $cost) {
            $this->createLineItem($creditNote, $cost);
        }

        $creditNote->recalculateTotal();

        return $creditNote;
    }

    /**
     * Additional costs billable to supplier not yet included in a credit note.
     *
     * Public: single source of truth for "which costs belong on a supplier
     * credit note" — unlike the debit note side, DISCOUNT is intentionally
     * included here (a supplier discount is a credit owed by the supplier).
     * Reused as-is by ViewCreditNote's autoPopulate action.
     */
    public function getUnbilledCosts(Company $supplier, ?PurchaseOrder $po, ?Shipment $shipment): \Illuminate\Support\Collection
    {
        $query = AdditionalCost::query()
            ->where('billable_to', BillableTo::SUPPLIER)
            ->where('supplier_company_id', $supplier->id)
            ->whereNotIn('status', [AdditionalCostStatus::WAIVED->value])
            ->whereDoesntHave('creditNoteLineItems');

        if ($po) {
            // Costs anchored on the PO's PI or on shipments containing PO items
            $query->where(function ($q) use ($po) {
                $q->where(function ($q2) use ($po) {
                    $q2->where('costable_type', ProformaInvoice::class)
                        ->where('costable_id', $po->proforma_invoice_id);
                });
                $shipmentIds = $po->items()
                    ->with('shipmentItems')
                    ->get()
                    ->flatMap(fn ($item) => $item->shipmentItems->pluck('shipment_id'))
                    ->unique()
                    ->values();
                if ($shipmentIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($shipmentIds) {
                        $q2->where('costable_type', Shipment::class)
                            ->whereIn('costable_id', $shipmentIds);
                    });
                }
            });
        } elseif ($shipment) {
            $query->where('costable_type', Shipment::class)
                ->where('costable_id', $shipment->id);
        }

        return $query->with('costable')->get();
    }

    /**
     * Public for the same reason as getUnbilledCosts(): ViewCreditNote's
     * autoPopulate action reuses this instead of duplicating line-item
     * construction (and re-introducing the missing abs()).
     */
    public function createLineItem(CreditNote $creditNote, AdditionalCost $cost): void
    {
        $costable = $cost->costable;

        $piId = null;
        $shipmentId = null;

        if ($costable instanceof ProformaInvoice) {
            $piId = $costable->id;
        } elseif ($costable instanceof Shipment) {
            $shipmentId = $costable->id;
        }

        $lineAmount = abs($cost->amount_in_document_currency ?: $cost->amount);
        $lineCurrency = $cost->amount_in_document_currency
            ? ($costable?->currency_code ?? $cost->currency_code)
            : $cost->currency_code;

        CreditNoteLineItem::create([
            'credit_note_id' => $creditNote->id,
            'additional_cost_id' => $cost->id,
            'proforma_invoice_id' => $piId,
            'shipment_id' => $shipmentId,
            'description' => $cost->cost_type->getLabel().' — '.($cost->description ?: ($costable?->reference ?? '')),
            'amount' => $lineAmount,
            'currency_code' => $lineCurrency,
        ]);
    }
}
