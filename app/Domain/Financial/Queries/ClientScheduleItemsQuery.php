<?php

namespace App\Domain\Financial\Queries;

use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tudo que um cliente deve ou já pagou à Impex, em qualquer status: parcelas
 * da PI (inclusive custos lançados na PI), custos do embarque cobráveis do
 * cliente e Debit Notes do cliente.
 *
 * Mesma população de OpenScheduleItemsQuery::receivables() filtrada pelo
 * cliente, mas sem o filtro de status — serve para quem precisa de "total",
 * "pago" e "em aberto" fechando entre si (painel e cronograma do portal).
 *
 * Os espelhos de Shipment (parcela da PI replicada no embarque, sem source)
 * nunca entram: a parcela já conta pela PI.
 *
 * Colunas qualificadas: o cronograma do portal ordena com join em tabelas que
 * também têm source_type/notes.
 */
final class ClientScheduleItemsQuery
{
    public static function forClient(int $companyId): Builder
    {
        return PaymentScheduleItem::query()
            ->where(function (Builder $query) use ($companyId) {
                $query->whereHasMorph('payable', [ProformaInvoice::class], fn ($q) => $q->where('company_id', $companyId))
                    ->orWhere(function (Builder $q) use ($companyId) {
                        $q->whereHasMorph('payable', [Shipment::class], fn ($sq) => $sq->where('company_id', $companyId))
                            ->where('payment_schedule_items.source_type', AdditionalCost::class)
                            ->whereIn(
                                'payment_schedule_items.source_id',
                                AdditionalCost::query()->where('billable_to', BillableTo::CLIENT)->select('id'),
                            );
                    })
                    ->orWhereHasMorph('payable', [DebitNote::class], fn ($q) => $q
                        ->where('company_id', $companyId)
                        ->where('party_type', PartyType::CLIENT->value));
            })
            ->where(function (Builder $query) {
                $query->whereNull('payment_schedule_items.notes')
                    ->orWhere(function (Builder $q) {
                        $q->where('payment_schedule_items.notes', 'not like', '%'.PaymentScheduleItem::FORWARDER_PAYABLE_TAG.'%')
                            ->where('payment_schedule_items.notes', 'not like', '%'.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.'%');
                    });
            });
    }
}
