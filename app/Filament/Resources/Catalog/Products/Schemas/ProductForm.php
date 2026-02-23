<?php

namespace App\Filament\Resources\Catalog\Products\Schemas;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Tag;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class ProductForm
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
                    Select::make('category_id')
                        ->label('Category')
                        ->options(
                            fn () => Category::query()
                                ->active()
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Category $cat) => [$cat->id => $cat->full_path])
                        )
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Set $set, ?string $state) {
                            if ($state) {
                                // preview() não usa lock — é apenas uma sugestão visual.
                                // O SKU final e único é gerado no evento creating do Model.
                                $set('sku', app(GenerateProductSkuAction::class)->preview((int) $state));
                                $category = Category::find((int) $state);
                                if ($category) {
                                    $set('name', $category->name);
                                }
                            }
                        }),
                    TextInput::make('name')
                        ->label('Product Name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Auto-generated from category + required attributes. You can override manually.')
                        ->placeholder('Will be auto-generated from attributes'),
                    TextInput::make('sku')
                        ->label('SKU')
                        ->unique(ignoreRecord: true)
                        ->maxLength(50)
                        ->helperText('Auto-generated from category prefix. You can override manually.')
                        ->placeholder('Will be auto-generated on save'),
                    Select::make('status')
                        ->label('Status')
                        ->options(ProductStatus::class)
                        ->required()
                        ->default(ProductStatus::DRAFT),
                    Select::make('parent_id')
                        ->label('Variant Of')
                        ->relationship('parent', 'name', fn ($query) => $query->whereNull('parent_id'))
                        ->searchable()
                        ->preload()
                        ->placeholder('None (base product)')
                        ->helperText('Select a parent product if this is a variant.'),
                    FileUpload::make('avatar')
                        ->label('Product Image')
                        ->image()
                        ->directory('products')
                        ->maxSize(2048)
                        ->columnSpanFull(),
                ])
                ->columns(2),

            Section::make('International Trade')
                ->schema([
                    TextInput::make('hs_code')
                        ->label('HS Code')
                        ->maxLength(20)
                        ->placeholder('8539.50')
                        ->helperText('Harmonized System code for customs classification.'),
                    TextInput::make('origin_country')
                        ->label('Country of Origin')
                        ->maxLength(2)
                        ->placeholder('CN')
                        ->helperText('ISO 3166-1 alpha-2 code.'),
                    TextInput::make('brand')
                        ->label('Brand')
                        ->maxLength(255),
                    TextInput::make('model_number')
                        ->label('Model Number')
                        ->maxLength(255),
                    TextInput::make('certifications')
                        ->label('Certifications')
                        ->maxLength(255)
                        ->placeholder('CE, FCC, RoHS')
                        ->helperText('Comma-separated list of certifications.'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Order Defaults')
                ->schema([
                    TextInput::make('moq')
                        ->label('MOQ')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Minimum Order Quantity.'),
                    TextInput::make('moq_unit')
                        ->label('MOQ Unit')
                        ->maxLength(20)
                        ->default('pcs'),
                    TextInput::make('lead_time_days')
                        ->label('Lead Time (days)')
                        ->numeric()
                        ->minValue(0),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Tags & Notes')
                ->schema([
                    Select::make('tags')
                        ->label('Tags')
                        ->multiple()
                        ->relationship('tags', 'name')
                        ->preload()
                        ->searchable()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('slug')
                                ->required()
                                ->maxLength(255),
                        ]),
                    Textarea::make('description')
                        ->label('Description')
                        ->rows(3)
                        ->maxLength(5000),
                    Textarea::make('internal_notes')
                        ->label('Internal Notes')
                        ->rows(2)
                        ->maxLength(5000),
                ])
                ->collapsible()
                ->collapsed(),
        ];
    }

    protected static function specificationsTab(): array
    {
        return [
            Section::make('Dimensions & Weight')
                ->relationship('specification')
                ->schema([
                    TextInput::make('net_weight')
                        ->label('Net Weight (kg)')
                        ->numeric()
                        ->step(0.001)
                        ->minValue(0),
                    TextInput::make('gross_weight')
                        ->label('Gross Weight (kg)')
                        ->numeric()
                        ->step(0.001)
                        ->minValue(0),
                    TextInput::make('length')
                        ->label('Length (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                    TextInput::make('width')
                        ->label('Width (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                    TextInput::make('height')
                        ->label('Height (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                ])
                ->columns(3),

            Section::make('Material & Finish')
                ->relationship('specification')
                ->schema([
                    TextInput::make('material')
                        ->label('Material')
                        ->maxLength(255),
                    TextInput::make('color')
                        ->label('Color')
                        ->maxLength(255),
                    TextInput::make('finish')
                        ->label('Finish')
                        ->maxLength(255),
                    Textarea::make('notes')
                        ->label('Specification Notes')
                        ->rows(2)
                        ->maxLength(2000)
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
                    TextInput::make('pcs_per_inner_box')
                        ->label('Pcs / Inner Box')
                        ->numeric()
                        ->minValue(1),
                    TextInput::make('inner_box_length')
                        ->label('Length (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                    TextInput::make('inner_box_width')
                        ->label('Width (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                    TextInput::make('inner_box_height')
                        ->label('Height (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                    TextInput::make('inner_box_weight')
                        ->label('Weight (kg)')
                        ->numeric()
                        ->step(0.001)
                        ->minValue(0),
                ])
                ->columns(5),

            Section::make('Master Carton')
                ->relationship('packaging')
                ->schema([
                    TextInput::make('pcs_per_carton')
                        ->label('Pcs / Carton')
                        ->numeric()
                        ->minValue(1),
                    TextInput::make('inner_boxes_per_carton')
                        ->label('Inner Boxes / Carton')
                        ->numeric()
                        ->minValue(1),
                    TextInput::make('carton_length')
                        ->label('Length (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                    TextInput::make('carton_width')
                        ->label('Width (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                    TextInput::make('carton_height')
                        ->label('Height (cm)')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0),
                    TextInput::make('carton_weight')
                        ->label('Weight (kg)')
                        ->numeric()
                        ->step(0.001)
                        ->minValue(0),
                    TextInput::make('carton_cbm')
                        ->label('CBM')
                        ->numeric()
                        ->step(0.0001)
                        ->minValue(0)
                        ->helperText('Cubic meters per carton.'),
                ])
                ->columns(4),

            Section::make('Container Loading')
                ->relationship('packaging')
                ->schema([
                    TextInput::make('cartons_per_20ft')
                        ->label("Cartons / 20' GP")
                        ->numeric()
                        ->minValue(0),
                    TextInput::make('cartons_per_40ft')
                        ->label("Cartons / 40' GP")
                        ->numeric()
                        ->minValue(0),
                    TextInput::make('cartons_per_40hq')
                        ->label("Cartons / 40' HC")
                        ->numeric()
                        ->minValue(0),
                    Textarea::make('packing_notes')
                        ->label('Packing Notes')
                        ->rows(2)
                        ->maxLength(2000)
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
                    Select::make('currency_id')
                        ->label('Currency')
                        ->relationship('currency', 'code')
                        ->searchable()
                        ->preload()
                        ->helperText('Currency for all cost values below.'),
                    TextInput::make('base_price')
                        ->label('Base Price')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.0001)
                        ->prefix('$')
                        ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                        ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null),
                    TextInput::make('bom_material_cost')
                        ->label('BOM Material Cost')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.0001)
                        ->prefix('$')
                        ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                        ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null),
                    TextInput::make('direct_labor_cost')
                        ->label('Direct Labor Cost')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.0001)
                        ->prefix('$')
                        ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                        ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null),
                    TextInput::make('direct_overhead_cost')
                        ->label('Direct Overhead Cost')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.0001)
                        ->prefix('$')
                        ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                        ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null),
                    TextInput::make('total_manufacturing_cost')
                        ->label('Total Manufacturing Cost')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.0001)
                        ->prefix('$')
                        ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                        ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null)
                        ->helperText('Sum of BOM + Labor + Overhead.'),
                    TextInput::make('markup_percentage')
                        ->label('Markup %')
                        ->numeric()
                        ->step(0.01)
                        ->minValue(0)
                        ->suffix('%'),
                    TextInput::make('calculated_selling_price')
                        ->label('Calculated Selling Price')
                        ->numeric()
                        ->minValue(0)
                        ->step(0.0001)
                        ->prefix('$')
                        ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                        ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null)
                        ->helperText('Manufacturing cost × (1 + markup%).'),
                ])
                ->columns(2),
        ];
    }
}
