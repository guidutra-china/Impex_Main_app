<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

final class PiFinancialSectionBuilder implements FinancialSectionBuilder
{
    public function key(): string
    {
        return 'proforma_invoices';
    }

    public function build(Company $company, FinancialReportFilters $filters): StatementSection
    {
        $query = ProformaInvoice::query()
            ->where('company_id', $company->id)
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy('issue_date');

        $scope = StatusScopeFilter::whereClause($filters->statusScope);
        if ($scope !== null) {
            [$op, $values] = $scope;
            $op === 'in'
                ? $query->whereIn('status', $values)
                : $query->whereNotIn('status', $values);
        }

        if ($filters->currency !== null) {
            $query->where('currency_code', $filters->currency);
        }

        $pis = $query->with([
            'items',
            'paymentScheduleItems.allocations.payment',
            'paymentTerm',
            'additionalCosts',
        ])->get();

        $rows = [];

        foreach ($pis as $pi) {
            if ($filters->isExcluded('proforma_invoices', (int) $pi->id)) {
                continue;
            }

            $total = (int) $pi->total;
            $additionalCosts = (int) $pi->client_billable_costs_total;
            $grandTotal = (int) $pi->grand_total;
            $paid = (int) $pi->schedule_paid_total;

            // PI header row
            $rows[] = [
                '_row_type' => 'header',
                '_entity_id' => (int) $pi->id,
                'number' => $pi->reference ?? (string) $pi->id,
                'client_reference' => (string) ($pi->client_reference ?? ''),
                'date' => optional($pi->issue_date)->format('Y-m-d'),
                'due_date' => '',
                'paid_date' => '',
                'status' => $pi->status instanceof \BackedEnum ? $pi->status->value : (string) $pi->status,
                'payment_term' => (string) ($pi->paymentTerm?->name ?? ''),
                'total' => round($total / Money::SCALE, 2),
                'additional_costs' => round($additionalCosts / Money::SCALE, 2),
                'grand_total' => round($grandTotal / Money::SCALE, 2),
                'paid' => round($paid / Money::SCALE, 2),
                'balance' => round(($grandTotal - $paid) / Money::SCALE, 2),
                'currency' => (string) ($pi->currency_code ?? ''),
            ];

            if (! $filters->showDetails) {
                continue;
            }

            // Payment schedule detail rows
            $scheduleItems = $pi->paymentScheduleItems->sortBy('sort_order');

            foreach ($scheduleItems as $item) {
                if ($item->is_credit) {
                    continue;
                }

                $itemAmount = (int) $item->amount;
                $itemPaid = $this->computePaidAmount($item);
                $itemRemaining = max(0, $itemAmount - $itemPaid);

                $rows[] = [
                    '_row_type' => 'detail',
                    'number' => '',
                    'client_reference' => '',
                    'date' => '',
                    'due_date' => optional($item->due_date)->format('Y-m-d'),
                    'paid_date' => $this->latestPaymentDate($item),
                    'status' => $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status,
                    'payment_term' => '  ↳ '.($item->label ?? __('financial_report.columns.installment')),
                    'total' => '',
                    'additional_costs' => '',
                    'grand_total' => round($itemAmount / Money::SCALE, 2),
                    'paid' => round($itemPaid / Money::SCALE, 2),
                    'balance' => round($itemRemaining / Money::SCALE, 2),
                    'currency' => '',
                ];
            }
        }

        return new StatementSection(
            key: 'proforma_invoices',
            titleKey: 'financial_report.sections.proforma_invoices',
            columns: ['number', 'client_reference', 'date', 'due_date', 'paid_date', 'status', 'payment_term', 'total', 'additional_costs', 'grand_total', 'paid', 'balance', 'currency'],
            rows: $rows,
        );
    }

    private function computePaidAmount(PaymentScheduleItem $item): int
    {
        $total = 0;
        foreach ($item->allocations as $allocation) {
            if ($allocation->payment && $allocation->payment->status === PaymentStatus::APPROVED) {
                $total += (int) ($allocation->allocated_amount_in_document_currency ?? 0);
            }
        }

        return $total;
    }

    private function latestPaymentDate(PaymentScheduleItem $item): ?string
    {
        $latest = null;

        foreach ($item->allocations as $allocation) {
            $payment = $allocation->payment;
            if ($payment && $payment->status === PaymentStatus::APPROVED && $payment->payment_date) {
                if ($latest === null || $payment->payment_date->gt($latest)) {
                    $latest = $payment->payment_date;
                }
            }
        }

        return $latest?->format('Y-m-d');
    }
}
