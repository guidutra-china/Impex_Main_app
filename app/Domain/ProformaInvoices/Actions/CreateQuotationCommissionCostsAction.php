<?php

namespace App\Domain\ProformaInvoices\Actions;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Models\Quotation;

class CreateQuotationCommissionCostsAction
{
    /**
     * Cria o AdditionalCost de comissão (Service Fee) na PI para cada quotation
     * vinculada com comissão SEPARATE. Comissão EMBEDDED já está no preço dos
     * itens e não gera custo adicional. Idempotente: pula quotations que já
     * têm um custo COMMISSION registrado na PI.
     *
     * @param  array<int>  $quotationIds
     */
    public function execute(ProformaInvoice $pi, array $quotationIds): void
    {
        if ($quotationIds === []) {
            return;
        }

        $quotations = Quotation::query()
            ->whereIn('id', $quotationIds)
            ->where('commission_type', CommissionType::SEPARATE)
            ->where('commission_rate', '>', 0)
            ->get();

        foreach ($quotations as $quotation) {
            $itemsTotal = $this->commissionBase($pi, $quotation);

            if ($itemsTotal <= 0) {
                continue;
            }

            $exists = $pi->additionalCosts()
                ->where('cost_type', AdditionalCostType::COMMISSION)
                ->where('notes', 'like', '%'.$quotation->reference.'%')
                ->exists();

            if ($exists) {
                continue;
            }

            $commissionAmount = (int) round($itemsTotal * ($quotation->commission_rate / 100));

            AdditionalCost::create([
                'costable_type' => $pi->getMorphClass(),
                'costable_id' => $pi->id,
                'cost_type' => AdditionalCostType::COMMISSION,
                'description' => 'Service Fee ('.$quotation->commission_rate.'%) — '.$quotation->reference,
                'amount' => $commissionAmount,
                'currency_code' => $pi->currency_code,
                'exchange_rate' => 1,
                'amount_in_document_currency' => $commissionAmount,
                'billable_to' => BillableTo::CLIENT,
                'cost_date' => now()->toDateString(),
                'status' => AdditionalCostStatus::PENDING,
                'notes' => 'Auto-generated from '.$quotation->reference.' (Separate commission '.$quotation->commission_rate.'%)',
            ]);
        }
    }

    /**
     * Base de cálculo da comissão: total dos itens da PI ligados aos itens da
     * quotation. Itens criados a partir da inquiry nascem sem vínculo com
     * quotation_item; nesse caso o fallback casa por produto para que a
     * comissão SEPARATE ainda seja cobrada.
     */
    protected function commissionBase(ProformaInvoice $pi, Quotation $quotation): int
    {
        $linkedTotal = $pi->items()
            ->whereHas('quotationItem', fn ($q) => $q->where('quotation_id', $quotation->id))
            ->get()
            ->sum(fn ($item) => $item->line_total);

        if ($linkedTotal > 0) {
            return $linkedTotal;
        }

        $productIds = $quotation->items()
            ->whereNotNull('product_id')
            ->pluck('product_id');

        if ($productIds->isEmpty()) {
            return 0;
        }

        return $pi->items()
            ->whereNull('quotation_item_id')
            ->whereIn('product_id', $productIds)
            ->get()
            ->sum(fn ($item) => $item->line_total);
    }
}
