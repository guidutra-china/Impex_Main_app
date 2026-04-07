<?php

namespace App\Domain\CRM\DataTransferObjects;

/**
 * Multi-currency financial snapshot for a single client.
 *
 * Receivables = unresolved client-side payment schedule items (ProformaInvoice).
 * Payables    = unresolved supplier-side payment schedule items (PurchaseOrder)
 *               where the PO is linked to one of the client's proforma invoices.
 *
 * Each map is keyed by ISO currency code; values are minor units (Money::SCALE).
 * No cross-currency conversion is performed — currencies stay separated.
 */
readonly class Client360FinancialSummary
{
    public function __construct(
        /** @var array<string, int> currency_code => total pending receivables (minor units) */
        public array $receivablesByCurrency,
        /** @var array<string, int> currency_code => count of pending receivable items */
        public array $receivablesCountByCurrency,
        /** @var array<string, int> currency_code => total overdue receivables (minor units) */
        public array $overdueReceivablesByCurrency,
        /** @var array<string, int> currency_code => total pending payables (minor units) */
        public array $payablesByCurrency,
        /** @var array<string, int> currency_code => count of pending payable items */
        public array $payablesCountByCurrency,
        /** @var array<string, int> currency_code => total overdue payables (minor units) */
        public array $overduePayablesByCurrency,
    ) {}
}
