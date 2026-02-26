<?php

namespace App\Filament\Resources\Shipments\Widgets;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class LandedCostCalculator extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.landed-cost-calculator';

    protected int | string | array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->record;

        $shipment->loadMissing([
            'items.proformaInvoiceItem.product',
            'items.proformaInvoiceItem.proformaInvoice',
            'items.purchaseOrderItem.purchaseOrder',
            'additionalCosts.supplierCompany',
        ]);

        $shipmentCurrency = $shipment->currency_code ?? 'USD';
        $baseCurrency = Currency::base();
        $baseCurrencyCode = $baseCurrency?->code ?? 'USD';
        $baseCurrencyId = $baseCurrency?->id;

        $items = $this->buildItemBreakdown($shipment, $shipmentCurrency);
        $costs = $this->buildCostBreakdown($shipment, $shipmentCurrency, $baseCurrencyId);
        $summary = $this->buildSummary($items, $costs, $shipment);
        $margin = $this->buildMarginAnalysis($items, $summary);

        return [
            'currency' => $shipmentCurrency,
            'baseCurrencyCode' => $baseCurrencyCode,
            'items' => $items,
            'costs' => $costs,
            'summary' => $summary,
            'margin' => $margin,
        ];
    }

    private function buildItemBreakdown(Shipment $shipment, string $currency): array
    {
        $rows = [];
        $totalFobCost = 0;
        $totalSellingValue = 0;
        $totalQuantity = 0;
        $totalWeight = 0;

        foreach ($shipment->items as $item) {
            $piItem = $item->proformaInvoiceItem;
            $poItem = $item->purchaseOrderItem;

            $unitCost = $poItem?->unit_cost ?? $piItem?->unit_cost ?? 0;
            $unitPrice = $piItem?->unit_price ?? 0;
            $qty = $item->quantity;
            $weight = (float) ($item->total_weight ?? 0);

            $lineCost = $unitCost * $qty;
            $linePrice = $unitPrice * $qty;

            $totalFobCost += $lineCost;
            $totalSellingValue += $linePrice;
            $totalQuantity += $qty;
            $totalWeight += $weight;

            $rows[] = [
                'product' => $item->product_name,
                'pi_ref' => $piItem?->proformaInvoice?->reference ?? '—',
                'po_ref' => $poItem?->purchaseOrder?->reference ?? '—',
                'quantity' => $qty,
                'unit' => $item->unit ?? 'pcs',
                'unit_cost' => Money::format($unitCost),
                'unit_cost_raw' => $unitCost,
                'unit_price' => Money::format($unitPrice),
                'unit_price_raw' => $unitPrice,
                'fob_total' => Money::format($lineCost),
                'fob_total_raw' => $lineCost,
                'sell_total' => Money::format($linePrice),
                'sell_total_raw' => $linePrice,
                'weight' => number_format($weight, 3),
            ];
        }

        return [
            'rows' => $rows,
            'total_fob_cost' => $totalFobCost,
            'total_fob_cost_formatted' => Money::format($totalFobCost),
            'total_selling_value' => $totalSellingValue,
            'total_selling_value_formatted' => Money::format($totalSellingValue),
            'total_quantity' => $totalQuantity,
            'total_weight' => $totalWeight,
            'total_weight_formatted' => number_format($totalWeight, 3),
        ];
    }

    private function buildCostBreakdown(Shipment $shipment, string $shipmentCurrency, ?int $baseCurrencyId): array
    {
        $costGroups = [
            'freight' => ['types' => [AdditionalCostType::FREIGHT], 'label' => __('widgets.landed_cost.freight'), 'icon' => 'heroicon-o-globe-alt', 'total' => 0],
            'insurance' => ['types' => [AdditionalCostType::INSURANCE], 'label' => __('widgets.landed_cost.insurance'), 'icon' => 'heroicon-o-shield-exclamation', 'total' => 0],
            'customs' => ['types' => [AdditionalCostType::CUSTOMS], 'label' => __('widgets.landed_cost.customs_duties'), 'icon' => 'heroicon-o-shield-check', 'total' => 0],
            'inspection' => ['types' => [AdditionalCostType::INSPECTION, AdditionalCostType::TESTING, AdditionalCostType::CERTIFICATION], 'label' => __('widgets.landed_cost.inspection_testing'), 'icon' => 'heroicon-o-magnifying-glass', 'total' => 0],
            'packaging' => ['types' => [AdditionalCostType::PACKAGING], 'label' => __('widgets.landed_cost.packaging'), 'icon' => 'heroicon-o-archive-box', 'total' => 0],
            'other' => ['types' => [AdditionalCostType::SAMPLES, AdditionalCostType::SAMPLE_SHIPPING, AdditionalCostType::TRAVEL, AdditionalCostType::COMMISSION, AdditionalCostType::OTHER], 'label' => __('widgets.landed_cost.other_costs'), 'icon' => 'heroicon-o-ellipsis-horizontal-circle', 'total' => 0],
        ];

        $costDetails = [];
        $totalAdditionalCosts = 0;

        foreach ($shipment->additionalCosts as $cost) {
            $amountInShipmentCurrency = $cost->amount_in_document_currency ?? $cost->amount;

            $costDetails[] = [
                'type' => $cost->cost_type->getLabel(),
                'type_value' => $cost->cost_type->value,
                'description' => $cost->description,
                'supplier' => $cost->supplierCompany?->name,
                'original_amount' => Money::format($cost->amount),
                'original_currency' => $cost->currency_code,
                'converted_amount' => Money::format($amountInShipmentCurrency),
                'billable_to' => $cost->billable_to->getLabel(),
                'billable_to_color' => $cost->billable_to->getColor(),
                'status' => $cost->status->getLabel(),
                'status_color' => $cost->status->getColor(),
            ];

            $totalAdditionalCosts += $amountInShipmentCurrency;

            foreach ($costGroups as $key => &$group) {
                if (in_array($cost->cost_type, $group['types'])) {
                    $group['total'] += $amountInShipmentCurrency;
                    break;
                }
            }
        }

        $byBillable = [
            'client' => $shipment->additionalCosts->where('billable_to', BillableTo::CLIENT)->sum('amount_in_document_currency'),
            'supplier' => $shipment->additionalCosts->where('billable_to', BillableTo::SUPPLIER)->sum('amount_in_document_currency'),
            'company' => $shipment->additionalCosts->where('billable_to', BillableTo::COMPANY)->sum('amount_in_document_currency'),
        ];

        $groupSummary = [];
        foreach ($costGroups as $key => $group) {
            if ($group['total'] > 0) {
                $groupSummary[] = [
                    'key' => $key,
                    'label' => $group['label'],
                    'icon' => $group['icon'],
                    'total' => $group['total'],
                    'total_formatted' => Money::format($group['total']),
                    'percentage' => $totalAdditionalCosts > 0 ? round(($group['total'] / $totalAdditionalCosts) * 100, 1) : 0,
                ];
            }
        }

        return [
            'details' => $costDetails,
            'groups' => $groupSummary,
            'total' => $totalAdditionalCosts,
            'total_formatted' => Money::format($totalAdditionalCosts),
            'by_billable' => [
                'client' => Money::format($byBillable['client']),
                'client_raw' => $byBillable['client'],
                'supplier' => Money::format($byBillable['supplier']),
                'supplier_raw' => $byBillable['supplier'],
                'company' => Money::format($byBillable['company']),
                'company_raw' => $byBillable['company'],
            ],
        ];
    }

    private function buildSummary(array $items, array $costs, Shipment $shipment): array
    {
        $fobCost = $items['total_fob_cost'];
        $additionalCosts = $costs['total'];
        $totalLandedCost = $fobCost + $additionalCosts;
        $totalQuantity = $items['total_quantity'];
        $totalWeight = $items['total_weight'];

        $landedCostPerUnit = $totalQuantity > 0 ? (int) round($totalLandedCost / $totalQuantity) : 0;
        $landedCostPerKg = $totalWeight > 0 ? (int) round($totalLandedCost / $totalWeight) : 0;

        $fobPercentage = $totalLandedCost > 0 ? round(($fobCost / $totalLandedCost) * 100, 1) : 0;
        $additionalPercentage = $totalLandedCost > 0 ? round(($additionalCosts / $totalLandedCost) * 100, 1) : 0;

        return [
            'fob_cost' => $fobCost,
            'fob_cost_formatted' => Money::format($fobCost),
            'fob_percentage' => $fobPercentage,
            'additional_costs' => $additionalCosts,
            'additional_costs_formatted' => Money::format($additionalCosts),
            'additional_percentage' => $additionalPercentage,
            'total_landed_cost' => $totalLandedCost,
            'total_landed_cost_formatted' => Money::format($totalLandedCost),
            'per_unit' => Money::format($landedCostPerUnit),
            'per_unit_raw' => $landedCostPerUnit,
            'per_kg' => Money::format($landedCostPerKg),
            'per_kg_raw' => $landedCostPerKg,
            'total_quantity' => $totalQuantity,
            'total_weight' => $totalWeight,
        ];
    }

    private function buildMarginAnalysis(array $items, array $summary): array
    {
        $sellingValue = $items['total_selling_value'];
        $landedCost = $summary['total_landed_cost'];
        $grossProfit = $sellingValue - $landedCost;

        $marginOnCost = $landedCost > 0 ? round(($grossProfit / $landedCost) * 100, 2) : 0;
        $marginOnSale = $sellingValue > 0 ? round(($grossProfit / $sellingValue) * 100, 2) : 0;

        $fobOnlyCost = $items['total_fob_cost'];
        $fobMargin = $fobOnlyCost > 0 ? round((($sellingValue - $fobOnlyCost) / $fobOnlyCost) * 100, 2) : 0;

        return [
            'selling_value' => $sellingValue,
            'selling_value_formatted' => Money::format($sellingValue),
            'landed_cost' => $landedCost,
            'landed_cost_formatted' => Money::format($landedCost),
            'gross_profit' => $grossProfit,
            'gross_profit_formatted' => Money::format(abs($grossProfit)),
            'gross_profit_raw' => $grossProfit,
            'margin_on_cost' => $marginOnCost,
            'margin_on_sale' => $marginOnSale,
            'fob_margin' => $fobMargin,
            'margin_erosion' => $fobMargin - $marginOnCost,
        ];
    }
}
