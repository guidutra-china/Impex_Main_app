<?php

namespace App\Filament\Resources\Catalog\Products\Widgets;

use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

class ProductSummary extends Widget
{
    protected string $view = 'filament.widgets.product-summary';

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public ?Model $record = null;

    protected function getViewData(): array
    {
        if (! $this->record instanceof Product) {
            return [];
        }

        $product = $this->record;
        $product->loadMissing([
            'category',
            'parent',
            'specification',
            'packaging',
            'costing.currency',
            'variants',
            'suppliers',
            'clients',
            'tags',
            'attributeValues.categoryAttribute',
        ]);

        return [
            'product' => $product,
            'stats' => $this->buildStats($product),
            'pricing' => $this->buildPricing($product),
            'quickSpecs' => $this->buildQuickSpecs($product),
            'attributes' => $this->buildAttributes($product),
        ];
    }

    protected function buildStats(Product $product): array
    {
        return [
            [
                'label' => __('widgets.product_summary.suppliers'),
                'value' => $product->suppliers->count(),
                'icon' => 'heroicon-o-truck',
                'color' => 'primary',
                'url' => null,
            ],
            [
                'label' => __('widgets.product_summary.clients'),
                'value' => $product->clients->count(),
                'icon' => 'heroicon-o-building-office',
                'color' => 'success',
                'url' => null,
            ],
            [
                'label' => __('widgets.product_summary.variants'),
                'value' => $product->variants->count(),
                'icon' => 'heroicon-o-square-3-stack-3d',
                'color' => 'info',
                'url' => null,
            ],
        ];
    }

    protected function buildPricing(Product $product): array
    {
        $pricing = [];
        $costing = $product->costing;
        $currencyCode = $costing?->currency?->code ?? 'USD';

        if ($costing?->base_price) {
            $pricing['base_price'] = [
                'label' => __('widgets.product_summary.base_price'),
                'value' => Money::format($costing->base_price),
                'currency' => $currencyCode,
            ];
        }

        if ($costing?->total_manufacturing_cost) {
            $pricing['manufacturing_cost'] = [
                'label' => __('widgets.product_summary.manufacturing_cost'),
                'value' => Money::format($costing->total_manufacturing_cost),
                'currency' => $currencyCode,
            ];
        }

        if ($costing?->calculated_selling_price) {
            $pricing['selling_price'] = [
                'label' => __('widgets.product_summary.selling_price'),
                'value' => Money::format($costing->calculated_selling_price),
                'currency' => $currencyCode,
            ];
        }

        if ($costing?->markup_percentage) {
            $pricing['markup'] = [
                'label' => __('widgets.product_summary.markup'),
                'value' => number_format($costing->markup_percentage, 1) . '%',
                'currency' => null,
            ];
        }

        $preferredSupplier = $product->suppliers->firstWhere('pivot.is_preferred', true);
        if ($preferredSupplier && $preferredSupplier->pivot->unit_price) {
            $pricing['supplier_price'] = [
                'label' => __('widgets.product_summary.preferred_supplier'),
                'value' => Money::format($preferredSupplier->pivot->unit_price),
                'currency' => $preferredSupplier->pivot->currency_code ?? $currencyCode,
                'note' => $preferredSupplier->name,
            ];
        }

        $preferredClient = $product->clients->firstWhere('pivot.is_preferred', true);
        if ($preferredClient && $preferredClient->pivot->unit_price) {
            $pricing['client_price'] = [
                'label' => __('widgets.product_summary.preferred_client'),
                'value' => Money::format($preferredClient->pivot->unit_price),
                'currency' => $preferredClient->pivot->currency_code ?? $currencyCode,
                'note' => $preferredClient->name,
            ];
        }

        return $pricing;
    }

    protected function buildQuickSpecs(Product $product): array
    {
        $specs = [];

        if ($product->specification) {
            $s = $product->specification;
            if ($s->net_weight) {
                $specs[] = ['label' => __('widgets.product_summary.weight'), 'value' => $s->net_weight . ' kg'];
            }
            if ($s->length && $s->width && $s->height) {
                $specs[] = ['label' => __('widgets.product_summary.dimensions'), 'value' => "{$s->length} × {$s->width} × {$s->height} cm"];
            }
            if ($s->material) {
                $specs[] = ['label' => __('widgets.product_summary.material'), 'value' => $s->material];
            }
            if ($s->color) {
                $specs[] = ['label' => __('widgets.product_summary.color'), 'value' => $s->color];
            }
        }

        if ($product->packaging) {
            $p = $product->packaging;
            if ($p->pcs_per_carton) {
                $specs[] = ['label' => __('widgets.product_summary.pcs_per_carton'), 'value' => $p->pcs_per_carton];
            }
            if ($p->carton_cbm) {
                $specs[] = ['label' => 'CBM/Carton', 'value' => $p->carton_cbm . ' m³'];
            }
            if ($p->carton_weight) {
                $specs[] = ['label' => 'GW/Carton', 'value' => $p->carton_weight . ' kg'];
            }
        }

        if ($product->moq) {
            $specs[] = ['label' => 'MOQ', 'value' => number_format($product->moq) . ' ' . ($product->moq_unit ?? 'pcs')];
        }

        if ($product->lead_time_days) {
            $specs[] = ['label' => __('widgets.product_summary.lead_time'), 'value' => $product->lead_time_days . ' days'];
        }

        return $specs;
    }

    protected function buildAttributes(Product $product): array
    {
        return $product->attributeValues
            ->filter(fn ($av) => $av->value !== null && $av->value !== '')
            ->map(fn ($av) => [
                'label' => $av->categoryAttribute?->name ?? 'Unknown',
                'value' => $av->value,
                'unit' => $av->categoryAttribute?->unit,
            ])
            ->values()
            ->toArray();
    }
}
