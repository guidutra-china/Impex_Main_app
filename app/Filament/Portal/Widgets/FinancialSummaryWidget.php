<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FinancialSummaryWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->can('portal:view-financial-summary') ?? false;
    }

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return [];
        }

        $companyId = $tenant->getKey();

        $totalPiValue = ProformaInvoice::where('company_id', $companyId)
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->get()
            ->sum(fn ($pi) => $pi->total);

        // Mesma família de predicados do Contas a Pagar do portal: parcelas de
        // PI não cancelada + custos adicionais por shipment (frete, comissão),
        // sem linhas de crédito nem espelhos forwarder/supplier-payable.
        $scheduleItems = PaymentScheduleItem::query()
            ->where(function ($outer) use ($companyId) {
                $outer->where(function ($q) use ($companyId) {
                    $q->where('payable_type', ProformaInvoice::class)
                        ->whereHasMorph('payable', [ProformaInvoice::class], fn ($sub) => $sub
                            ->where('company_id', $companyId)
                            ->where('status', '!=', 'cancelled'));
                })->orWhere(function ($q) use ($companyId) {
                    $q->where('payable_type', \App\Domain\Logistics\Models\Shipment::class)
                        ->where('source_type', \App\Domain\Financial\Models\AdditionalCost::class)
                        ->whereHasMorph('payable', [\App\Domain\Logistics\Models\Shipment::class], fn ($sub) => $sub
                            ->where('company_id', $companyId)
                            ->where('status', '!=', 'cancelled'));
                });
            })
            ->where('is_credit', false)
            ->withoutSideTags()
            ->with('allocations.payment')
            ->get();

        // Pago = alocações aprovadas (captura pagamentos parciais); pendente =
        // SALDO restante das parcelas abertas (pending + due + overdue —
        // "due" ficava de fora e parcialmente pagas entravam pelo valor cheio).
        $totalPaid = $scheduleItems->sum(fn ($item) => $item->paid_amount);

        $totalPending = $scheduleItems
            ->whereIn('status', [
                PaymentScheduleStatus::PENDING,
                PaymentScheduleStatus::DUE,
                PaymentScheduleStatus::OVERDUE,
            ])
            ->sum(fn ($item) => max(0, (int) $item->amount - (int) $item->paid_amount));

        return [
            Stat::make(__('widgets.portal.total_pi_value'), 'USD '.Money::format($totalPiValue))
                ->description(__('widgets.portal.confirmed_proforma_invoices'))
                ->icon('heroicon-o-document-check')
                ->color('primary'),
            Stat::make(__('widgets.portal.total_paid'), 'USD '.Money::format($totalPaid))
                ->description(__('widgets.portal.payments_received'))
                ->icon('heroicon-o-check-circle')
                ->color('success'),
            Stat::make(__('widgets.portal.pending_balance'), 'USD '.Money::format($totalPending))
                ->description(__('widgets.portal.outstanding_payments'))
                ->icon('heroicon-o-clock')
                ->color($totalPending > 0 ? 'warning' : 'success'),
        ];
    }
}
