<?php

namespace App\Domain\Financial\Traits;

use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAdditionalCosts
{
    public function additionalCosts(): MorphMany
    {
        return $this->morphMany(AdditionalCost::class, 'costable');
    }

    public function getAdditionalCostsTotalAttribute(): int
    {
        return $this->additionalCosts->sum('amount_in_document_currency');
    }

    public function getClientBillableCostsTotalAttribute(): int
    {
        return $this->additionalCosts
            ->where('billable_to', BillableTo::CLIENT)
            ->sum('amount_in_document_currency');
    }

    public function getSupplierBillableCostsTotalAttribute(): int
    {
        return $this->additionalCosts
            ->where('billable_to', BillableTo::SUPPLIER)
            ->sum('amount_in_document_currency');
    }

    public function getCompanyCostsTotalAttribute(): int
    {
        return $this->additionalCosts
            ->where('billable_to', BillableTo::COMPANY)
            ->sum('amount_in_document_currency');
    }
}
