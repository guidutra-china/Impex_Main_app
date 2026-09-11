<?php

namespace App\Domain\Financial\Support;

/**
 * Converte entre a forma do FORMULÁRIO (crédito aninhado dentro da linha da
 * parcela: `allocations[*].credits[*]`) e a forma PERSISTIDA/validada
 * (lista plana `credit_applications`, uma linha por crédito × parcela).
 *
 * A tela foi unificada em 2026-09-11 — o crédito passou a viver na mesma
 * linha da parcela para debitar o dinheiro na hora. Persistência, guard e
 * modal continuam falando a forma plana, então a conversão fica aqui,
 * pura e testável.
 */
final class AllocationFormShape
{
    /**
     * @param  array<int|string, array<string, mixed>>  $allocations
     * @return list<array{credit_schedule_item_id: int, payment_schedule_item_id: int, credit_amount: float}>
     */
    public static function flattenCredits(array $allocations): array
    {
        $rows = [];

        foreach ($allocations as $allocation) {
            $itemId = (int) ($allocation['payment_schedule_item_id'] ?? 0);

            if ($itemId <= 0) {
                continue;
            }

            foreach ($allocation['credits'] ?? [] as $credit) {
                $creditId = (int) ($credit['credit_schedule_item_id'] ?? 0);
                $amount = (float) ($credit['credit_amount'] ?? 0);

                if ($creditId <= 0 || $amount <= 0) {
                    continue;
                }

                $rows[] = [
                    'credit_schedule_item_id' => $creditId,
                    'payment_schedule_item_id' => $itemId,
                    'credit_amount' => $amount,
                ];
            }
        }

        return $rows;
    }

    /**
     * Aninha aplicações de crédito nas linhas de alocação da parcela alvo.
     * Crédito aplicado a uma parcela sem linha de dinheiro ganha uma linha
     * própria com dinheiro zero — o crédito sozinho também é uma liquidação.
     *
     * @param  array<int|string, array<string, mixed>>  $allocations
     * @param  array<int|string, array<string, mixed>>  $creditApplications
     * @return list<array<string, mixed>>
     */
    public static function nestCredits(array $allocations, array $creditApplications): array
    {
        $allocations = array_values($allocations);

        foreach ($allocations as &$allocation) {
            $allocation['credits'] = array_values($allocation['credits'] ?? []);
        }
        unset($allocation);

        foreach ($creditApplications as $credit) {
            $itemId = (int) ($credit['payment_schedule_item_id'] ?? 0);
            $creditId = (int) ($credit['credit_schedule_item_id'] ?? 0);

            if ($itemId <= 0 || $creditId <= 0) {
                continue;
            }

            $row = [
                'credit_schedule_item_id' => $creditId,
                'credit_amount' => (float) ($credit['credit_amount'] ?? 0),
            ];

            foreach ($allocations as $index => $allocation) {
                if ((int) ($allocation['payment_schedule_item_id'] ?? 0) === $itemId) {
                    $allocations[$index]['credits'][] = $row;

                    continue 2;
                }
            }

            $allocations[] = [
                'payment_schedule_item_id' => $itemId,
                'document_currency_code' => $credit['document_currency_code'] ?? null,
                'allocated_amount' => '0.00',
                'allocated_amount_in_document_currency' => '0.00',
                'exchange_rate' => null,
                'credits' => [$row],
            ];
        }

        return $allocations;
    }

    /**
     * Soma dos créditos aninhados, por moeda do documento ('' = moeda do
     * pagamento / não informada).
     *
     * @param  array<int|string, array<string, mixed>>  $allocations
     * @return array<string, float>
     */
    public static function creditTotalsByCurrency(array $allocations, string $paymentCurrency): array
    {
        $totals = [];

        foreach ($allocations as $allocation) {
            $currency = (string) ($allocation['document_currency_code'] ?? '');
            $key = ($currency === '' || $currency === $paymentCurrency) ? $paymentCurrency : $currency;

            foreach ($allocation['credits'] ?? [] as $credit) {
                $amount = (float) ($credit['credit_amount'] ?? 0);

                if ($amount > 0) {
                    $totals[$key] = ($totals[$key] ?? 0.0) + $amount;
                }
            }
        }

        return $totals;
    }
}
