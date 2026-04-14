<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Facades\DB;

final class AdditionalCostFinancialSectionBuilder implements FinancialSectionBuilder
{
    public function key(): string
    {
        return 'additional_costs';
    }

    public function build(Company $company, FinancialReportFilters $filters): StatementSection
    {
        $dateExpr = DB::raw('COALESCE(cost_date, created_at)');

        $query = AdditionalCost::query()
            ->whereNot('status', AdditionalCostStatus::WAIVED)
            ->whereBetween($dateExpr, [$filters->from->toDateString(), $filters->to->toDateString()])
            ->where(function ($q) {
                // Exclude costs already shown in the Shipments section (freight/other on shipments billable to client)
                $q->where('costable_type', '!=', (new Shipment)->getMorphClass())
                    ->orWhere('billable_to', '!=', BillableTo::CLIENT);
            })
            ->with('costable')
            ->orderBy($dateExpr);

        if ($filters->isClient()) {
            $query->where('billable_to', BillableTo::CLIENT)
                ->whereHasMorph('costable', '*', function ($q) use ($company) {
                    $q->where('company_id', $company->id);
                });
        } elseif ($filters->isSupplier()) {
            $query->where('supplier_company_id', $company->id);
        } else {
            // Admin: all costs related to the company (as client)
            $query->whereHasMorph('costable', '*', function ($q) use ($company) {
                $q->where('company_id', $company->id);
            });
        }

        if ($filters->currency !== null) {
            $query->where('currency_code', $filters->currency);
        }

        $rows = $query->get()->map(function (AdditionalCost $cost) use ($filters) {
            $parent = $cost->costable;
            $parentRef = $parent?->reference ?? class_basename($cost->costable_type) . '-' . $cost->costable_id;

            $row = [
                'document' => $parentRef,
                'cost_type' => $cost->cost_type instanceof \BackedEnum ? $cost->cost_type->getEnglishLabel() : (string) $cost->cost_type,
                'description' => (string) ($cost->description ?? ''),
                'amount' => round($cost->amount / Money::SCALE, 2),
                'currency' => (string) ($cost->currency_code ?? ''),
                'status' => $cost->status instanceof \BackedEnum ? $cost->status->value : (string) $cost->status,
                'date' => ($cost->cost_date ?? $cost->created_at)?->format('Y-m-d'),
            ];

            if ($filters->isAdmin()) {
                $row['billable_to'] = $cost->billable_to instanceof \BackedEnum ? $cost->billable_to->value : (string) ($cost->billable_to ?? '');
            }

            return $row;
        })->all();

        $columns = ['document', 'cost_type', 'description', 'amount', 'currency', 'date', 'status'];
        if ($filters->isAdmin()) {
            $columns[] = 'billable_to';
        }

        return new StatementSection(
            key: 'additional_costs',
            titleKey: 'financial_report.sections.additional_costs',
            columns: $columns,
            rows: $rows,
        );
    }
}
