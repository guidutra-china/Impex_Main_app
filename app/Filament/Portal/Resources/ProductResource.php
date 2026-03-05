<?php

namespace App\Filament\Portal\Resources;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Portal\Resources\ProductResource\Pages;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductResource extends Resource
{
    protected static ?string $model = CompanyProduct::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'products';

    protected static ?string $recordTitleAttribute = 'product.name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('portal:view-products') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /**
     * Scope the query to only the current tenant's client-role products.
     *
     * We deliberately avoid a JOIN to the products table here because Filament's
     * multi-tenancy layer appends its own whereKey() clause after this method runs,
     * and that clause uses the unqualified column name 'id', which becomes ambiguous
     * when a JOIN is present. Instead we add a correlated subquery column
     * (product_name) that can be used for sorting without any JOIN.
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        $query = parent::getEloquentQuery()
            ->with([
                'product',
                'product.category',
                'product.specification',
                'product.packaging',
                'product.tags',
                'product.attributeValues.categoryAttribute',
            ])
            ->addSelect([
                'product_name' => \App\Domain\Catalog\Models\Product::select('name')
                    ->whereColumn('products.id', 'company_product.product_id')
                    ->limit(1),
            ])
            ->where('role', 'client');

        if ($tenant) {
            $query->where('company_id', $tenant->getKey());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        $showFinancial = auth()->user()?->can('portal:view-financial-summary') ?? false;

        return $table
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=P&background=e2e8f0&color=64748b&size=40')
                    ->width('50px'),

                TextColumn::make('product.sku')
                    ->label(__('forms.labels.sku'))
                    ->searchable()
                    ->weight('bold')
                    ->copyable(),

                TextColumn::make('product.name')
                    ->label(__('forms.labels.product_name'))
                    ->searchable()
                    ->limit(40),

                TextColumn::make('product.category.name')
                    ->label(__('forms.labels.category'))
                    ->badge()
                    ->color('primary'),

                TextColumn::make('external_code')
                    ->label(__('forms.labels.client_code'))
                    ->placeholder('—'),

                TextColumn::make('external_name')
                    ->label(__('forms.labels.client_product_name'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('unit_price')
                    ->label(__('forms.labels.selling_price'))
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd()
                    ->visible($showFinancial),

                TextColumn::make('custom_price')
                    ->label(__('forms.labels.ci_price'))
                    ->formatStateUsing(fn ($state) => $state ? Money::format($state, 4) : '—')
                    ->prefix('$ ')
                    ->alignEnd()
                    ->visible($showFinancial),

                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->placeholder('—')
                    ->visible($showFinancial),

                TextColumn::make('incoterm')
                    ->label(__('forms.labels.incoterm'))
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('moq')
                    ->label(__('forms.labels.moq'))
                    ->placeholder('—')
                    ->alignEnd(),

                TextColumn::make('lead_time_days')
                    ->label(__('forms.labels.lead_time_days'))
                    ->suffix(' days')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordUrl(fn (CompanyProduct $record) => Pages\ViewProduct::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (CompanyProduct $record) => Pages\ViewProduct::getUrl(['record' => $record])),
            ])
            ->defaultSort('product_name')
            ->emptyStateHeading('No products')
            ->emptyStateDescription('No products are linked to your company yet.')
            ->emptyStateIcon('heroicon-o-cube');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
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

                    Tabs\Tab::make('Pricing & Terms')
                        ->icon('heroicon-o-banknotes')
                        ->schema(static::pricingTab()),
                ])
                ->columnSpanFull()
                ->persistTabInQueryString(),
        ]);
    }

    protected static function generalTab(): array
    {
        return [
            // Avatar / product image
            Section::make('')
                ->schema([
                    TextEntry::make('avatar_url')
                        ->label('')
                        ->formatStateUsing(fn ($state) => $state
                            ? '<img src="' . e($state) . '" class="w-24 h-24 rounded-lg object-cover shadow" />'
                            : '<div class="w-24 h-24 rounded-lg bg-slate-100 flex items-center justify-center text-slate-400 text-2xl">📦</div>')
                        ->html()
                        ->columnSpanFull(),
                ])
                ->extraAttributes(['class' => 'border-0 shadow-none p-0']),

            Section::make(__('forms.sections.product_identity'))
                ->schema([
                    TextEntry::make('product.category.name')
                        ->label(__('forms.labels.category'))
                        ->icon('heroicon-o-folder')
                        ->badge()
                        ->color('primary')
                        ->placeholder('—'),

                    TextEntry::make('product.name')
                        ->label(__('forms.labels.product_name'))
                        ->weight(FontWeight::Bold)
                        ->size(TextSize::Large),

                    TextEntry::make('product.sku')
                        ->label(__('forms.labels.sku'))
                        ->badge()
                        ->color('gray')
                        ->copyable(),

                    TextEntry::make('product.status')
                        ->label(__('forms.labels.status'))
                        ->badge(),

                    TextEntry::make('product.parent.name')
                        ->label(__('forms.labels.variant_of'))
                        ->placeholder(__('forms.placeholders.base_product_2'))
                        ->icon('heroicon-o-link'),
                ])
                ->columns(2),

            Section::make(__('forms.sections.international_trade'))
                ->schema([
                    TextEntry::make('product.hs_code')
                        ->label(__('forms.labels.hs_code'))
                        ->placeholder('—')
                        ->copyable(),

                    TextEntry::make('product.origin_country')
                        ->label(__('forms.labels.country_of_origin'))
                        ->placeholder('—'),

                    TextEntry::make('product.brand')
                        ->label(__('forms.labels.brand'))
                        ->placeholder('—'),

                    TextEntry::make('product.model_number')
                        ->label(__('forms.labels.model_number'))
                        ->placeholder('—'),

                    TextEntry::make('product.certifications')
                        ->label(__('forms.labels.certifications'))
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make(__('forms.sections.order_defaults'))
                ->schema([
                    TextEntry::make('product.moq')
                        ->label(__('forms.labels.moq'))
                        ->placeholder('—'),

                    TextEntry::make('product.moq_unit')
                        ->label(__('forms.labels.moq_unit'))
                        ->placeholder('—'),

                    TextEntry::make('product.lead_time_days')
                        ->label(__('forms.labels.lead_time'))
                        ->suffix(' days')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Your Reference')
                ->schema([
                    TextEntry::make('external_code')
                        ->label(__('forms.labels.client_code'))
                        ->placeholder('—'),

                    TextEntry::make('external_name')
                        ->label(__('forms.labels.client_product_name'))
                        ->placeholder('—'),

                    TextEntry::make('external_description')
                        ->label(__('forms.labels.client_product_description'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make(__('forms.sections.tags_notes'))
                ->schema([
                    TextEntry::make('product.tags.name')
                        ->label(__('forms.labels.tags'))
                        ->badge()
                        ->color('info')
                        ->placeholder(__('forms.placeholders.no_tags')),

                    TextEntry::make('product.description')
                        ->label(__('forms.labels.description'))
                        ->placeholder('—')
                        ->columnSpanFull()
                        ->markdown(),

                    TextEntry::make('notes')
                        ->label('Your Notes')
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
                ->schema([
                    TextEntry::make('product.specification.net_weight')
                        ->label(__('forms.labels.net_weight_1_pc'))
                        ->suffix(' kg')
                        ->placeholder('—'),

                    TextEntry::make('product.specification.length')
                        ->label(__('forms.labels.length'))
                        ->suffix(' cm')
                        ->placeholder('—'),

                    TextEntry::make('product.specification.width')
                        ->label(__('forms.labels.width'))
                        ->suffix(' cm')
                        ->placeholder('—'),

                    TextEntry::make('product.specification.height')
                        ->label(__('forms.labels.height'))
                        ->suffix(' cm')
                        ->placeholder('—'),
                ])
                ->columns(3),

            Section::make(__('forms.sections.material_finish'))
                ->schema([
                    TextEntry::make('product.specification.material')
                        ->label(__('forms.labels.material'))
                        ->placeholder('—'),

                    TextEntry::make('product.specification.color')
                        ->label(__('forms.labels.color'))
                        ->placeholder('—'),

                    TextEntry::make('product.specification.finish')
                        ->label(__('forms.labels.finish'))
                        ->placeholder('—'),

                    TextEntry::make('product.specification.notes')
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
                ->schema([
                    TextEntry::make('product.packaging.packaging_type')
                        ->label(__('forms.labels.packaging_type'))
                        ->badge()
                        ->placeholder('—'),
                ])
                ->columns(3),

            Section::make(__('forms.sections.inner_box'))
                ->schema([
                    TextEntry::make('product.packaging.pcs_per_inner_box')
                        ->label(__('forms.labels.pcs_inner_box'))
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.inner_box_length')
                        ->label(__('forms.labels.length'))
                        ->suffix(' cm')
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.inner_box_width')
                        ->label(__('forms.labels.width'))
                        ->suffix(' cm')
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.inner_box_height')
                        ->label(__('forms.labels.height'))
                        ->suffix(' cm')
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.inner_box_weight')
                        ->label(__('forms.labels.gw_inner_box'))
                        ->suffix(' kg')
                        ->placeholder('—'),
                ])
                ->columns(5),

            Section::make(__('forms.sections.master_carton'))
                ->schema([
                    TextEntry::make('product.packaging.pcs_per_carton')
                        ->label(__('forms.labels.pcs_carton'))
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.inner_boxes_per_carton')
                        ->label(__('forms.labels.inner_boxes_carton'))
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.carton_length')
                        ->label(__('forms.labels.length'))
                        ->suffix(' cm')
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.carton_width')
                        ->label(__('forms.labels.width'))
                        ->suffix(' cm')
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.carton_height')
                        ->label(__('forms.labels.height'))
                        ->suffix(' cm')
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.carton_net_weight')
                        ->label(__('forms.labels.nw_carton'))
                        ->suffix(' kg')
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.carton_weight')
                        ->label(__('forms.labels.gw_carton'))
                        ->suffix(' kg')
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.carton_cbm')
                        ->label(__('forms.labels.cbm_carton'))
                        ->suffix(' m³')
                        ->placeholder('—'),
                ])
                ->columns(4),

            Section::make(__('forms.sections.container_loading'))
                ->schema([
                    TextEntry::make('product.packaging.cartons_per_20ft')
                        ->label("Cartons / 20' GP")
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.cartons_per_40ft')
                        ->label("Cartons / 40' GP")
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.cartons_per_40hq')
                        ->label("Cartons / 40' HC")
                        ->placeholder('—'),

                    TextEntry::make('product.packaging.packing_notes')
                        ->label(__('forms.labels.packing_notes'))
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3),
        ];
    }

    protected static function pricingTab(): array
    {
        $showFinancial = auth()->user()?->can('portal:view-financial-summary') ?? false;

        return [
            Section::make('Your Pricing')
                ->schema([
                    TextEntry::make('unit_price')
                        ->label(__('forms.labels.selling_price'))
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 4))
                        ->placeholder('—'),

                    TextEntry::make('custom_price')
                        ->label(__('forms.labels.ci_price'))
                        ->formatStateUsing(fn ($state, $record) => $state ? ($record->currency_code ?? '') . ' ' . Money::format($state, 4) : '—')
                        ->placeholder('—'),

                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray')
                        ->placeholder('—'),

                    TextEntry::make('incoterm')
                        ->label(__('forms.labels.incoterm'))
                        ->badge()
                        ->placeholder('—'),

                    TextEntry::make('moq')
                        ->label(__('forms.labels.moq'))
                        ->placeholder('—'),

                    TextEntry::make('lead_time_days')
                        ->label(__('forms.labels.lead_time_days'))
                        ->suffix(' days')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->visible($showFinancial),

            Section::make('Access Restricted')
                ->schema([
                    TextEntry::make('_access_notice')
                        ->label('')
                        ->default('Financial information is not available for your account role. Please contact your account manager if you need pricing details.')
                        ->columnSpanFull(),
                ])
                ->visible(! $showFinancial),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'view' => Pages\ViewProduct::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.products');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.product');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.products');
    }
}
