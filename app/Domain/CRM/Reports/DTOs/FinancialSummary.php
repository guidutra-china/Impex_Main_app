<?php

namespace App\Domain\CRM\Reports\DTOs;

final class FinancialSummary
{
    /**
     * @param  list<CurrencyTotals>  $totalsByCurrency
     * @param  list<AgingBuckets>  $agingByCurrency
     * @param  array<string,array<string,float>>  $breakdownByDocumentType  Shape: [currency => [docType => total]]
     */
    public function __construct(
        public readonly array $totalsByCurrency,
        public readonly array $agingByCurrency,
        public readonly array $breakdownByDocumentType,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->totalsByCurrency === [];
    }
}
