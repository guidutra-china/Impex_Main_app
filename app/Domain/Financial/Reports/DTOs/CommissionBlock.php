<?php

namespace App\Domain\Financial\Reports\DTOs;

/**
 * Comissão por deal (PI), em moeda de apresentação.
 *
 * - received = comissão que a Impex cobra do cliente: AdditionalCost COMMISSION
 *   billable_to=client (separate) + comissão embutida derivada da quotation (embedded).
 * - paid = comissão que a Impex repassa: AdditionalCost COMMISSION billable_to=supplier/company.
 *
 * Valores presentation são null quando há gap de câmbio (segue o padrão dos demais blocos).
 */
final readonly class CommissionBlock
{
    public function __construct(
        public ?int $receivedPresentation,
        public ?int $paidPresentation,
        public ?int $receivedSeparatePresentation,
        public ?int $receivedEmbeddedPresentation,
    ) {}
}
