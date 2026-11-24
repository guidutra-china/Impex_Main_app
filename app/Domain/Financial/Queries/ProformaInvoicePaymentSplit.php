<?php

namespace App\Domain\Financial\Queries;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pago da PI separado em dois grupos: produtos e outros custos.
 *
 * A parcela é de PRODUTO quando nasce de uma etapa do payment term
 * (source_type nulo) e de OUTROS CUSTOS quando nasce de um AdditionalCost
 * (comissão, frete, desconto…). Só o lado do cliente entra: parcelas com tag
 * de lado a pagar (forwarder/fornecedor) ficam de fora.
 *
 * "Pago" é o que foi alocado, com pagamento APPROVED, nas parcelas normais
 * do grupo: dinheiro mais crédito aplicado (desconto, credit note). É a
 * mesma leitura do paid_amount da parcela, então uma parcela PAID sempre
 * aparece como paga aqui.
 *
 * As parcelas de crédito em si não entram. Um desconto não é um custo
 * cobrado nem um pagamento negativo: ele aparece como pago na parcela onde
 * foi usado. Subtrair o consumo no grupo do crédito deixava "pago de
 * outros" negativo enquanto a comissão seguia aberta (PI-2026-00070).
 *
 * Tudo em subselects na própria query da lista: uma consulta por página,
 * sem N+1, e as colunas ficam ordenáveis. SQL portátil (MySQL e SQLite).
 */
final class ProformaInvoicePaymentSplit
{
    public const PRODUCTS_PAID = 'products_paid_amount';

    public const PRODUCTS_WAIVED = 'products_waived_amount';

    public const OTHER_COSTS_PAID = 'other_costs_paid_amount';

    public const OTHER_COSTS_WAIVED = 'other_costs_waived_amount';

    /**
     * @param  Builder<ProformaInvoice>  $query
     * @return Builder<ProformaInvoice>
     */
    public static function addSelects(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->getModel()->qualifyColumn('*'));
        }

        $key = $query->getModel()->getQualifiedKeyName();
        $morph = $query->getModel()->getMorphClass();

        foreach ([
            [false, self::PRODUCTS_PAID, self::PRODUCTS_WAIVED],
            [true, self::OTHER_COSTS_PAID, self::OTHER_COSTS_WAIVED],
        ] as [$costs, $paidAlias, $waivedAlias]) {
            [$paidSql, $paidBindings] = self::paidExpression($key, $morph, $costs);
            [$waivedSql, $waivedBindings] = self::waivedExpression($key, $morph, $costs);

            $query->selectRaw("({$paidSql}) as {$paidAlias}", $paidBindings);
            $query->selectRaw("({$waivedSql}) as {$waivedAlias}", $waivedBindings);
        }

        return $query;
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function paidExpression(string $key, string $morph, bool $costs): array
    {
        [$bucket, $bucketBindings] = self::bucketCondition('psi', $key, $morph, $costs);
        [$approved, $approvedBindings] = self::approvedPaymentCondition();

        $paid = <<<SQL
            select coalesce(sum(pa.allocated_amount_in_document_currency), 0)
            from payment_allocations pa
            inner join payments p on p.id = pa.payment_id
            inner join payment_schedule_items psi on psi.id = pa.payment_schedule_item_id
            where {$approved} and {$bucket}
            SQL;

        return [$paid, [...$approvedBindings, ...$bucketBindings]];
    }

    /**
     * Saldo isento: valor das parcelas WAIVED menos o que já tinha sido pago
     * nelas antes da isenção.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function waivedExpression(string $key, string $morph, bool $costs): array
    {
        [$bucket, $bucketBindings] = self::bucketCondition('psi', $key, $morph, $costs);
        [$approved, $approvedBindings] = self::approvedPaymentCondition();
        $waived = 'psi.status = ?';
        $waivedBinding = PaymentScheduleStatus::WAIVED->value;

        $amount = <<<SQL
            select coalesce(sum(psi.amount), 0)
            from payment_schedule_items psi
            where {$bucket} and {$waived}
            SQL;

        $paid = <<<SQL
            select coalesce(sum(pa.allocated_amount_in_document_currency), 0)
            from payment_allocations pa
            inner join payments p on p.id = pa.payment_id
            inner join payment_schedule_items psi on psi.id = pa.payment_schedule_item_id
            where {$approved} and {$bucket} and {$waived}
            SQL;

        return [
            "({$amount}) - ({$paid})",
            [...$bucketBindings, $waivedBinding, ...$approvedBindings, ...$bucketBindings, $waivedBinding],
        ];
    }

    /**
     * Parcelas normais (não crédito) do lado do cliente desta PI, do grupo
     * pedido.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function bucketCondition(string $alias, string $key, string $morph, bool $costs): array
    {
        $source = $costs ? 'is not null' : 'is null';

        return [
            "{$alias}.payable_type = ? and {$alias}.payable_id = {$key}"
            ." and {$alias}.source_type {$source} and {$alias}.is_credit = 0"
            ." and ({$alias}.notes is null or ({$alias}.notes not like ? and {$alias}.notes not like ?))",
            [
                $morph,
                '%'.PaymentScheduleItem::FORWARDER_PAYABLE_TAG.'%',
                '%'.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.'%',
            ],
        ];
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private static function approvedPaymentCondition(): array
    {
        return ['p.status = ? and p.deleted_at is null', [PaymentStatus::APPROVED->value]];
    }
}
