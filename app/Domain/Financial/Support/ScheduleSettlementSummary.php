<?php

namespace App\Domain\Financial\Support;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Support\Collection;

/**
 * Totais do "Financial Summary" de um documento (PI ou PO) a partir das
 * parcelas do cronograma dele. Valores em unidades mínimas (escala Money).
 *
 * O crédito aplicado numa parcela entra no paid_amount dela. Como o total
 * líquido já abate as parcelas de crédito do próprio documento, esse crédito
 * não pode contar também como pago — senão o mesmo desconto reduz o saldo
 * duas vezes (PI-2026-00070, PI-2026-00083). Crédito vindo de outro documento
 * (ex.: Credit Note, PI-2026-00052) não está no total líquido e vale como pago.
 */
final class ScheduleSettlementSummary
{
    private function __construct(
        /** Parcelas normais menos as parcelas de crédito do documento. */
        public readonly int $netDue,
        /** Soma das parcelas de crédito do documento. */
        public readonly int $credits,
        /** Recebido/pago, sem o crédito do próprio documento. */
        public readonly int $paid,
        /** Saldo perdoado das parcelas isentas: conta como quitado. */
        public readonly int $waived,
        /** Total líquido menos pago e isento; nunca negativo. */
        public readonly int $remaining,
    ) {}

    /**
     * @param  Collection<int, PaymentScheduleItem>  $scheduleItems  todas as parcelas de UM documento
     */
    public static function fromScheduleItems(Collection $scheduleItems): self
    {
        $regularItems = $scheduleItems->where('is_credit', false);
        $creditItems = $scheduleItems->where('is_credit', true);

        $credits = (int) $creditItems->sum(fn (PaymentScheduleItem $i) => abs($i->amount));
        $netDue = (int) $regularItems->sum('amount') - $credits;

        $ownCreditsApplied = $creditItems->isEmpty() ? 0 : (int) PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $regularItems->pluck('id'))
            ->whereIn('credit_schedule_item_id', $creditItems->pluck('id'))
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->sum('allocated_amount_in_document_currency');

        $paid = max(0, (int) $regularItems->sum(fn (PaymentScheduleItem $i) => $i->paid_amount) - $ownCreditsApplied);

        $waived = (int) $regularItems
            ->where('status', PaymentScheduleStatus::WAIVED)
            ->sum(fn (PaymentScheduleItem $i) => $i->remaining_amount);

        // Saldo pelo total do documento, não parcela a parcela: sobras e faltas
        // de fração de centavo em parcelas já quitadas se compensam.
        $remaining = max(0, $netDue - $paid - $waived);

        return new self($netDue, $credits, $paid, $waived, $remaining);
    }

    /** Parte quitada do total líquido (pago + isento), em %. */
    public function progress(): int
    {
        return $this->netDue > 0 ? (int) round((($this->paid + $this->waived) / $this->netDue) * 100) : 0;
    }

    /** Parte efetivamente paga do total líquido, em %. */
    public function paidProgress(): int
    {
        return $this->netDue > 0 ? (int) round(($this->paid / $this->netDue) * 100) : 0;
    }
}
