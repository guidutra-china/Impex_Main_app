<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
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
            'paymentScheduleItems.shipment',
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
                'balance' => round(\App\Domain\CRM\Reports\DocumentBalance::open($pi, $grandTotal, $paid) / Money::SCALE, 2),
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
                // Cancelado/waived: nada mais é devido nesta parcela.
                $itemRemaining = (\App\Domain\CRM\Reports\DocumentBalance::isCancelled($pi)
                    || $item->status === \App\Domain\Financial\Enums\PaymentScheduleStatus::WAIVED)
                    ? 0
                    : max(0, $itemAmount - $itemPaid);

                $label = $item->label ?? __('financial_report.columns.installment');
                $enrichedLabel = $this->enrichInstallmentLabel((string) $label, $pi, $item->shipment);

                $rows[] = [
                    '_row_type' => 'detail',
                    'number' => '',
                    'client_reference' => '',
                    'date' => '',
                    'due_date' => optional($item->due_date)->format('Y-m-d'),
                    'paid_date' => $this->latestPaymentDate($item),
                    'status' => $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status,
                    'payment_term' => '  ↳ '.$enrichedLabel,
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

    /**
     * Rewrites the trailing "[SH-XXX / PI-YYY]" or "[PI-XXX]" suffix of an
     * installment label with BL number and client_reference inline:
     *   [SH-2026-00007 / PI-2026-00038] →
     *   [SH-2026-00007 (BL3087239734) / PI-2026-00038(1022)]
     * Leaves the label untouched when no bracketed suffix is present
     * (e.g. PI-wide installments without shipment context).
     */
    private function enrichInstallmentLabel(string $label, ProformaInvoice $pi, ?Shipment $shipment): string
    {
        $piRef = $pi->reference ?? 'PI-'.$pi->id;
        $piClientRef = trim((string) ($pi->client_reference ?? ''));
        $piEnriched = $piClientRef !== '' ? "{$piRef}({$piClientRef})" : $piRef;

        // Pattern 1: "[SH-XXX / PI-YYY]"
        $replaced = preg_replace_callback(
            '#\[\s*(SH-[\w-]+)\s*/\s*(PI-[\w-]+)\s*\]#',
            function (array $m) use ($shipment, $piEnriched): string {
                $shRef = $m[1];
                $bl = $shipment ? trim((string) ($shipment->bl_number ?? '')) : '';
                $shEnriched = $bl !== '' ? "{$shRef} ({$bl})" : $shRef;

                return "[{$shEnriched} / {$piEnriched}]";
            },
            $label,
            -1,
            $count1,
        );

        if ($count1 > 0) {
            return $replaced;
        }

        // Pattern 2: bare "[PI-XXX]" suffix
        $replaced = preg_replace_callback(
            '#\[\s*PI-[\w-]+\s*\]#',
            fn () => "[{$piEnriched}]",
            $label,
            -1,
            $count2,
        );

        return $count2 > 0 ? $replaced : $label;
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
