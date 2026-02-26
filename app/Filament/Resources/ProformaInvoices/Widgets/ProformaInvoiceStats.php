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

    protected int | string | array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof ProformaInvoice) {
            return $this->emptyState();
        }

        $pi = $this->record;
        $pi->loadMissing(['items', 'paymentScheduleItems.allocations.payment', 'additionalCosts']);

        $currency = $pi->currency_code ?? 'USD';
        $total = $pi->total;
        $costTotal = $pi->cost_total;
        $margin = $pi->margin;

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

        $unallocatedTotal = $this->getUnallocatedPaymentsTotal(
            $pi->company_id,
            PaymentDirection::INBOUND,
            $currency,
        );

        $cards = [
            [
                'label' => 'Invoice Total',
                'value' => $currency . ' ' . Money::format($total),
                'description' => $totalCredits > 0
                    ? 'Credits: ' . $currency . ' ' . Money::format($totalCredits)
                    : $pi->items->count() . ' item(s)',
                'icon' => 'heroicon-o-document-currency-dollar',
                'color' => 'primary',
            ],
            [
                'label' => 'Cost / Margin',
                'value' => $currency . ' ' . Money::format($costTotal),
                'description' => 'Margin: ' . $margin . '%',
                'icon' => 'heroicon-o-calculator',
                'color' => $margin > 0 ? 'success' : 'danger',
            ],
            [
                'label' => 'Paid',
                'value' => $currency . ' ' . Money::format($totalPaid),
                'description' => $progress . '% received',
                'icon' => 'heroicon-o-banknotes',
                'color' => $progress >= 100 ? 'success' : ($progress > 0 ? 'info' : 'gray'),
            ],
            [
                'label' => 'Remaining',
                'value' => $currency . ' ' . Money::format($netRemaining),
                'description' => $this->buildRemainingDescription($overdueAmount, $regularItems, $netRemaining, $currency),
                'icon' => 'heroicon-o-clock',
                'color' => $overdueAmount > 0 ? 'danger' : ($netRemaining <= 0 ? 'success' : 'warning'),
            ],
        ];

        $mappedSchedule = $scheduleItems->values()->map(fn ($item) => [
            'label' => $item->label,
            'status' => $item->status,
            'due_date' => $item->due_date?->format('M d, Y'),
            'percentage' => $item->percentage,
            'amount' => Money::format(abs($item->amount)),
            'paid' => Money::format($item->paid_amount),
            'remaining' => Money::format($item->remaining_amount),
            'remaining_raw' => $item->remaining_amount,
            'is_credit' => $item->is_credit,
            'is_blocking' => $item->is_blocking,
        ])->all();

        $totalScheduleAmount = $regularItems->sum('amount');
        $totalSchedulePaid = $regularItems->sum(fn ($i) => $i->paid_amount);
        $totalScheduleRemaining = max(0, $totalScheduleAmount - $totalSchedulePaid);

        return [
            'heading' => 'Financial Summary',
            'icon' => 'heroicon-o-banknotes',
            'currency' => $currency,
            'cards' => $cards,
            'progress' => $progress,
            'scheduleItems' => $mappedSchedule,
            'totals' => [
                'amount' => Money::format($totalScheduleAmount),
                'paid' => Money::format($totalSchedulePaid),
                'remaining' => Money::format($totalScheduleRemaining),
                'remaining_raw' => $totalScheduleRemaining,
            ],
            'unallocatedTotal' => $unallocatedTotal,
            'unallocatedFormatted' => Money::format($unallocatedTotal),
            'unallocatedLabel' => 'From this client — available to allocate',
        ];
    }

    private function buildRemainingDescription(int $overdueAmount, $regularItems, int $netRemaining, string $currency): string
    {
        if ($netRemaining <= 0) {
            return 'Fully paid';
        }

        $parts = [];

        if ($overdueAmount > 0) {
            $parts[] = 'Overdue: ' . $currency . ' ' . Money::format($overdueAmount);
        }

        $nextDue = $regularItems
            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE])
            ->sortBy('due_date')
            ->first();

        if ($nextDue?->due_date) {
            $parts[] = 'Next: ' . $nextDue->due_date->format('M d, Y');
        }

        return implode(' | ', $parts) ?: 'Outstanding';
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
            'totals' => ['amount' => '0.00', 'paid' => '0.00', 'remaining' => '0.00', 'remaining_raw' => 0],
            'unallocatedTotal' => 0,
            'unallocatedFormatted' => '0.00',
            'unallocatedLabel' => '',
        ];
    }
}
