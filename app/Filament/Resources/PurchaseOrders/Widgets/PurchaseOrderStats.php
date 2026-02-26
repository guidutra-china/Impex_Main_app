<?php

namespace App\Filament\Resources\PurchaseOrders\Widgets;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderStats extends BaseWidget
{
    protected static bool $isLazy = false;

    public ?Model $record = null;

    protected function getStats(): array
    {
        if (! $this->record instanceof PurchaseOrder) {
            return [];
        }

        $po = $this->record;
        $po->loadMissing(['items', 'paymentScheduleItems']);

        $currency = $po->currency_code ?? 'USD';
        $total = $po->total;

        $scheduleItems = $po->paymentScheduleItems;
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

        $unallocatedTotal = $this->getUnallocatedPaymentsTotal($po->supplier_company_id, PaymentDirection::OUTBOUND, $currency);

        $stats = [
            Stat::make('PO Total', $currency . ' ' . Money::format($total))
                ->description($totalCredits > 0
                    ? 'Credits: ' . $currency . ' ' . Money::format($totalCredits)
                    : $po->items->count() . ' item(s)')
                ->icon('heroicon-o-shopping-cart')
                ->color('primary'),

            Stat::make('Paid', $currency . ' ' . Money::format($totalPaid))
                ->description($progress . '% paid')
                ->icon('heroicon-o-banknotes')
                ->color($progress >= 100 ? 'success' : ($progress > 0 ? 'info' : 'gray')),

            Stat::make('Remaining', $currency . ' ' . Money::format($netRemaining))
                ->description($this->buildRemainingDescription($overdueAmount, $nextDue, $netRemaining, $currency))
                ->icon('heroicon-o-clock')
                ->color($overdueAmount > 0 ? 'danger' : ($netRemaining <= 0 ? 'success' : 'warning')),
        ];

        if ($unallocatedTotal > 0) {
            $stats[] = Stat::make('Unallocated Payments', $currency . ' ' . Money::format($unallocatedTotal))
                ->description('To this supplier — available to allocate')
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
