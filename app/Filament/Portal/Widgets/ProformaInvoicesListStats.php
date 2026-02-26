<?php

namespace App\Filament\Portal\Widgets;

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
            $invoices = (clone $query)->with(['items', 'paymentScheduleItems'])->get();
            $currency = $invoices->first()?->currency_code ?? 'USD';

            $totalValue = $invoices->sum(fn ($pi) => $pi->total);
            $totalPaid = $invoices->sum(fn ($pi) => $pi->paymentScheduleItems->sum('paid_amount'));
            $totalRemaining = max(0, $totalValue - $totalPaid);
            $paymentProgress = $totalValue > 0 ? round(($totalPaid / $totalValue) * 100, 1) : 0;
        }

        return [
            'total' => $total,
            'confirmed' => $confirmed,
            'finalized' => $finalized,
            'pending' => $pending,
            'showFinancial' => $showFinancial,
            'totalValue' => $totalValue !== null ? Money::format($totalValue) : null,
            'totalPaid' => $totalPaid !== null ? Money::format($totalPaid) : null,
            'totalRemaining' => $totalRemaining !== null ? Money::format($totalRemaining) : null,
            'currency' => $currency,
            'paymentProgress' => $paymentProgress,
        ];
    }
}
