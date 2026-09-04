<?php

namespace App\Domain\ProformaInvoices\Actions;

use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cria ou regenera a Proforma Invoice de uma quotation.
 *
 * Sem PI vinculada: cria uma nova (comportamento original do botão Workflow).
 * Com PI vinculada em DRAFT: sincroniza itens e header com os valores atuais
 * da quotation em vez de criar uma segunda PI. O sync casa itens por
 * quotation_item_id (mesmo padrão do GeneratePurchaseOrdersAction); itens
 * manuais da PI (quotation_item_id null) são preservados. PI além de DRAFT
 * não pode ser regenerada.
 */
class SyncProformaInvoiceFromQuotationAction
{
    public function __construct(
        protected CreateQuotationCommissionCostsAction $commissionCosts,
    ) {}

    /**
     * PI ativa (não cancelada) vinculada à quotation via pivot.
     */
    public function findLinkedPi(Quotation $quotation): ?ProformaInvoice
    {
        return ProformaInvoice::query()
            ->whereHas('quotations', fn ($query) => $query->whereKey($quotation->id))
            ->where('status', '!=', ProformaInvoiceStatus::CANCELLED)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{pi: ProformaInvoice, created: bool}
     */
    public function execute(Quotation $quotation): array
    {
        return DB::transaction(function () use ($quotation) {
            $pi = $this->findLinkedPi($quotation);

            if ($pi !== null && $pi->status !== ProformaInvoiceStatus::DRAFT) {
                throw new RuntimeException(
                    "Proforma Invoice {$pi->reference} has already been issued and can no longer be regenerated from this quotation."
                );
            }

            $created = $pi === null;

            if ($created) {
                $pi = ProformaInvoice::create([
                    'inquiry_id' => $quotation->inquiry_id,
                    'company_id' => $quotation->company_id,
                    'contact_id' => $quotation->contact_id,
                    'payment_term_id' => $quotation->payment_term_id,
                    'status' => ProformaInvoiceStatus::DRAFT,
                    'currency_code' => $quotation->currency_code ?? 'USD',
                    'issue_date' => now(),
                    'validity_days' => $quotation->validity_days,
                    'created_by' => auth()->id(),
                    'responsible_user_id' => $quotation->responsible_user_id,
                ]);

                $pi->quotations()->attach($quotation->id);
            } else {
                $pi->update([
                    'contact_id' => $quotation->contact_id,
                    'payment_term_id' => $quotation->payment_term_id,
                    'currency_code' => $quotation->currency_code ?? 'USD',
                    'validity_days' => $quotation->validity_days,
                ]);
            }

            $this->syncItems($pi, $quotation);

            $this->commissionCosts->refresh($pi, $quotation);

            return ['pi' => $pi, 'created' => $created];
        });
    }

    /**
     * Sincroniza itens da quotation na PI, casando por quotation_item_id.
     * Itens removidos da quotation são apagados pelo hook deleting do
     * QuotationItem (o FK é set-null e apagaria a chave de sync antes do
     * regenerate — mesma razão do hook em ProformaInvoiceItem para POs).
     */
    protected function syncItems(ProformaInvoice $pi, Quotation $quotation): void
    {
        $quotation->load('items.product.suppliers');

        $existingItems = $pi->items()
            ->whereNotNull('quotation_item_id')
            ->get()
            ->keyBy('quotation_item_id');

        $isSeparateCommission = $quotation->commission_type === CommissionType::SEPARATE;
        $sortOrder = 0;

        foreach ($quotation->items as $item) {
            $sortOrder++;

            // Comissão SEPARATE: o unit_price da PI é o custo na moeda da
            // quotation; o markup vira AdditionalCost de Service Fee.
            $unitPrice = $isSeparateCommission ? $item->converted_unit_cost : $item->unit_price;

            $piItem = $existingItems->get($item->id);

            if ($piItem !== null) {
                $updates = [
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit ?: 'pcs',
                    'unit_price' => $unitPrice,
                    'unit_cost' => $item->unit_cost,
                    'cost_currency_code' => $item->cost_currency_code,
                    'cost_exchange_rate' => $item->cost_exchange_rate,
                    'incoterm' => $item->incoterm ?? $quotation->incoterm,
                    'sort_order' => $sortOrder,
                ];

                // Só sobrescreve o fornecedor quando a quotation define um;
                // um fornecedor atribuído manualmente na PI é mantido.
                if ($item->selected_supplier_id !== null) {
                    $updates['supplier_company_id'] = $item->selected_supplier_id;
                }

                // Regenerar reflete renomeações do produto no documento;
                // itens sem produto vinculado mantêm a descrição atual.
                if ($item->product !== null) {
                    $updates['description'] = $item->product->name;
                }

                $piItem->update($updates);

                continue;
            }

            ProformaInvoiceItem::create([
                'proforma_invoice_id' => $pi->id,
                'product_id' => $item->product_id,
                'quotation_item_id' => $item->id,
                'supplier_company_id' => $this->resolveSupplierId($item),
                'description' => $item->product?->name,
                'specifications' => $item->product?->specification?->description ?? null,
                'quantity' => $item->quantity,
                'unit' => $item->unit ?: 'pcs',
                'unit_price' => $unitPrice,
                'unit_cost' => $item->unit_cost,
                'cost_currency_code' => $item->cost_currency_code,
                'cost_exchange_rate' => $item->cost_exchange_rate,
                'incoterm' => $item->incoterm ?? $quotation->incoterm,
                'notes' => $item->notes,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    protected function resolveSupplierId(QuotationItem $item): ?int
    {
        if ($item->selected_supplier_id !== null) {
            return $item->selected_supplier_id;
        }

        if (! $item->product) {
            return null;
        }

        return $item->product->suppliers()
            ->orderByDesc('company_product.is_preferred')
            ->first()?->id;
    }
}
