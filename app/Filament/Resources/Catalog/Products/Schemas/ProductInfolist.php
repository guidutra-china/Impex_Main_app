<?php

namespace App\Filament\Resources\Catalog\Products\Schemas;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class ProductInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Product')
                    ->tabs([
                        Tabs\Tab::make(__('forms.tabs.general'))
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make(__('forms.tabs.specifications'))
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema(static::specificationsTab()),
                        Tabs\Tab::make(__('forms.tabs.packaging'))
                            ->icon('heroicon-o-archive-box')
                            ->schema(static::packagingTab()),
                        Tabs\Tab::make(__('forms.tabs.costing'))
                            ->icon('heroicon-o-calculator')
                            ->schema(static::costingTab()),
                    ])
                    ->columnSpanFull()
                    ->persistTabInQueryString(),
            ]);
    }

    protected static function generalTab(): array
    {
        return [
            Section::make(__('forms.sections.product_identity'))
                ->schema([
                    TextEntry::make('category.full_path')
                        ->label(__('forms.labels.category'))
                        ->icon('heroicon-o-folder')
                        ->placeholder('—'),
                    TextEntry::make('name')
                        ->label(__('forms.labels.product_name'))
                        ->weight(FontWeight::Bold)
                        ->size(TextSize::Large),
                    TextEntry::make('sku')
                        ->label(__('forms.labels.sku'))
                        ->badge()
                        ->color('gray')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('forms.labels.status'))
                        ->badge(),
                    TextEntry::make('parent.name')
                        ->label(__('forms.labels.variant_of'))
                        ->placeholder(__('forms.placeholders.base_product_2'))
                        ->icon('heroicon-o-link'),
                ])
                ->columns(2),

            Section::make(__('forms.sections.international_trade'))
                ->schema([
                    TextEntry::make('hs_code')
                        ->label(__('forms.labels.hs_code'))
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('origin_country')
                        ->label(__('forms.labels.country_of_origin'))
                        ->placeholder('—'),
                    TextEntry::make('brand')
                        ->label(__('forms.labels.brand'))
                        ->placeholder('—'),
                    TextEntry::make('model_number')
                        ->label(__('forms.labels.model_number'))
                        ->placeholder('—'),
                    TextEntry::make('certifications')
                        ->label(__('forms.labels.certifications'))
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make(__('forms.sections.order_defaults'))
                ->schema([
                    TextEntry::make('moq')
                        ->label(__('forms.labels.moq'))
                        ->placeholder('—'),
                    TextEntry::make('moq_unit')
                        ->label(__('forms.labels.moq_unit'))
                        ->placeholder('—'),
                    TextEntry::make('lead_time_days')
                        ->label(__('forms.labels.lead_time'))
                        ->suffix(' days')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make(__('forms.sections.tags_notes'))
                ->schema([
                    TextEntry::make('tags.name')
                        ->label(__('forms.labels.tags'))
                        ->badge()
                        ->color('info')
                        ->placeholder(__('forms.placeholders.no_tags')),
                    TextEntry::make('description')
                        ->label(__('forms.labels.description'))
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->markdown(),
                    TextEntry::make('internal_notes')
                        ->label(__('forms.labels.internal_notes'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible(),
        ];
    }

    protected static function specificationsTab(): array
    {
        return [
            Section::make(__('forms.sections.product_dimensions_weight_unpackaged'))
                ->relationship('specification')
                ->schema([
                    TextEntry::make('net_weight')
                        ->label(__('forms.labels.net_weight_1_pc'))
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('length')
                        ->label(__('forms.labels.length'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('width')
                        ->label(__('forms.labels.width'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('height')
                        ->label(__('forms.labels.height'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                ])
                ->columns(3),

            Section::make(__('forms.sections.material_finish'))
                ->relationship('specification')
                ->schema([
                    TextEntry::make('material')
                        ->label(__('forms.labels.material'))
                        ->placeholder('—'),
                    TextEntry::make('color')
                        ->label(__('forms.labels.color'))
                        ->placeholder('—'),
                    TextEntry::make('finish')
                        ->label(__('forms.labels.finish'))
                        ->placeholder('—'),
                    TextEntry::make('notes')
                        ->label(__('forms.labels.specification_notes'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ];
    }

    protected static function packagingTab(): array
    {
        return [
            Section::make(__('forms.sections.packaging_type'))
                ->relationship('packaging')
                ->schema([
                    TextEntry::make('packaging_type')
                        ->label(__('forms.labels.packaging_type'))
                        ->badge()
                        ->placeholder('—'),
                ])
                ->columns(3),

            Section::make(__('forms.sections.inner_box'))
                ->relationship('packaging')
                ->schema([
                    TextEntry::make('pcs_per_inner_box')
                        ->label(__('forms.labels.pcs_inner_box'))
                        ->placeholder('—'),
                    TextEntry::make('inner_box_length')
                        ->label(__('forms.labels.length'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('inner_box_width')
                        ->label(__('forms.labels.width'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('inner_box_height')
                        ->label(__('forms.labels.height'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('inner_box_weight')
                        ->label(__('forms.labels.gw_inner_box'))
                        ->suffix(' kg')
                        ->placeholder('—'),
                ])
                ->columns(5),

            Section::make(__('forms.sections.master_carton'))
                ->relationship('packaging')
                ->schema([
                    TextEntry::make('pcs_per_carton')
                        ->label(__('forms.labels.pcs_carton'))
                        ->placeholder('—'),
                    TextEntry::make('inner_boxes_per_carton')
                        ->label(__('forms.labels.inner_boxes_carton'))
                        ->placeholder('—'),
                    TextEntry::make('carton_length')
                        ->label(__('forms.labels.length'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('carton_width')
                        ->label(__('forms.labels.width'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('carton_height')
                        ->label(__('forms.labels.height'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('carton_net_weight')
                        ->label(__('forms.labels.nw_carton'))
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('carton_weight')
                        ->label(__('forms.labels.gw_carton'))
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('carton_cbm')
                        ->label(__('forms.labels.cbm_carton'))
                        ->suffix(' m³')
                        ->placeholder('—'),
                ])
                ->columns(4),

            Section::make(__('forms.sections.container_loading'))
                ->relationship('packaging')
                ->schema([
                    TextEntry::make('cartons_per_20ft')
                        ->label("Cartons / 20' GP")
                        ->placeholder('—'),
                    TextEntry::make('cartons_per_40ft')
                        ->label("Cartons / 40' GP")
                        ->placeholder('—'),
                    TextEntry::make('cartons_per_40hq')
                        ->label("Cartons / 40' HC")
                        ->placeholder('—'),
                    TextEntry::make('packing_notes')
                        ->label(__('forms.labels.packing_notes'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ];
    }

    protected static function costingTab(): array
    {
        return [
            Section::make(__('forms.sections.cost_breakdown'))
                ->relationship('costing')
                ->schema([
                    TextEntry::make('currency.code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('base_price')
                        ->label(__('forms.labels.base_price'))
                        ->formatStateUsing(fn ($state) => $state ? Money::format($state) : null)
                        ->prefix('$ ')
                        ->placeholder('—'),
                    TextEntry::make('bom_material_cost')
                        ->label(__('forms.labels.bom_material_cost'))
                        ->formatStateUsing(fn ($state) => $state ? Money::format($state) : null)
                        ->prefix('$ ')
                        ->placeholder('—'),
                    TextEntry::make('direct_labor_cost')
                        ->label(__('forms.labels.direct_labor_cost'))
                        ->formatStateUsing(fn ($state) => $state ? Money::format($state) : null)
                        ->prefix('$ ')
                        ->placeholder('—'),
                    TextEntry::make('direct_overhead_cost')
                        ->label(__('forms.labels.direct_overhead_cost'))
                        ->formatStateUsing(fn ($state) => $state ? Money::format($state) : null)
                        ->prefix('$ ')
                        ->placeholder('—'),
                    TextEntry::make('total_manufacturing_cost')
                        ->label(__('forms.labels.total_manufacturing_cost'))
                        ->formatStateUsing(fn ($state) => $state ? Money::format($state) : null)
                        ->prefix('$ ')
                        ->placeholder('—')
                        ->weight(FontWeight::Bold),
                    TextEntry::make('markup_percentage')
                        ->label(__('forms.labels.markup'))
                        ->suffix(' %')
                        ->placeholder('—'),
                    TextEntry::make('calculated_selling_price')
                        ->label(__('forms.labels.calculated_selling_price'))
                        ->formatStateUsing(fn ($state) => $state ? Money::format($state) : null)
                        ->prefix('$ ')
                        ->placeholder('—')
                        ->weight(FontWeight::Bold)
                        ->color('success'),
                ])
                ->columns(2),
        ];
    }
}
