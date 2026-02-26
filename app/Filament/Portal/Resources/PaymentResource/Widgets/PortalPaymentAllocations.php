<?php

namespace App\Filament\Portal\Resources\PaymentResource\Widgets;

use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class PortalPaymentAllocations extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'portal.widgets.payment-allocations';

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof Payment) {
            return ['allocations' => [], 'currency' => 'USD', 'totalAllocated' => '0.00', 'totalUnallocated' => '0.00'];
        }

        $payment = $this->record;
        $payment->loadMissing(['allocations.scheduleItem.payable']);

        $currency = $payment->currency_code ?? 'USD';
        $allocations = [];

        foreach ($payment->allocations as $allocation) {
            $scheduleItem = $allocation->scheduleItem;
            $payable = $scheduleItem?->payable;

            $documentType = match (true) {
                $payable instanceof \App\Domain\ProformaInvoices\Models\ProformaInvoice => 'Proforma Invoice',
                $payable instanceof \App\Domain\Logistics\Models\Shipment => 'Shipment',
                $payable instanceof \App\Domain\PurchaseOrders\Models\PurchaseOrder => 'Purchase Order',
                default => 'Document',
            };

            $allocations[] = [
                'document_type' => $documentType,
                'document_ref' => $payable?->reference ?? '—',
                'schedule_label' => $scheduleItem?->label ?? '—',
                'amount' => Money::format($allocation->allocated_amount_in_document_currency ?: $allocation->allocated_amount),
                'amount_raw' => $allocation->allocated_amount_in_document_currency ?: $allocation->allocated_amount,
            ];
        }

        $totalAllocated = $payment->allocated_total;
        $totalUnallocated = $payment->unallocated_amount;

        return [
            'allocations' => $allocations,
            'currency' => $currency,
            'totalAllocated' => Money::format($totalAllocated),
            'totalUnallocated' => Money::format($totalUnallocated),
            'totalUnallocatedRaw' => $totalUnallocated,
            'paymentAmount' => Money::format($payment->amount),
        ];
    }
}
