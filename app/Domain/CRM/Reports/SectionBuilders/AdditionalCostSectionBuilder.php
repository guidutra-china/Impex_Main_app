<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Support\Facades\DB;

final class AdditionalCostSectionBuilder implements SectionBuilder
{
    public function __construct(private readonly CompanyRole $role) {}

    public function key(): string
    {
        return 'additional_costs';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $dateExpr = DB::raw('COALESCE(cost_date, created_at)');

        $query = AdditionalCost::query()
            ->whereNot('status', AdditionalCostStatus::WAIVED)
            ->where('costable_type', '!=', (new ProformaInvoice)->getMorphClass())
            ->where(function ($q) {
                // Exclude freight billable to client — already included in Shipment total
                $q->where('cost_type', '!=', AdditionalCostType::FREIGHT)
                    ->orWhere('billable_to', '!=', BillableTo::CLIENT);
            })
            ->whereBetween($dateExpr, [$filters->from->toDateString(), $filters->to->toDateString()])
            ->with('costable')
            ->orderBy($dateExpr);

        match ($this->role) {
            CompanyRole::CLIENT => $query
                ->where('billable_to', BillableTo::CLIENT)
                ->whereHasMorph('costable', '*', function ($q) use ($company) {
                    $q->where('company_id', $company->id);
                }),
            CompanyRole::SUPPLIER => $query
                ->where('supplier_company_id', $company->id),
            CompanyRole::FORWARDER => $query
                ->where('forwarder_company_id', $company->id),
            default => $query->whereRaw('1 = 0'),
        };

        if ($filters->currency !== null) {
            $query->where('currency_code', $filters->currency);
        }

        $rows = $query->get()->map(function (AdditionalCost $cost) {
            $parent = $cost->costable;
            $document = $this->buildDocumentLabel($cost, $parent);

            return [
                'document' => $document,
                'cost_type' => $cost->cost_type instanceof \BackedEnum ? $cost->cost_type->getEnglishLabel() : (string) $cost->cost_type,
                'description' => (string) ($cost->description ?? ''),
                'amount' => round($cost->amount / Money::SCALE, 2),
                'currency' => (string) ($cost->currency_code ?? ''),
                'date' => ($cost->cost_date ?? $cost->created_at)?->format('Y-m-d'),
                'status' => $cost->status instanceof \BackedEnum ? $cost->status->value : (string) $cost->status,
            ];
        })->all();

        return new StatementSection(
            key: 'additional_costs',
            titleKey: 'statements.sections.additional_costs',
            columns: ['document', 'cost_type', 'description', 'amount', 'currency', 'date', 'status'],
            rows: $rows,
        );
    }

    /**
     * Render the parent document column. Pattern:
     *   Shipment → "SH-XXXX (BL_NUMBER) / PI-XXXX(CLIENT_REF), PI-YYYY(CLIENT_REF)"
     *   PI       → "PI-XXXX(CLIENT_REF)"
     * Falls back to the bare reference when the side data is missing.
     */
    private function buildDocumentLabel(AdditionalCost $cost, ?object $parent): string
    {
        if ($parent instanceof Shipment) {
            $shRef = $parent->reference ?? 'SH-'.$parent->id;
            $bl = trim((string) ($parent->bl_number ?? ''));
            $shPart = $bl !== '' ? "{$shRef} ({$bl})" : $shRef;

            $parent->loadMissing('items.proformaInvoiceItem.proformaInvoice');
            $piParts = $parent->items
                ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice)
                ->filter()
                ->unique('id')
                ->map(function (ProformaInvoice $pi) {
                    $ref = $pi->reference ?? 'PI-'.$pi->id;
                    $clientRef = trim((string) ($pi->client_reference ?? ''));

                    return $clientRef !== '' ? "{$ref}({$clientRef})" : $ref;
                })
                ->values()
                ->all();

            return $piParts === [] ? $shPart : $shPart.' / '.implode(', ', $piParts);
        }

        if ($parent instanceof ProformaInvoice) {
            $ref = $parent->reference ?? 'PI-'.$parent->id;
            $clientRef = trim((string) ($parent->client_reference ?? ''));

            return $clientRef !== '' ? "{$ref}({$clientRef})" : $ref;
        }

        $ref = $parent?->reference ?? class_basename($cost->costable_type).'-'.$cost->costable_id;
        $clientRef = trim((string) ($parent?->client_reference ?? ''));

        return $clientRef !== '' ? "{$ref}({$clientRef})" : $ref;
    }
}
