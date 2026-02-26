<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class PaymentsListStats extends Widget
{
    protected string $view = 'portal.widgets.payments-list-stats';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $query = Payment::where('company_id', $tenant->id);

        $total = $query->count();
        $approved = (clone $query)->where('status', PaymentStatus::APPROVED)->count();
        $pending = (clone $query)->where('status', PaymentStatus::PENDING_APPROVAL)->count();

        $payments = (clone $query)->get();
        $currency = $payments->first()?->currency_code ?? 'USD';

        $totalAmount = $payments->sum('amount');
        $approvedAmount = $payments->where('status', PaymentStatus::APPROVED)->sum('amount');
        $allocatedAmount = 0;

        foreach ($payments->where('status', PaymentStatus::APPROVED) as $payment) {
            $allocatedAmount += $payment->allocated_total;
        }

        $unallocatedAmount = max(0, $approvedAmount - $allocatedAmount);

        return [
            'total' => $total,
            'approved' => $approved,
            'pending' => $pending,
            'currency' => $currency,
            'totalAmount' => Money::format($totalAmount),
            'approvedAmount' => Money::format($approvedAmount),
            'allocatedAmount' => Money::format($allocatedAmount),
            'unallocatedAmount' => Money::format($unallocatedAmount),
        ];
    }
}
