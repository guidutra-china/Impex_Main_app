<?php

namespace App\Domain\Financial\Support;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;

/**
 * Espelho de status parcela → custo, por lado. Um AdditionalCost tem até
 * três parcelas (cliente, [forwarder-payable], [supplier-payable]), cada
 * uma refletida na sua coluna: status, forwarder_status,
 * supplier_payable_status.
 *
 * O reconcile grava a coluna a cada alocação; aqui fica a regra comum e o
 * seed inicial — sem ele os lados forwarder/fornecedor nasciam NULL e só
 * ganhavam status no primeiro pagamento.
 */
final class AdditionalCostSideStatus
{
    public static function fromScheduleStatus(?PaymentScheduleStatus $status): AdditionalCostStatus
    {
        return match ($status) {
            PaymentScheduleStatus::PAID => AdditionalCostStatus::PAID,
            PaymentScheduleStatus::WAIVED => AdditionalCostStatus::WAIVED,
            PaymentScheduleStatus::DUE,
            PaymentScheduleStatus::OVERDUE => AdditionalCostStatus::INVOICED,
            default => AdditionalCostStatus::PENDING,
        };
    }

    /** Coluna do custo que a parcela espelha, pela tag nas notes. */
    public static function columnFor(PaymentScheduleItem $item): string
    {
        $notes = (string) ($item->notes ?? '');

        return match (true) {
            str_contains($notes, PaymentScheduleItem::FORWARDER_PAYABLE_TAG) => 'forwarder_status',
            str_contains($notes, PaymentScheduleItem::SUPPLIER_PAYABLE_TAG) => 'supplier_payable_status',
            default => 'status',
        };
    }

    /**
     * Preenche a coluna do lado só quando ainda está NULL — nunca por cima
     * do que o reconcile já gravou (PAID/WAIVED/...).
     */
    public static function seedFromScheduleItem(AdditionalCost $cost, PaymentScheduleItem $item): bool
    {
        $column = self::columnFor($item);

        if ($cost->{$column} !== null) {
            return false;
        }

        $cost->{$column} = self::fromScheduleStatus($item->status);
        $cost->save();

        return true;
    }

    /**
     * Backfill (migração): toda parcela derivada de custo cujo lado ainda
     * está NULL. Idempotente; portável (Eloquent, sem SQL cru).
     *
     * @return int custos alterados
     */
    public static function backfillMissing(): int
    {
        $changed = 0;

        PaymentScheduleItem::query()
            ->where('source_type', AdditionalCost::class)
            ->where(function ($q) {
                $q->where('notes', 'LIKE', '%'.PaymentScheduleItem::FORWARDER_PAYABLE_TAG.'%')
                    ->orWhere('notes', 'LIKE', '%'.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.'%');
            })
            ->orderBy('id')
            ->chunkById(500, function ($items) use (&$changed) {
                foreach ($items as $item) {
                    $cost = AdditionalCost::find($item->source_id);
                    if ($cost && self::seedFromScheduleItem($cost, $item)) {
                        $changed++;
                    }
                }
            });

        return $changed;
    }
}
