<?php

namespace App\Domain\Financial\Support;

use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Support\Collection;

/**
 * Junta parcelas iguais ({@see InstallmentStage}) e soma valor, pago e
 * restante — para telas que não são tabela Filament (portal do cliente).
 *
 * O restante do grupo é a soma do saldo de cada parcela (o mesmo número da
 * linha), não soma − pago: parcela paga a mais não abate o saldo das outras.
 * A ordem dos grupos é a de primeira aparição na lista recebida.
 */
final class InstallmentStageGrouper
{
    /**
     * @param  iterable<PaymentScheduleItem>  $items
     * @return Collection<int, array{key: string, bucket: string, title: ?string, currency: string, amount: int, paid: int, remaining: int, count: int, earliest_due: ?\Carbon\CarbonInterface, items: Collection<int, PaymentScheduleItem>}>
     */
    public static function group(iterable $items): Collection
    {
        $groups = [];

        foreach ($items as $item) {
            $key = InstallmentStage::key($item);
            $bucket = InstallmentStage::bucket($item);

            $groups[$key] ??= [
                'key' => $key,
                'bucket' => $bucket,
                // Custos e créditos não têm estágio: quem exibe escolhe o rótulo.
                'title' => InstallmentStage::isStage($bucket) ? InstallmentStage::cleanLabel($item) : null,
                'currency' => (string) $item->currency_code,
                'amount' => 0,
                'paid' => 0,
                'remaining' => 0,
                'count' => 0,
                'earliest_due' => null,
                'items' => collect(),
            ];

            $groups[$key]['amount'] += (int) $item->amount;
            $groups[$key]['paid'] += (int) $item->paid_amount;
            $groups[$key]['remaining'] += (int) $item->remaining_amount;
            $groups[$key]['count']++;
            $groups[$key]['items']->push($item);

            if ($item->due_date !== null
                && ($groups[$key]['earliest_due'] === null || $item->due_date->lt($groups[$key]['earliest_due']))) {
                $groups[$key]['earliest_due'] = $item->due_date;
            }
        }

        return collect(array_values($groups));
    }
}
