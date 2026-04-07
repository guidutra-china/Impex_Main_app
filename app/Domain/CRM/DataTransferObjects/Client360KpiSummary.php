<?php

namespace App\Domain\CRM\DataTransferObjects;

/**
 * Aggregated KPI counters for the Client 360 dashboard header.
 *
 * Money values are kept multi-currency: each map is keyed by ISO currency code
 * (e.g. ['USD' => 4520000, 'EUR' => 1280000]) using minor units (Money::SCALE).
 */
readonly class Client360KpiSummary
{
    public function __construct(
        public int $activeInquiriesCount,
        public int $openInvoicesCount,
        /** @var array<string, int> currency_code => grand_total in minor units */
        public array $openInvoicesValueByCurrency,
        public int $purchaseOrdersInProgressCount,
        public int $shipmentsInTransitCount,
        /** @var array<string, int> currency_code => pending receivables in minor units */
        public array $pendingReceivablesByCurrency,
        public int $alertsCount,
    ) {}
}
