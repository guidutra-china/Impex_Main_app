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
        $payment->loadMissing(['allocations.scheduleItem.payable', 'allocations.scheduleItem.shipment']);

        $currency = $payment->currency_code ?? 'USD';
        $allocations = [];

        foreach ($payment->allocations as $allocation) {
            $scheduleItem = $allocation->scheduleItem;
            $payable = $scheduleItem?->payable;

            $documentType = match (true) {
                $payable instanceof \App\Domain\ProformaInvoices\Models\ProformaInvoice => __('navigation.models.proforma_invoice'),
                $payable instanceof \App\Domain\Logistics\Models\Shipment => __('navigation.models.shipment'),
                $payable instanceof \App\Domain\PurchaseOrders\Models\PurchaseOrder => __('navigation.models.purchase_order'),
                default => __('widgets.portal.document'),
            };

            $clientReference = null;
            if ($payable instanceof \App\Domain\ProformaInvoices\Models\ProformaInvoice) {
                $clientReference = $payable->client_reference;
            }

            $scheduleLabel = $scheduleItem?->label ?? '—';
            if ($scheduleLabel !== '—') {
                $shipment = $scheduleItem?->shipment;
                $blNumber = $shipment?->bl_number;

                // Replace [SH-xxxx / PI-xxxx] or [SH-xxxx / PO-xxxx] with [BL: xxx] or remove bracket entirely
                $scheduleLabel = preg_replace_callback('/\[([^\]]*)\]/', function ($matches) use ($blNumber) {
                    $content = $matches[1];
                    // Remove PI reference (e.g. " / PI-2026-00006" or "PI-2026-00006 / ")
                    $content = preg_replace('/\s*\/\s*PI-[^\]\/]+/', '', $content);
                    $content = preg_replace('/PI-[^\]\/]+\s*\/\s*/', '', $content);
                    // Replace SH reference with BL number
                    if ($blNumber) {
                        $content = preg_replace('/SH-[^\]\/]+/', 'BL: ' . $blNumber, $content);
                    }
                    $content = trim($content);

                    return $content ? '[' . $content . ']' : '';
                }, $scheduleLabel);

                $scheduleLabel = rtrim(trim($scheduleLabel), '—');
                $scheduleLabel = trim($scheduleLabel);
            }

            $allocations[] = [
                'document_type' => $documentType,
                'document_ref' => $payable?->reference ?? '—',
                'client_reference' => $clientReference,
                'schedule_label' => $scheduleLabel,
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
