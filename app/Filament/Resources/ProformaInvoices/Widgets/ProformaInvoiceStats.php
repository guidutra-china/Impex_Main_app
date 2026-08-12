<?php

namespace App\Filament\Resources\ProformaInvoices\Widgets;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ProformaInvoiceStats extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.document-financial-summary';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof ProformaInvoice) {
            return $this->emptyState();
        }

        $pi = $this->record;
        $pi->loadMissing(['items', 'paymentScheduleItems.allocations.payment', 'additionalCosts']);

        $currency = $pi->currency_code ?? 'USD';
        $total = $pi->grand_total;
        $costTotal = $pi->cost_total;
        $margin = $pi->margin;

        $hasMultiCurrency = $pi->items->contains(
            fn ($item) => $item->cost_currency_code
                && $item->cost_currency_code !== $currency
        );

        $scheduleItems = $pi->paymentScheduleItems->sortBy('sort_order');
        $regularItems = $scheduleItems->where('is_credit', false);
        $creditItems = $scheduleItems->where('is_credit', true);

        $totalDue = $regularItems->sum('amount');
        $totalCredits = $creditItems->sum(fn ($i) => abs($i->amount));
        $netDue = $totalDue - $totalCredits;
        $totalPaid = $regularItems->sum(fn ($i) => $i->paid_amount);

        // Parcelas isentas (waived) contam como quitadas no saldo exibido.
        $waivedTotal = $regularItems
            ->where('status', PaymentScheduleStatus::WAIVED)
            ->sum(fn ($i) => $i->remaining_amount);

        $netRemaining = max(0, $netDue - $totalPaid - $waivedTotal);
        $progress = $netDue > 0 ? (int) round((($totalPaid + $waivedTotal) / $netDue) * 100) : 0;
        $paidProgress = $netDue > 0 ? (int) round(($totalPaid / $netDue) * 100) : 0;

        $overdueAmount = $regularItems
            ->where('status', PaymentScheduleStatus::OVERDUE)
            ->sum(fn ($i) => $i->remaining_amount);

        $unallocatedTotal = $this->getUnallocatedPaymentsTotal(
            $pi->company_id,
            PaymentDirection::INBOUND,
            $currency,
        );

        $cards = [
            [
                'label' => __('widgets.document_summary.invoice_total'),
                'value' => $currency.' '.Money::format($total, 2),
                'description' => $pi->client_billable_costs_total !== 0
                    ? 'Incl. '.$currency.' '.Money::format($pi->client_billable_costs_total, 2).' additional costs'
                    : $pi->items->count().' item(s)',
                'icon' => 'heroicon-o-document-currency-dollar',
                'color' => 'primary',
            ],
            [
                'label' => __('widgets.document_summary.cost_margin'),
                'value' => $currency.' '.Money::format($costTotal),
                'description' => __('widgets.document_summary.margin').': '.$margin.'%'
                    .($hasMultiCurrency ? ' · '.__('widgets.document_summary.fx_snapshot') : ''),
                'icon' => 'heroicon-o-calculator',
                'color' => $margin > 0 ? 'success' : 'danger',
            ],
            [
                'label' => __('widgets.document_summary.paid'),
                'value' => $currency.' '.Money::format($totalPaid),
                'description' => $paidProgress.'% received',
                'icon' => 'heroicon-o-banknotes',
                'color' => $progress >= 100 ? 'success' : ($progress > 0 ? 'info' : 'gray'),
            ],
            [
                'label' => __('widgets.document_summary.remaining'),
                'value' => $currency.' '.Money::format($netRemaining),
                'description' => $this->buildRemainingDescription($overdueAmount, $regularItems, $netRemaining, $currency),
                'icon' => 'heroicon-o-clock',
                'color' => $overdueAmount > 0 ? 'danger' : ($netRemaining <= 0 ? 'success' : 'warning'),
            ],
        ];

        if ($waivedTotal > 0) {
            $cards[] = [
                'label' => __('widgets.document_summary.waived'),
                'value' => $currency.' '.Money::format($waivedTotal),
                'description' => __('widgets.document_summary.waived_description'),
                'icon' => 'heroicon-o-receipt-percent',
                'color' => 'info',
            ];
        }

        $scheduleItems->loadMissing('shipment');

        $mappedSchedule = $scheduleItems->values()->map(fn ($item) => [
            'label' => $item->label,
            'label_clean' => preg_replace('/\s*\x{2014}\s*\[.*\]\s*$/u', '', $item->label ?? ''),
            'shipment_ref' => $item->shipment ? ($item->shipment->bl_number ?: $item->shipment->reference) : null,
            'status_value' => $item->status->value,
            'status_label' => $item->status->getLabel().($item->is_overpaid ? ' · Overpaid' : ''),
            'status_color' => $item->is_overpaid ? 'warning' : $item->status->getColor(),
            'status_icon' => $item->is_overpaid ? 'heroicon-o-exclamation-triangle' : $item->status->getIcon(),
            'due_date' => $item->due_date?->format('M d, Y'),
            'due_date_sort' => $item->due_date?->format('Y-m-d') ?? '',
            'percentage' => $item->percentage,
            'amount' => Money::format(abs($item->amount)),
            'amount_raw' => abs($item->amount),
            'paid' => Money::format($item->paid_amount),
            'paid_raw' => $item->paid_amount,
            'remaining' => Money::format($item->status === PaymentScheduleStatus::WAIVED ? 0 : $item->remaining_amount),
            'remaining_raw' => $item->status === PaymentScheduleStatus::WAIVED ? 0 : $item->remaining_amount,
            'is_credit' => $item->is_credit,
            'is_blocking' => $item->is_blocking,
            'is_overpaid' => $item->is_overpaid,
            'overpaid_amount' => Money::format($item->overpaid_amount),
            'overpaid_amount_raw' => $item->overpaid_amount,
        ])->all();

        $statusOptions = $scheduleItems
            ->pluck('status')
            ->unique()
            ->map(fn ($s) => ['value' => $s->value, 'label' => $s->getLabel()])
            ->values()
            ->all();

        // Linha de total da tabela: líquido de créditos (descontos), como os cards.
        $totalScheduleAmount = $netDue;
        $totalSchedulePaid = $totalPaid;
        $totalScheduleRemaining = $netRemaining;
        $totalOverpaid = $regularItems->sum(fn ($i) => $i->overpaid_amount);

        return [
            'heading' => __('widgets.document_summary.financial_summary'),
            'icon' => 'heroicon-o-banknotes',
            'currency' => $currency,
            'cards' => $cards,
            'progress' => $progress,
            'scheduleItems' => $mappedSchedule,
            'statusOptions' => $statusOptions,
            'totals' => [
                'amount' => Money::format($totalScheduleAmount),
                'paid' => Money::format($totalSchedulePaid),
                'remaining' => Money::format($totalScheduleRemaining),
                'remaining_raw' => $totalScheduleRemaining,
                'overpaid' => Money::format($totalOverpaid),
                'overpaid_raw' => $totalOverpaid,
            ],
            'unallocatedTotal' => $unallocatedTotal,
            'unallocatedFormatted' => Money::format($unallocatedTotal),
            'unallocatedLabel' => __('widgets.document_summary.from_client_available'),
        ];
    }

    private function buildRemainingDescription(int $overdueAmount, $regularItems, int $netRemaining, string $currency): string
    {
        if ($netRemaining <= 0) {
            return __('widgets.document_summary.fully_paid');
        }

        $parts = [];

        if ($overdueAmount > 0) {
            $parts[] = __('widgets.document_summary.overdue').': '.$currency.' '.Money::format($overdueAmount);
        }

        $nextDue = $regularItems
            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE])
            ->sortBy('due_date')
            ->first();

        if ($nextDue?->due_date) {
            $parts[] = __('widgets.document_summary.next').': '.$nextDue->due_date->format('M d, Y');
        }

        return implode(' | ', $parts) ?: __('widgets.document_summary.outstanding');
    }

    private function getUnallocatedPaymentsTotal(int $companyId, PaymentDirection $direction, string $currency): int
    {
        return Payment::where('company_id', $companyId)
            ->where('direction', $direction)
            ->where('status', PaymentStatus::APPROVED)
            ->where('currency_code', $currency)
            ->get()
            ->sum(fn ($p) => $p->unallocated_amount);
    }

    private function emptyState(): array
    {
        return [
            'heading' => 'Financial Summary',
            'icon' => 'heroicon-o-banknotes',
            'currency' => 'USD',
            'cards' => [],
            'progress' => null,
            'scheduleItems' => [],
            'statusOptions' => [],
            'totals' => ['amount' => '0.00', 'paid' => '0.00', 'remaining' => '0.00', 'remaining_raw' => 0],
            'unallocatedTotal' => 0,
            'unallocatedFormatted' => '0.00',
            'unallocatedLabel' => '',
        ];
    }
}
