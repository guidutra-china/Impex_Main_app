<?php

namespace App\Domain\Financial\Reports\DTOs;

/**
 * Comissão por deal (PI), em moeda de apresentação.
 *
 * - received = comissão cobrada do cliente (a receber): AdditionalCost COMMISSION
 *   billable_to=client (separate) + comissão embutida derivada da quotation (embedded).
 * - paid = quanto o cliente JÁ PAGOU dessa comissão. Separate: alocações aprovadas
 *   no PSI gerado do AdditionalCost de comissão. Embedded: proporcional ao pagamento
 *   das mercadorias (está embutida no unit_price da PI).
 * - outstanding = received − paid (o que falta o cliente pagar de comissão).
 *
 * Valores presentation são null quando há gap de câmbio (segue o padrão dos demais blocos).
 */
final readonly class CommissionBlock
{
    public function __construct(
        public ?int $receivedPresentation,
        public ?int $paidPresentation,
        public ?int $outstandingPresentation,
        public ?int $receivedSeparatePresentation,
        public ?int $receivedEmbeddedPresentation,
    ) {}
}
