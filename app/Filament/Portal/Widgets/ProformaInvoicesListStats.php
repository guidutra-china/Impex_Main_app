<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class ProformaInvoicesListStats extends Widget
{
    protected string $view = 'portal.widgets.proforma-invoices-list-stats';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $query = ProformaInvoice::where('company_id', $tenant->id);

        $total = $query->count();
        $confirmed = (clone $query)->where('status', ProformaInvoiceStatus::CONFIRMED)->count();
        $finalized = (clone $query)->where('status', ProformaInvoiceStatus::FINALIZED)->count();
        $pending = (clone $query)->whereIn('status', [
            ProformaInvoiceStatus::DRAFT,
            ProformaInvoiceStatus::SENT,
        ])->count();

        $showFinancial = auth()->user()?->can('portal:view-financial-summary');

        $totalValue = null;
        $totalPaid = null;
        $totalRemaining = null;
        $currency = null;
        $paymentProgress = 0;

        if ($showFinancial) {
            $invoices = (clone $query)->with(['items', 'paymentScheduleItems', 'additionalCosts'])->get();
            $currency = $invoices->first()?->currency_code ?? 'USD';

            $totalValue = $invoices->sum(fn ($pi) => $pi->grand_total);

            // paid_amount inclui o crédito (desconto) aplicado na parcela, e o
            // grand_total destas PIs já vem líquido dos descontos delas: crédito
            // das próprias PIs não conta como pago, senão o desconto entra em
            // dobro. Crédito de outro documento (ex.: Credit Note) conta.
            $scheduleItems = $invoices->flatMap(fn ($pi) => $pi->paymentScheduleItems);
            $ownCreditItemIds = $scheduleItems->where('is_credit', true)->pluck('id');

            $totalPaid = (int) PaymentAllocation::query()
                ->whereIn('payment_schedule_item_id', $scheduleItems->where('is_credit', false)->pluck('id'))
                ->where(fn ($q) => $q
                    ->whereNull('credit_schedule_item_id')
                    ->orWhereNotIn('credit_schedule_item_id', $ownCreditItemIds))
                ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
                ->sum('allocated_amount_in_document_currency');

            // Parcelas isentas (waived) contam como quitadas no saldo do Portal.
            $totalWaived = $invoices->sum(
                fn ($pi) => $pi->paymentScheduleItems
                    ->where('is_credit', false)
                    ->where('status', PaymentScheduleStatus::WAIVED)
                    ->sum(fn ($i) => $i->remaining_amount)
            );

            $totalRemaining = max(0, $totalValue - $totalPaid - $totalWaived);
            $paymentProgress = $totalValue > 0 ? round((($totalPaid + $totalWaived) / $totalValue) * 100, 1) : 0;
        }

        return [
            'total' => $total,
            'confirmed' => $confirmed,
            'finalized' => $finalized,
            'pending' => $pending,
            'showFinancial' => $showFinancial,
            'totalValue' => $totalValue !== null ? Money::format($totalValue, 2) : null,
            'totalPaid' => $totalPaid !== null ? Money::format($totalPaid, 2) : null,
            'totalRemaining' => $totalRemaining !== null ? Money::format($totalRemaining, 2) : null,
            'currency' => $currency,
            'paymentProgress' => $paymentProgress,
        ];
    }
}
