<?php

namespace App\Domain\Financial\Support;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Support\Collection;

/**
 * No formulário de pagamento uma Debit Note com várias linhas é UMA opção,
 * com o total — quem recebe uma DN de viagem não quer alocar hotel, voo e
 * refeição um a um (DN-2026-0016, PERFIL SUL).
 *
 * O modelo de dados não muda: cada linha da DN continua com a sua parcela
 * (é o que liga a linha ao custo e ao status). Esta classe só traduz:
 *
 *   tela  → banco: expandRows() distribui o valor pelas linhas, em ordem;
 *   banco → tela:  collapseRows() junta as linhas de volta numa só.
 *
 * Regra única, sem flag no formulário: linha cuja parcela pertence a uma DN
 * com mais de uma parcela representa a DN inteira.
 */
final class DebitNoteBundle
{
    /**
     * Parcelas (não-crédito) da mesma DN, na ordem das linhas. Para qualquer
     * outro documento devolve só a própria parcela.
     *
     * @return Collection<int, PaymentScheduleItem>
     */
    public static function lines(PaymentScheduleItem $item): Collection
    {
        if ($item->payable_type !== DebitNote::class || $item->is_credit) {
            return collect([$item]);
        }

        return PaymentScheduleItem::query()
            ->where('payable_type', DebitNote::class)
            ->where('payable_id', $item->payable_id)
            ->where('is_credit', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public static function isBundle(PaymentScheduleItem $item): bool
    {
        return self::lines($item)->count() > 1;
    }

    /** Saldo da DN inteira (ou da própria parcela, fora de DN). */
    public static function remainingMinor(PaymentScheduleItem $item, ?int $ignorePaymentId = null): int
    {
        return (int) self::lines($item)->sum(fn (PaymentScheduleItem $line) => self::capacity($line, $ignorePaymentId));
    }

    /** Valor cheio da DN inteira. */
    public static function amountMinor(PaymentScheduleItem $item): int
    {
        return (int) self::lines($item)->sum('amount');
    }

    /**
     * Quanto a linha ainda comporta. No Edit, as alocações do próprio
     * pagamento serão recriadas, então voltam a contar como espaço livre.
     */
    private static function capacity(PaymentScheduleItem $line, ?int $ignorePaymentId): int
    {
        if ($line->status === PaymentScheduleStatus::WAIVED) {
            return 0;
        }

        $remaining = (int) $line->remaining_amount;

        if ($ignorePaymentId !== null) {
            $remaining += (int) PaymentAllocation::query()
                ->where('payment_schedule_item_id', $line->id)
                ->where('payment_id', $ignorePaymentId)
                ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
                ->sum('allocated_amount_in_document_currency');
        }

        return max(0, min((int) $line->amount, $remaining));
    }

    /**
     * Junta as parcelas de uma mesma DN numa só entrada (a primeira da lista).
     *
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @return Collection<int, PaymentScheduleItem> só as âncoras + parcelas de outros documentos
     */
    public static function collapseItems(Collection $items): Collection
    {
        $seen = [];

        return $items->filter(function (PaymentScheduleItem $item) use (&$seen) {
            if ($item->payable_type !== DebitNote::class || $item->is_credit || ! self::isBundle($item)) {
                return true;
            }

            if (isset($seen[$item->payable_id])) {
                return false;
            }

            return $seen[$item->payable_id] = true;
        })->values();
    }

    /**
     * tela → banco. Linha de DN vira uma linha por parcela, preenchendo em
     * ordem até o valor acabar; sobra (pagamento a mais) fica na última.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function expandRows(array $rows, ?int $ignorePaymentId = null): array
    {
        $out = [];

        foreach ($rows as $row) {
            $item = PaymentScheduleItem::find((int) ($row['payment_schedule_item_id'] ?? 0));

            if (! $item || ! self::isBundle($item)) {
                $out[] = $row;

                continue;
            }

            foreach (self::spread($row, self::lines($item), $ignorePaymentId) as $expanded) {
                $out[] = $expanded;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  Collection<int, PaymentScheduleItem>  $lines
     * @return list<array<string, mixed>>
     */
    private static function spread(array $row, Collection $lines, ?int $ignorePaymentId): array
    {
        $pmtTotal = Money::toMinor((float) ($row['allocated_amount'] ?? 0));
        $docRaw = $row['allocated_amount_in_document_currency'] ?? null;
        $docTotal = ($docRaw !== null && $docRaw !== '') ? Money::toMinor((float) $docRaw) : $pmtTotal;
        $sameCurrency = $docTotal === $pmtTotal;

        $credits = array_values($row['credits'] ?? []);
        $creditLeft = 0;
        foreach ($credits as $credit) {
            $creditLeft += Money::toMinor((float) ($credit['credit_amount'] ?? 0));
        }

        $shares = [];
        $left = $docTotal;
        $creditLineId = null;

        foreach ($lines as $line) {
            $capacity = self::capacity($line, $ignorePaymentId);

            // Créditos da linha do formulário vão para a 1ª parcela com espaço.
            if ($creditLeft > 0 && $capacity > 0 && $creditLineId === null) {
                $creditLineId = $line->id;
                $capacity = max(0, $capacity - $creditLeft);
            }

            $take = min($capacity, $left);
            if ($take > 0) {
                $shares[$line->id] = $take;
                $left -= $take;
            }
        }

        if ($left > 0) {
            $lastId = array_key_last($shares) ?? $lines->last()->id;
            $shares[$lastId] = ($shares[$lastId] ?? 0) + $left;
        }

        if ($creditLineId !== null && ! isset($shares[$creditLineId])) {
            $shares = [$creditLineId => 0] + $shares;
        }

        $base = array_diff_key($row, array_flip(['payment_schedule_item_id', 'allocated_amount', 'allocated_amount_in_document_currency', 'credits']));
        $out = [];
        $pmtLeft = $pmtTotal;
        $lastKey = array_key_last($shares);

        foreach ($shares as $lineId => $docShare) {
            $pmtShare = $sameCurrency
                ? $docShare
                : ($lineId === $lastKey ? $pmtLeft : (int) round($docTotal > 0 ? $pmtTotal * $docShare / $docTotal : 0));
            $pmtLeft -= $pmtShare;

            $out[] = $base + [
                'payment_schedule_item_id' => $lineId,
                'allocated_amount' => number_format(Money::toMajor($pmtShare), 2, '.', ''),
                'allocated_amount_in_document_currency' => number_format(Money::toMajor($docShare), 2, '.', ''),
                'credits' => $lineId === $creditLineId ? $credits : [],
            ];
        }

        return $out;
    }

    /**
     * banco → tela. Linhas de uma mesma DN voltam a ser uma só, ancorada na
     * primeira parcela alocada, com valores e créditos somados.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function collapseRows(array $rows): array
    {
        $out = [];
        $index = [];

        foreach ($rows as $row) {
            $item = PaymentScheduleItem::find((int) ($row['payment_schedule_item_id'] ?? 0));

            if (! $item || ! self::isBundle($item)) {
                $out[] = $row;

                continue;
            }

            $key = (int) $item->payable_id;

            if (! isset($index[$key])) {
                $index[$key] = count($out);
                $row['credits'] = array_values($row['credits'] ?? []);
                $out[] = $row;

                continue;
            }

            $target = &$out[$index[$key]];
            foreach (['allocated_amount', 'allocated_amount_in_document_currency'] as $field) {
                $target[$field] = round((float) ($target[$field] ?? 0) + (float) ($row[$field] ?? 0), 2);
            }
            $target['credits'] = array_merge($target['credits'], array_values($row['credits'] ?? []));
            unset($target);
        }

        return $out;
    }
}
