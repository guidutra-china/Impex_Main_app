<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;

final class ProformaInvoiceSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'proforma_invoices';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
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
        ])->get();

        $pis->load([
            'purchaseOrders' => function ($q) {
                $q->whereIn('status', [
                    PurchaseOrderStatus::IN_PRODUCTION,
                    PurchaseOrderStatus::AWAITING_SHIPMENT,
                    PurchaseOrderStatus::SHIPPED,
                    PurchaseOrderStatus::COMPLETED,
                ]);
            },
        ]);

        $pis->load([
            'productionSchedules' => function ($q) {
                $q->whereIn('status', [ProductionScheduleStatus::Approved, ProductionScheduleStatus::Completed])
                    ->with('entries');
            },
        ]);

        $hasProduction = false;

        $rows = $pis->map(function (ProformaInvoice $pi) use (&$hasProduction) {
                $total = (int) $pi->grand_total;
                $paid = (int) $pi->schedule_paid_total;

                // Try production schedules first (detailed tracking)
                $planned = 0;
                $actual = 0;
                foreach ($pi->productionSchedules as $schedule) {
                    foreach ($schedule->entries as $entry) {
                        $planned += (int) $entry->quantity;
                        $actual += (int) $entry->actual_quantity;
                    }
                }

                $production = null;
                if ($planned > 0) {
                    $hasProduction = true;
                    $pct = min(100, round(($actual / $planned) * 100));
                    $production = $pct . '%';
                } elseif ($pi->purchaseOrders->isNotEmpty()) {
                    // Fallback: use the most advanced PO status
                    $statusPriority = [
                        PurchaseOrderStatus::COMPLETED->value => 4,
                        PurchaseOrderStatus::SHIPPED->value => 3,
                        PurchaseOrderStatus::AWAITING_SHIPMENT->value => 2,
                        PurchaseOrderStatus::IN_PRODUCTION->value => 1,
                    ];

                    $bestStatus = $pi->purchaseOrders
                        ->sortByDesc(fn ($po) => $statusPriority[$po->status->value] ?? 0)
                        ->first()
                        ->status;

                    $hasProduction = true;
                    $production = $bestStatus->getLabel();
                }

                return [
                    'number' => $pi->reference ?? (string) $pi->id,
                    'client_reference' => (string) ($pi->client_reference ?? ''),
                    'date' => optional($pi->issue_date)->format('Y-m-d'),
                    'status' => $pi->status instanceof \BackedEnum ? $pi->status->value : (string) $pi->status,
                    'incoterm' => $pi->incoterm instanceof \BackedEnum ? $pi->incoterm->value : (string) ($pi->incoterm ?? ''),
                    'payment_term' => (string) ($pi->paymentTerm?->name ?? ''),
                    'total' => round($total / Money::SCALE, 2),
                    'paid' => round($paid / Money::SCALE, 2),
                    'balance' => round(($total - $paid) / Money::SCALE, 2),
                    'currency' => (string) ($pi->currency_code ?? ''),
                    'production' => $production,
                ];
            })
            ->all();

        $columns = ['number', 'client_reference', 'date', 'status', 'incoterm', 'payment_term', 'total', 'paid', 'balance', 'currency'];
        if ($hasProduction) {
            $columns[] = 'production';
        }

        return new StatementSection(
            key: 'proforma_invoices',
            titleKey: 'statements.sections.proforma_invoices',
            columns: $columns,
            rows: $rows,
        );
    }
}
