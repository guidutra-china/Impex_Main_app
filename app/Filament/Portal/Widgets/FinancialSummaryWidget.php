<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Queries\ClientScheduleItemsQuery;
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

        // Mesma população do Próximos Pagamentos e do cronograma: parcelas da
        // PI, custos do embarque cobráveis do cliente e DNs do cliente — sem
        // crédito, sem espelho de Shipment e sem documento cancelado.
        $scheduleItems = ClientScheduleItemsQuery::forClient($companyId)
            ->where('is_credit', false)
            ->payableNotCancelled()
            ->with('allocations.payment')
            ->get();

        // Waived foi perdoada: não é dívida nem pagamento.
        $billable = $scheduleItems->reject(fn ($item) => $item->status === PaymentScheduleStatus::WAIVED);

        $totalBilled = (int) $billable->sum('amount');

        // Pendente = SALDO restante das parcelas abertas (pending + due + overdue).
        $totalPending = (int) $billable
            ->reject(fn ($item) => $item->status->isResolved())
            ->sum(fn ($item) => max(0, (int) $item->amount - (int) $item->paid_amount));

        // Pago = o que foi cobrado menos o que está em aberto, para o card
        // fechar a conta Total − Pago = Pendente. Difere da soma das alocações
        // só por sobra de centavos em parcela já quitada.
        $totalPaid = $totalBilled - $totalPending;

        return [
            Stat::make(__('widgets.portal.total_pi_value'), 'USD '.Money::format($totalPiValue))
                ->description(__('widgets.portal.confirmed_proforma_invoices'))
                ->icon('heroicon-o-document-check')
                ->color('primary'),
            Stat::make(__('widgets.portal.total_billed'), 'USD '.Money::format($totalBilled))
                ->description(__('widgets.portal.total_billed_desc'))
                ->icon('heroicon-o-banknotes')
                ->color('gray'),
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
