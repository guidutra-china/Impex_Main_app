<?php

namespace App\Domain\Financial\Support;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;

/**
 * Sanidade das alocações do form de pagamento (AR/AP). Puro e testável:
 * recebe os arrays do form (valores em MAJOR units, como digitados) e
 * devolve mensagens de erro — vazio = ok.
 *
 * Alvo principal: a dupla contagem do crédito — o prefill preenche a
 * alocação em dinheiro com o valor CHEIO da parcela e o usuário aplica o
 * crédito por cima, gerando "Overpaid" fantasma. Parcelas SEM crédito no
 * form ficam fora da checagem por parcela, então overpay genuíno (cliente
 * pagou a mais) continua registrável.
 */
class AllocationGuards
{
    private const TOLERANCE_MINOR = 100; // 0.01 na escala do projeto

    /**
     * @param  array<int, array<string, mixed>>  $allocations  rows do repeater de alocações
     * @param  array<int, array<string, mixed>>  $creditApplications  rows do repeater de créditos
     * @param  float|null  $paymentAmount  valor do pagamento (moeda do pagamento)
     * @param  int|null  $ignorePaymentId  no Edit, o pagamento sendo editado (suas alocações antigas serão recriadas)
     * @return list<string>
     */
    public static function overpayErrors(array $allocations, array $creditApplications, ?float $paymentAmount, ?int $ignorePaymentId = null): array
    {
        $errors = [];
        $tolerance = Money::toMajor(self::TOLERANCE_MINOR);

        // 1) Dinheiro alocado não pode exceder o valor do pagamento.
        $cashTotal = 0.0;
        foreach ($allocations as $row) {
            $cashTotal += (float) ($row['allocated_amount'] ?? 0);
        }

        if ($paymentAmount !== null && $cashTotal > $paymentAmount + $tolerance) {
            $errors[] = __('forms.validation.allocations_exceed_payment', [
                'allocated' => number_format($cashTotal, 2),
                'payment' => number_format($paymentAmount, 2),
            ]);
        }

        // 2) Por parcela COM crédito aplicado neste form: dinheiro + crédito
        //    não pode exceder a capacidade da parcela.
        $creditsByItem = [];
        foreach ($creditApplications as $row) {
            $itemId = (int) ($row['payment_schedule_item_id'] ?? 0);
            if ($itemId > 0) {
                $creditsByItem[$itemId] = ($creditsByItem[$itemId] ?? 0.0) + (float) ($row['credit_amount'] ?? 0);
            }
        }

        if ($creditsByItem === []) {
            return $errors;
        }

        $cashByItem = [];
        foreach ($allocations as $row) {
            $itemId = (int) ($row['payment_schedule_item_id'] ?? 0);
            if ($itemId > 0) {
                $inDoc = $row['allocated_amount_in_document_currency'] ?? null;
                $cashByItem[$itemId] = ($cashByItem[$itemId] ?? 0.0)
                    + (float) ($inDoc !== null && $inDoc !== '' ? $inDoc : ($row['allocated_amount'] ?? 0));
            }
        }

        $items = PaymentScheduleItem::whereIn('id', array_keys($creditsByItem))->get()->keyBy('id');

        foreach ($creditsByItem as $itemId => $creditTotal) {
            $item = $items->get($itemId);

            if (! $item) {
                continue;
            }

            // Capacidade = valor da parcela − pago por OUTROS pagamentos
            // aprovados. No Edit, as alocações do próprio pagamento serão
            // recriadas, então ficam fora da conta.
            $paidByOthers = (int) PaymentAllocation::query()
                ->where('payment_schedule_item_id', $itemId)
                ->when($ignorePaymentId !== null, fn ($q) => $q->where('payment_id', '!=', $ignorePaymentId))
                ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
                ->sum('allocated_amount_in_document_currency');

            $capacity = Money::toMajor(max(0, $item->amount - $paidByOthers));
            $incoming = ($cashByItem[$itemId] ?? 0.0) + $creditTotal;

            if ($incoming > $capacity + $tolerance) {
                $errors[] = __('forms.validation.allocation_plus_credit_exceeds_item', [
                    'item' => $item->label,
                    'incoming' => number_format($incoming, 2),
                    'capacity' => number_format($capacity, 2),
                ]);
            }
        }

        return $errors;
    }
}
