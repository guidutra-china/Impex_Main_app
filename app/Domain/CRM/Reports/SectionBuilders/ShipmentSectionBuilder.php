<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Facades\DB;

final class ShipmentSectionBuilder implements SectionBuilder
{
    public function __construct(private readonly CompanyRole $role)
    {
    }

    public function key(): string
    {
        return 'shipments';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $dateExpr = DB::raw('COALESCE(issue_date, etd, created_at)');

        $query = Shipment::query()
            ->whereBetween($dateExpr, [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy($dateExpr);

        match ($this->role) {
            CompanyRole::CLIENT => $query->where('company_id', $company->id),
            CompanyRole::FORWARDER => $query->where('forwarder_company_id', $company->id),
            CompanyRole::SUPPLIER => $query->whereHas(
                'items.proformaInvoiceItem',
                fn ($q) => $q->where('supplier_company_id', $company->id),
            ),
            default => $query->whereRaw('1 = 0'),
        };

        $scope = StatusScopeFilter::whereClause($filters->statusScope);
        if ($scope !== null) {
            [$op, $values] = $scope;
            $op === 'in'
                ? $query->whereIn('status', $values)
                : $query->whereNotIn('status', $values);
        }

        $rows = $query->with(['items.proformaInvoiceItem', 'additionalCosts'])->get()->map(function (Shipment $s) {
            $ciTotal = $s->items->sum(fn ($item) => $item->line_total);

            $freight = $s->additionalCosts
                ->where('billable_to', BillableTo::CLIENT)
                ->where('cost_type', AdditionalCostType::FREIGHT)
                ->sum('amount_in_document_currency');

            $ref = $s->reference ?? (string) $s->id;

            return [
                'number' => $ref,
                'ci_number' => 'CI-' . $ref,
                'client_reference' => (string) ($s->client_reference ?? ''),
                'etd' => optional($s->etd)->format('Y-m-d'),
                'eta' => optional($s->eta)->format('Y-m-d'),
                'status' => $s->status instanceof \BackedEnum ? $s->status->value : (string) $s->status,
                'transport_mode' => $s->transport_mode instanceof \BackedEnum ? $s->transport_mode->value : (string) ($s->transport_mode ?? ''),
                'bl_number' => (string) ($s->bl_number ?? ''),
                'goods' => round($ciTotal / Money::SCALE, 2),
                'freight' => round($freight / Money::SCALE, 2),
                'total' => round(($ciTotal + $freight) / Money::SCALE, 2),
                'currency' => (string) ($s->currency_code ?? ''),
            ];
        })->all();

        return new StatementSection(
            key: 'shipments',
            titleKey: 'statements.sections.shipments',
            columns: ['number', 'ci_number', 'client_reference', 'etd', 'eta', 'status', 'transport_mode', 'bl_number', 'goods', 'freight', 'total', 'currency'],
            rows: $rows,
        );
    }
}
