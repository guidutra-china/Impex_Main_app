<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;

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

        $rows = $query->with(['paymentMethod', 'allocations'])
            ->get()
            ->map(function (Payment $payment) {
                $amount = (int) $payment->amount;
                $allocated = (int) $payment->allocated_total;

                return [
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
            })
            ->all();

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
}
