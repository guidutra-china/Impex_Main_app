<?php

namespace App\Domain\Financial\Reports;

use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Reports\DTOs\PaymentAllocationDetail;
use App\Domain\Financial\Reports\DTOs\PaymentCompanyBucket;
use App\Domain\Financial\Reports\DTOs\PaymentCurrencyTotal;
use App\Domain\Financial\Reports\DTOs\PaymentMonthBucket;
use App\Domain\Financial\Reports\DTOs\PaymentsSummaryFilters;
use App\Domain\Financial\Reports\DTOs\PaymentsSummaryReport;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;
use Carbon\CarbonImmutable;

final class PaymentsSummaryReportService
{
    public function build(PaymentsSummaryFilters $filters): PaymentsSummaryReport
    {
        $payments = Payment::query()
            ->where('direction', $filters->direction)
            ->whereIn('status', array_map(fn ($s) => $s->value, $filters->statuses()))
            ->whereBetween('payment_date', [
                $filters->from->toDateString(),
                $filters->to->toDateString(),
            ])
            ->when(! empty($filters->companyIds), fn ($q) => $q->whereIn('company_id', $filters->companyIds))
            ->when(! empty($filters->currencies), fn ($q) => $q->whereIn('currency_code', $filters->currencies))
            ->with([
                'company:id,name',
                'allocations.scheduleItem.payable',
                'allocations.scheduleItem.paymentTermStage',
            ])
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        return new PaymentsSummaryReport(
            filters: $filters,
            grandTotalsByCurrency: $this->grandTotalsByCurrency($payments),
            totalPaymentCount: $payments->count(),
            byCompany: $this->buildCompanyBuckets($payments),
            byMonth: $this->buildMonthBuckets($payments, $filters),
            allocations: $this->buildAllocationDetails($payments, $filters->direction->value),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Payment>  $payments
     * @return list<PaymentCurrencyTotal>
     */
    private function grandTotalsByCurrency($payments): array
    {
        $rows = [];
        foreach ($payments->groupBy('currency_code') as $currency => $group) {
            $rows[] = new PaymentCurrencyTotal(
                currency: (string) $currency,
                total: (int) $group->sum('amount'),
                count: $group->count(),
            );
        }
        usort($rows, fn ($a, $b) => strcmp($a->currency, $b->currency));

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Payment>  $payments
     * @return list<PaymentCompanyBucket>
     */
    private function buildCompanyBuckets($payments): array
    {
        $buckets = [];

        foreach ($payments->groupBy('company_id') as $companyId => $group) {
            $totalsByCurrency = [];
            foreach ($group->groupBy('currency_code') as $currency => $sub) {
                $totalsByCurrency[] = new PaymentCurrencyTotal(
                    currency: (string) $currency,
                    total: (int) $sub->sum('amount'),
                    count: $sub->count(),
                );
            }
            usort($totalsByCurrency, fn ($a, $b) => strcmp($a->currency, $b->currency));

            $first = $group->first();
            $lastDate = $group->max('payment_date');

            $buckets[] = new PaymentCompanyBucket(
                companyId: (int) $companyId,
                companyName: (string) ($first->company?->name ?? '—'),
                totalsByCurrency: $totalsByCurrency,
                paymentCount: $group->count(),
                lastPaymentDate: $lastDate ? CarbonImmutable::parse((string) $lastDate) : null,
            );
        }

        usort($buckets, fn ($a, $b) => strcmp($a->companyName, $b->companyName));

        return $buckets;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Payment>  $payments
     * @return list<PaymentMonthBucket>
     */
    private function buildMonthBuckets($payments, PaymentsSummaryFilters $filters): array
    {
        $grouped = $payments->groupBy(fn (Payment $p) => CarbonImmutable::parse((string) $p->payment_date)->format('Y-m'));

        $buckets = [];
        $cursor = $filters->from->startOfMonth();
        $end = $filters->to->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->format('Y-m');
            $group = $grouped->get($key);

            $totalsByCurrency = [];
            $count = 0;
            if ($group) {
                $count = $group->count();
                foreach ($group->groupBy('currency_code') as $currency => $sub) {
                    $totalsByCurrency[] = new PaymentCurrencyTotal(
                        currency: (string) $currency,
                        total: (int) $sub->sum('amount'),
                        count: $sub->count(),
                    );
                }
                usort($totalsByCurrency, fn ($a, $b) => strcmp($a->currency, $b->currency));
            }

            $buckets[] = new PaymentMonthBucket(
                yearMonth: $key,
                label: $cursor->locale(app()->getLocale())->isoFormat('MMM YYYY'),
                totalsByCurrency: $totalsByCurrency,
                paymentCount: $count,
            );

            $cursor = $cursor->addMonth();
        }

        return $buckets;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Payment>  $payments
     * @return list<PaymentAllocationDetail>
     */
    private function buildAllocationDetails($payments, string $direction): array
    {
        $details = [];

        foreach ($payments as $payment) {
            $allocations = [];
            foreach ($payment->allocations as $alloc) {
                $scheduleItem = $alloc->scheduleItem;
                $payable = $scheduleItem?->payable;

                [$docType, $docRef, $docUrl, $clientRef] = $this->describePayable($payable);

                $allocations[] = [
                    'label' => (string) ($scheduleItem?->paymentTermStage?->label ?? $scheduleItem?->label ?? ''),
                    'document_type' => $docType,
                    'document_reference' => $docRef,
                    'document_url' => $docUrl,
                    'client_reference' => $clientRef,
                    'amount' => (int) $alloc->allocated_amount,
                    'is_credit' => (bool) ($scheduleItem?->is_credit ?? false),
                ];
            }

            $allocatedTotal = (int) array_sum(array_column($allocations, 'amount'));
            $detailUrl = $direction === 'inbound'
                ? AccountsReceivableResource::getUrl('view', ['record' => $payment->id])
                : AccountsPayableResource::getUrl('view', ['record' => $payment->id]);

            $details[] = new PaymentAllocationDetail(
                paymentId: $payment->id,
                paymentReference: (string) ($payment->number ?? $payment->reference),
                paymentDate: CarbonImmutable::parse((string) $payment->payment_date),
                paymentStatus: $payment->status,
                companyId: (int) $payment->company_id,
                companyName: (string) ($payment->company?->name ?? '—'),
                currency: (string) $payment->currency_code,
                paymentAmount: (int) $payment->amount,
                allocatedAmount: $allocatedTotal,
                unallocatedAmount: max(0, (int) $payment->amount - $allocatedTotal),
                allocations: $allocations,
                detailUrl: $detailUrl,
            );
        }

        return $details;
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: ?string}
     */
    private function describePayable(mixed $payable): array
    {
        if ($payable instanceof ProformaInvoice) {
            return [
                'PI',
                (string) $payable->reference,
                ProformaInvoiceResource::getUrl('view', ['record' => $payable->id]),
                $payable->client_reference !== null && $payable->client_reference !== ''
                    ? (string) $payable->client_reference
                    : null,
            ];
        }
        if ($payable instanceof PurchaseOrder) {
            return ['PO', (string) $payable->reference, PurchaseOrderResource::getUrl('view', ['record' => $payable->id]), null];
        }
        if ($payable instanceof Shipment) {
            return [
                'SH',
                (string) $payable->reference,
                ShipmentResource::getUrl('view', ['record' => $payable->id]),
                $payable->client_reference !== null && $payable->client_reference !== ''
                    ? (string) $payable->client_reference
                    : null,
            ];
        }

        return [null, null, null, null];
    }
}
