<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\QuotationItemSupplier;

class PromoteQuotationItemSupplierAction
{
    public function execute(QuotationItemSupplier $alt): void
    {
        $item = $alt->quotationItem;
        $quotation = $item->quotation;

        if ($quotation->status !== QuotationStatus::DRAFT) {
            throw new QuotationLockedException($quotation);
        }

        $rate = $alt->cost_exchange_rate !== null ? (float) $alt->cost_exchange_rate : 1.0;
        $convertedCost = (int) round($alt->unit_cost * $rate);
        $commissionRate = (float) $item->commission_rate;
        $unitPrice = $quotation->commission_type === CommissionType::EMBEDDED && $commissionRate > 0
            ? (int) round($convertedCost * (1 + $commissionRate / 100))
            : $convertedCost;

        $item->update([
            'selected_supplier_id' => $alt->company_id,
            'unit_cost' => $alt->unit_cost,
            'cost_currency_code' => $alt->currency_code,
            'cost_exchange_rate' => $rate,
            'unit_price' => $unitPrice,
        ]);
    }
}
