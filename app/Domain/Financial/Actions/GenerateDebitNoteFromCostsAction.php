<?php

namespace App\Domain\Financial\Actions;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

class GenerateDebitNoteFromCostsAction
{
    /**
     * Collect all unbilled client-billable additional costs for a company
     * and create a DRAFT debit note with line items.
     */
    public function execute(Company $company, string $currencyCode, ?ProformaInvoice $pi = null, ?Shipment $shipment = null): ?DebitNote
    {
        $costs = $this->getUnbilledCosts($company, $pi, $shipment);

        if ($costs->isEmpty()) {
            return null;
        }

        $debitNote = DebitNote::create([
            'company_id' => $company->id,
            'proforma_invoice_id' => $pi?->id,
            'shipment_id' => $shipment?->id,
            'currency_code' => $currencyCode,
            'status' => DebitNoteStatus::DRAFT,
        ]);

        foreach ($costs as $cost) {
            $this->createLineItem($debitNote, $cost);
        }

        $debitNote->recalculateTotal();

        return $debitNote;
    }

    /**
     * Get additional costs billable to client that haven't been included in a debit note yet.
     */
    protected function getUnbilledCosts(Company $company, ?ProformaInvoice $pi, ?Shipment $shipment): \Illuminate\Support\Collection
    {
        $query = AdditionalCost::query()
            ->where('billable_to', BillableTo::CLIENT)
            ->whereNotIn('status', [AdditionalCostStatus::WAIVED->value])
            ->whereDoesntHave('debitNoteLineItems');

        if ($pi) {
            $query->where(function ($q) use ($pi) {
                $q->where(function ($q2) use ($pi) {
                    $q2->where('costable_type', ProformaInvoice::class)
                        ->where('costable_id', $pi->id);
                });
                // Also include costs on shipments that contain items from this PI
                $shipmentIds = ShipmentItem::whereHas('proformaInvoiceItem', fn ($q) => $q->where('proforma_invoice_id', $pi->id))
                    ->pluck('shipment_id')
                    ->unique();
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
        } else {
            // All unbilled costs for this client's PIs and shipments
            $piIds = ProformaInvoice::where('company_id', $company->id)->pluck('id');
            $shipmentIds = Shipment::where('company_id', $company->id)->pluck('id');

            $query->where(function ($q) use ($piIds, $shipmentIds) {
                $q->where(function ($q2) use ($piIds) {
                    $q2->where('costable_type', ProformaInvoice::class)
                        ->whereIn('costable_id', $piIds);
                });
                if ($shipmentIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($shipmentIds) {
                        $q2->where('costable_type', Shipment::class)
                            ->whereIn('costable_id', $shipmentIds);
                    });
                }
            });
        }

        return $query->with('costable')->get();
    }

    protected function createLineItem(DebitNote $debitNote, AdditionalCost $cost): void
    {
        $costable = $cost->costable;

        // Determine PI and Shipment references
        $piId = null;
        $shipmentId = null;

        if ($costable instanceof ProformaInvoice) {
            $piId = $costable->id;
        } elseif ($costable instanceof Shipment) {
            $shipmentId = $costable->id;
        }

        // Use document currency amount when available (converted to PI/document currency)
        $lineAmount = $cost->amount_in_document_currency ?: $cost->amount;
        $lineCurrency = $cost->amount_in_document_currency
            ? ($costable?->currency_code ?? $cost->currency_code)
            : $cost->currency_code;

        DebitNoteLineItem::create([
            'debit_note_id' => $debitNote->id,
            'additional_cost_id' => $cost->id,
            'proforma_invoice_id' => $piId,
            'shipment_id' => $shipmentId,
            'description' => $cost->cost_type->getLabel() . ' — ' . ($cost->description ?: ($costable?->reference ?? '')),
            'amount' => $lineAmount,
            'currency_code' => $lineCurrency,
        ]);
    }
}
