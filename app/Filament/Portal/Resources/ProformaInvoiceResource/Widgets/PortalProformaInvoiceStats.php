<?php

namespace App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class PortalProformaInvoiceStats extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.document-financial-summary';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    public static function canView(): bool
    {
        return auth()->user()?->can('portal:view-financial-summary') ?? false;
    }

    protected function getViewData(): array
    {
        if (! $this->record instanceof ProformaInvoice) {
            return $this->emptyState();
        }

        $pi = $this->record;
        $pi->loadMissing(['items', 'paymentScheduleItems.allocations.payment']);

        $currency = $pi->currency_code ?? 'USD';
        $pi->loadMissing('additionalCosts');
        $total = $pi->grand_total;

        $scheduleItems = $pi->paymentScheduleItems->sortBy('sort_order');
        $regularItems = $scheduleItems->where('is_credit', false);
        $creditItems = $scheduleItems->where('is_credit', true);

        $totalDue = $regularItems->sum('amount');
        $totalCredits = $creditItems->sum(fn ($i) => abs($i->amount));
        $netDue = $totalDue - $totalCredits;
        $totalPaid = $regularItems->sum(fn ($i) => $i->paid_amount);
        $netRemaining = max(0, $netDue - $totalPaid);
        $progress = $netDue > 0 ? (int) round(($totalPaid / $netDue) * 100) : 0;

        $overdueAmount = $regularItems
            ->where('status', PaymentScheduleStatus::OVERDUE)
            ->sum(fn ($i) => $i->remaining_amount);

        $cards = [
            [
                'label' => __('widgets.document_summary.invoice_total'),
                'value' => $currency . ' ' . Money::format($total, 2),
                'description' => $pi->client_billable_costs_total > 0
                    ? 'Incl. ' . $currency . ' ' . Money::format($pi->client_billable_costs_total, 2) . ' additional costs'
                    : $pi->items->count() . ' item(s)',
                'icon' => 'heroicon-o-document-currency-dollar',
                'color' => 'primary',
            ],
            [
                'label' => __('widgets.document_summary.paid'),
                'value' => $currency . ' ' . Money::format($totalPaid),
                'description' => $progress . '% ' . __('widgets.portal.received'),
                'icon' => 'heroicon-o-banknotes',
                'color' => $progress >= 100 ? 'success' : ($progress > 0 ? 'info' : 'gray'),
            ],
            [
                'label' => __('widgets.document_summary.remaining'),
                'value' => $currency . ' ' . Money::format($netRemaining),
                'description' => $this->buildRemainingDescription($overdueAmount, $regularItems, $netRemaining, $currency),
                'icon' => 'heroicon-o-clock',
                'color' => $overdueAmount > 0 ? 'danger' : ($netRemaining <= 0 ? 'success' : 'warning'),
            ],
        ];

        if ($overdueAmount > 0) {
            $cards[] = [
                'label' => __('widgets.document_summary.overdue'),
                'value' => $currency . ' ' . Money::format($overdueAmount),
                'description' => __('widgets.portal.requires_attention'),
                'icon' => 'heroicon-o-exclamation-triangle',
                'color' => 'danger',
            ];
        }

        $mappedSchedule = $scheduleItems->values()->map(fn ($item) => [
            'label' => $item->label,
            'status' => $item->status,
            'status_value' => $item->status->value,
            'status_label' => $item->status->getLabel(),
            'status_color' => $item->status->getColor(),
            'status_icon' => $item->status->getIcon(),
            'due_date' => $item->due_date?->format('M d, Y'),
            'due_date_sort' => $item->due_date?->format('Y-m-d') ?? '',
            'percentage' => $item->percentage,
            'amount' => Money::format(abs($item->amount)),
            'amount_raw' => abs($item->amount),
            'paid' => Money::format($item->paid_amount),
            'paid_raw' => $item->paid_amount,
            'remaining' => Money::format($item->remaining_amount),
            'remaining_raw' => $item->remaining_amount,
            'is_credit' => $item->is_credit,
            'is_blocking' => $item->is_blocking,
        ])->all();

        $totalScheduleAmount = $regularItems->sum('amount');
        $totalSchedulePaid = $regularItems->sum(fn ($i) => $i->paid_amount);
        $totalScheduleRemaining = max(0, $totalScheduleAmount - $totalSchedulePaid);

        $statusOptions = $scheduleItems
            ->pluck('status')
            ->unique()
            ->map(fn ($s) => ['value' => $s->value, 'label' => $s->getLabel()])
            ->values()
            ->all();

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
            ],
            'unallocatedTotal' => 0,
            'unallocatedFormatted' => '0.00',
            'unallocatedLabel' => '',
        ];
    }

    private function buildRemainingDescription(int $overdueAmount, $regularItems, int $netRemaining, string $currency): string
    {
        if ($netRemaining <= 0) {
            return __('widgets.document_summary.fully_paid');
        }

        $parts = [];

        if ($overdueAmount > 0) {
            $parts[] = __('widgets.document_summary.overdue') . ': ' . $currency . ' ' . Money::format($overdueAmount);
        }

        $nextDue = $regularItems
            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE])
            ->sortBy('due_date')
            ->first();

        if ($nextDue?->due_date) {
            $parts[] = __('widgets.document_summary.next') . ': ' . $nextDue->due_date->format('M d, Y');
        }

        return implode(' | ', $parts) ?: __('widgets.document_summary.outstanding');
    }

    private function emptyState(): array
    {
        return [
            'heading' => __('widgets.document_summary.financial_summary'),
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
