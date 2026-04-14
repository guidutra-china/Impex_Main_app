<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

final class PaymentSectionBuilder implements FinancialSectionBuilder
{
    public function key(): string
    {
        return 'payments';
    }

    public function build(Company $company, FinancialReportFilters $filters): StatementSection
    {
        $query = Payment::query()
            ->where('company_id', $company->id)
            ->whereBetween('payment_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy('payment_date');

        // Client sees only inbound (payments they made), Supplier only outbound (payments they received)
        if ($filters->isClient()) {
            $query->where('direction', PaymentDirection::INBOUND);
        } elseif ($filters->isSupplier()) {
            $query->where('direction', PaymentDirection::OUTBOUND);
        }

        if ($filters->currency !== null) {
            $query->where('currency_code', $filters->currency);
        }

        $payments = $query->with([
            'paymentMethod',
            'allocations.scheduleItem.payable',
        ])->get();

        $rows = [];

        foreach ($payments as $payment) {
            $amount = (int) $payment->amount;
            $allocated = (int) $payment->allocations->sum('allocated_amount');

            // Payment header row
            $rows[] = [
                '_row_type' => 'header',
                'reference' => (string) ($payment->reference ?? ''),
                'date' => optional($payment->payment_date)->format('Y-m-d'),
                'direction' => $payment->direction instanceof \BackedEnum ? $payment->direction->value : (string) $payment->direction,
                'amount' => round($amount / Money::SCALE, 2),
                'currency' => (string) ($payment->currency_code ?? ''),
                'method' => (string) ($payment->paymentMethod?->name ?? ''),
                'allocated' => round($allocated / Money::SCALE, 2),
                'unallocated' => round(($amount - $allocated) / Money::SCALE, 2),
                'status' => $payment->status instanceof \BackedEnum ? $payment->status->value : (string) $payment->status,
            ];

            // Allocation detail rows
            foreach ($payment->allocations as $allocation) {
                $scheduleItem = $allocation->scheduleItem;
                $payable = $scheduleItem?->payable;
                $allocatedAmount = (int) $allocation->allocated_amount;

                $rows[] = [
                    '_row_type' => 'detail',
                    'reference' => '',
                    'date' => '',
                    'direction' => '',
                    'amount' => round($allocatedAmount / Money::SCALE, 2),
                    'currency' => '',
                    'method' => '  ↳ ' . $this->documentLabel($payable, $scheduleItem?->label),
                    'allocated' => '',
                    'unallocated' => '',
                    'status' => '',
                ];
            }
        }

        $columns = ['reference', 'date'];
        if ($filters->isAdmin()) {
            $columns[] = 'direction';
        }
        $columns = array_merge($columns, ['amount', 'currency', 'method', 'allocated', 'unallocated', 'status']);

        return new StatementSection(
            key: 'payments',
            titleKey: 'financial_report.sections.payments',
            columns: $columns,
            rows: $rows,
        );
    }

    private function documentLabel($payable, ?string $itemLabel): string
    {
        if ($payable === null) {
            return $itemLabel ?? __('financial_report.columns.installment');
        }

        $type = match (true) {
            $payable instanceof ProformaInvoice => 'PI',
            $payable instanceof PurchaseOrder => 'PO',
            $payable instanceof Shipment => 'SHP',
            $payable instanceof ShipmentPlan => 'SP',
            default => class_basename($payable),
        };

        $ref = $payable->reference ?? $payable->getKey();

        $label = "{$type} {$ref}";
        if ($itemLabel) {
            $label .= " ({$itemLabel})";
        }

        return $label;
    }
}
