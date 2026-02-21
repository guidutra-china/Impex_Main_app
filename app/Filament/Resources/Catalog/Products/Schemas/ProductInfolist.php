<?php

namespace App\Filament\Resources\Catalog\Products\Schemas;

use App\Domain\Catalog\Enums\ProductStatus;
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
                        Tabs\Tab::make('General')
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make('Specifications')
                            ->icon('heroicon-o-cog-6-tooth')
                            ->schema(static::specificationsTab()),
                        Tabs\Tab::make('Packaging')
                            ->icon('heroicon-o-archive-box')
                            ->schema(static::packagingTab()),
                        Tabs\Tab::make('Costing')
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
            Section::make('Product Identity')
                ->schema([
                    TextEntry::make('category.full_path')
                        ->label('Category')
                        ->icon('heroicon-o-folder')
                        ->placeholder('—'),
                    TextEntry::make('name')
                        ->label('Product Name')
                        ->weight(FontWeight::Bold)
                        ->size(TextSize::Large),
                    TextEntry::make('sku')
                        ->label('SKU')
                        ->badge()
                        ->color('gray')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge(),
                    TextEntry::make('parent.name')
                        ->label('Variant Of')
                        ->placeholder('Base product')
                        ->icon('heroicon-o-link'),
                ])
                ->columns(2),

            Section::make('International Trade')
                ->schema([
                    TextEntry::make('hs_code')
                        ->label('HS Code')
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('origin_country')
                        ->label('Country of Origin')
                        ->placeholder('—'),
                    TextEntry::make('brand')
                        ->label('Brand')
                        ->placeholder('—'),
                    TextEntry::make('model_number')
                        ->label('Model Number')
                        ->placeholder('—'),
                    TextEntry::make('certifications')
                        ->label('Certifications')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Order Defaults')
                ->schema([
                    TextEntry::make('moq')
                        ->label('MOQ')
                        ->placeholder('—'),
                    TextEntry::make('moq_unit')
                        ->label('MOQ Unit')
                        ->placeholder('—'),
                    TextEntry::make('lead_time_days')
                        ->label('Lead Time')
                        ->suffix(' days')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Tags & Notes')
                ->schema([
                    TextEntry::make('tags.name')
                        ->label('Tags')
                        ->badge()
                        ->color('info')
                        ->placeholder('No tags'),
                    TextEntry::make('description')
                        ->label('Description')
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->markdown(),
                    TextEntry::make('internal_notes')
                        ->label('Internal Notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible(),
        ];
    }

    protected static function specificationsTab(): array
    {
        return [
            Section::make('Dimensions & Weight')
                ->relationship('specification')
                ->schema([
                    TextEntry::make('net_weight')
                        ->label('Net Weight')
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('gross_weight')
                        ->label('Gross Weight')
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('length')
                        ->label('Length')
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('width')
                        ->label('Width')
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('height')
                        ->label('Height')
                        ->suffix(' cm')
                        ->placeholder('—'),
                ])
                ->columns(3),

            Section::make('Material & Finish')
                ->relationship('specification')
                ->schema([
                    TextEntry::make('material')
                        ->label('Material')
                        ->placeholder('—'),
                    TextEntry::make('color')
                        ->label('Color')
                        ->placeholder('—'),
                    TextEntry::make('finish')
                        ->label('Finish')
                        ->placeholder('—'),
                    TextEntry::make('notes')
                        ->label('Specification Notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ];
    }

    protected static function packagingTab(): array
    {
        return [
            Section::make('Inner Box')
                ->relationship('packaging')
                ->schema([
                    TextEntry::make('pcs_per_inner_box')
                        ->label('Pcs / Inner Box')
                        ->placeholder('—'),
                    TextEntry::make('inner_box_length')
                        ->label('Length')
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('inner_box_width')
                        ->label('Width')
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('inner_box_height')
                        ->label('Height')
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('inner_box_weight')
                        ->label('Weight')
                        ->suffix(' kg')
                        ->placeholder('—'),
                ])
                ->columns(5),

            Section::make('Master Carton')
                ->relationship('packaging')
                ->schema([
                    TextEntry::make('pcs_per_carton')
                        ->label('Pcs / Carton')
                        ->placeholder('—'),
                    TextEntry::make('inner_boxes_per_carton')
                        ->label('Inner Boxes / Carton')
                        ->placeholder('—'),
                    TextEntry::make('carton_length')
                        ->label('Length')
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('carton_width')
                        ->label('Width')
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('carton_height')
                        ->label('Height')
                        ->suffix(' cm')
                        ->placeholder('—'),
                    TextEntry::make('carton_weight')
                        ->label('Weight')
                        ->suffix(' kg')
                        ->placeholder('—'),
                    TextEntry::make('carton_cbm')
                        ->label('CBM')
                        ->suffix(' m³')
                        ->placeholder('—'),
                ])
                ->columns(4),

            Section::make('Container Loading')
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
                        ->label('Packing Notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ];
    }

    protected static function costingTab(): array
    {
        return [
            Section::make('Cost Breakdown')
                ->relationship('costing')
                ->schema([
                    TextEntry::make('currency.code')
                        ->label('Currency')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('base_price')
                        ->label('Base Price')
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : null)
                        ->prefix('$ ')
                        ->placeholder('—'),
                    TextEntry::make('bom_material_cost')
                        ->label('BOM Material Cost')
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : null)
                        ->prefix('$ ')
                        ->placeholder('—'),
                    TextEntry::make('direct_labor_cost')
                        ->label('Direct Labor Cost')
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : null)
                        ->prefix('$ ')
                        ->placeholder('—'),
                    TextEntry::make('direct_overhead_cost')
                        ->label('Direct Overhead Cost')
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : null)
                        ->prefix('$ ')
                        ->placeholder('—'),
                    TextEntry::make('total_manufacturing_cost')
                        ->label('Total Manufacturing Cost')
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : null)
                        ->prefix('$ ')
                        ->placeholder('—')
                        ->weight(FontWeight::Bold),
                    TextEntry::make('markup_percentage')
                        ->label('Markup')
                        ->suffix(' %')
                        ->placeholder('—'),
                    TextEntry::make('calculated_selling_price')
                        ->label('Calculated Selling Price')
                        ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : null)
                        ->prefix('$ ')
                        ->placeholder('—')
                        ->weight(FontWeight::Bold)
                        ->color('success'),
                ])
                ->columns(2),
        ];
    }
}
