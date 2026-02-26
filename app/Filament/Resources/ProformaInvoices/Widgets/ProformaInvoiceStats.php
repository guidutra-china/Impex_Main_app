<?php

namespace App\Filament\Resources\ProformaInvoices\Widgets;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class ProformaInvoiceStats extends BaseWidget
{
    protected static bool $isLazy = false;

    public ?Model $record = null;

    protected function getStats(): array
    {
        if (! $this->record instanceof ProformaInvoice) {
            return [];
        }

        $pi = $this->record;
        $pi->loadMissing(['items', 'paymentScheduleItems', 'additionalCosts']);

        $currency = $pi->currency_code ?? 'USD';
        $total = $pi->total;
        $costTotal = $pi->cost_total;
        $margin = $pi->margin;

        $scheduleItems = $pi->paymentScheduleItems;
        $regularItems = $scheduleItems->where('is_credit', false);
        $creditItems = $scheduleItems->where('is_credit', true);

        $totalDue = $regularItems->sum('amount');
        $totalCredits = $creditItems->sum(fn ($i) => abs($i->amount));
        $netDue = $totalDue - $totalCredits;
        $totalPaid = $regularItems->sum(fn ($i) => $i->paid_amount);
        $netRemaining = max(0, $netDue - $totalPaid);
        $progress = $netDue > 0 ? round(($totalPaid / $netDue) * 100) : 0;

        $overdueItems = $regularItems->where('status', PaymentScheduleStatus::OVERDUE);
        $overdueAmount = $overdueItems->sum(fn ($i) => $i->remaining_amount);

        $nextDue = $regularItems
            ->whereIn('status', [PaymentScheduleStatus::PENDING, PaymentScheduleStatus::DUE])
            ->sortBy('due_date')
            ->first();

        $unallocatedTotal = $this->getUnallocatedPaymentsTotal($pi->company_id, PaymentDirection::INBOUND, $currency);

        $stats = [
            Stat::make('Invoice Total', $currency . ' ' . Money::format($total))
                ->description($totalCredits > 0
                    ? 'Credits: ' . $currency . ' ' . Money::format($totalCredits)
                    : $pi->items->count() . ' item(s)')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('primary'),

            Stat::make('Cost Total', $currency . ' ' . Money::format($costTotal))
                ->description('Margin: ' . $margin . '%')
                ->icon('heroicon-o-calculator')
                ->color($margin > 0 ? 'success' : 'danger'),

            Stat::make('Paid', $currency . ' ' . Money::format($totalPaid))
                ->description($progress . '% received')
                ->icon('heroicon-o-banknotes')
                ->color($progress >= 100 ? 'success' : ($progress > 0 ? 'info' : 'gray')),

            Stat::make('Remaining', $currency . ' ' . Money::format($netRemaining))
                ->description($this->buildRemainingDescription($overdueAmount, $nextDue, $netRemaining, $currency))
                ->icon('heroicon-o-clock')
                ->color($overdueAmount > 0 ? 'danger' : ($netRemaining <= 0 ? 'success' : 'warning')),
        ];

        if ($unallocatedTotal > 0) {
            $stats[] = Stat::make('Unallocated Payments', $currency . ' ' . Money::format($unallocatedTotal))
                ->description('From this client — available to allocate')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning');
        }

        return $stats;
    }

    private function buildRemainingDescription(int $overdueAmount, ?object $nextDue, int $netRemaining, string $currency): string
    {
        if ($netRemaining <= 0) {
            return 'Fully paid';
        }

        $parts = [];

        if ($overdueAmount > 0) {
            $parts[] = 'Overdue: ' . $currency . ' ' . Money::format($overdueAmount);
        }

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
}
