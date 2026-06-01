<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class DealBreakdownReport
{
    /**
     * @param  list<DealRow>  $deals
     * @param  list<DebitNoteRow>  $debitNotes
     * @param  list<string>  $unconvertedCurrencyPairs
     */
    public function __construct(
        public int $clientId,
        public string $clientName,
        public string $presentationCurrency,
        public DealBreakdownFilters $filters,
        public KpiSummary $kpi,
        public array $deals,
        public array $debitNotes,
        public array $unconvertedCurrencyPairs,
    ) {}
}
