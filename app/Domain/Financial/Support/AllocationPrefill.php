<?php

namespace App\Domain\Financial\Support;

use App\Domain\Infrastructure\Support\Money;

/**
 * Ao escolher uma parcela no formulário de pagamento, os créditos do MESMO
 * documento (desconto da PO-71 na parcela da PO-71) entram sozinhos e o
 * dinheiro é pré-carregado com o líquido. Antes o dinheiro vinha cheio e o
 * crédito era aplicado por cima, em outro quadro — o guard barrava, mas só
 * no fim ("Cash allocations exceed the payment amount").
 */
final class AllocationPrefill
{
    /**
     * @param  int  $remainingMinor  saldo da parcela (minor units)
     * @param  list<array{id: int, available: int}>  $credits  créditos do documento, na ordem de preferência, com saldo disponível em minor units
     * @param  list<int>  $usedCreditIds  créditos já aplicados em outras linhas do formulário
     * @return array{credits: list<array{credit_schedule_item_id: int, credit_amount: string}>, cash_minor: int}
     */
    public static function plan(int $remainingMinor, array $credits, array $usedCreditIds = []): array
    {
        $left = max(0, $remainingMinor);
        $rows = [];

        foreach ($credits as $credit) {
            if ($left <= 0) {
                break;
            }

            if (in_array((int) $credit['id'], $usedCreditIds, true)) {
                continue;
            }

            $apply = min((int) $credit['available'], $left);

            if ($apply <= 0) {
                continue;
            }

            $rows[] = [
                'credit_schedule_item_id' => (int) $credit['id'],
                'credit_amount' => number_format(Money::toMajor($apply), 2, '.', ''),
            ];

            $left -= $apply;
        }

        return ['credits' => $rows, 'cash_minor' => $left];
    }
}
