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
use Filament\Schemas\Schema;
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
            ->with(['product', 'product.category', 'product.specification', 'product.costing'])
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
            Section::make('Product Details')
                ->schema([
                    TextEntry::make('avatar_url')
                        ->label('')
                        ->formatStateUsing(fn ($state) => $state ? '<img src="' . e($state) . '" class="w-20 h-20 rounded-full object-cover" />' : '')
                        ->html()
                        ->columnSpanFull(),

                    TextEntry::make('product.sku')
                        ->label(__('forms.labels.sku'))
                        ->copyable()
                        ->weight('bold'),

                    TextEntry::make('product.name')
                        ->label(__('forms.labels.product_name'))
                        ->weight('bold'),

                    TextEntry::make('product.category.name')
                        ->label(__('forms.labels.category'))
                        ->badge()
                        ->color('primary'),

                    TextEntry::make('product.brand')
                        ->label(__('forms.labels.brand'))
                        ->placeholder('—'),

                    TextEntry::make('product.model_number')
                        ->label(__('forms.labels.model_number'))
                        ->placeholder('—'),

                    TextEntry::make('product.origin_country')
                        ->label(__('forms.labels.origin_country'))
                        ->placeholder('—'),

                    TextEntry::make('product.hs_code')
                        ->label(__('forms.labels.hs_code'))
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Your Product Reference')
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
                ->columnSpanFull(),

            Section::make('Pricing & Terms')
                ->schema([
                    TextEntry::make('unit_price')
                        ->label(__('forms.labels.selling_price'))
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 4))
                        ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),

                    TextEntry::make('custom_price')
                        ->label(__('forms.labels.ci_price'))
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 4))
                        ->placeholder('—')
                        ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),

                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray')
                        ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),

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
                ->columnSpanFull(),

            Section::make('Specifications')
                ->schema([
                    TextEntry::make('product.specification.net_weight')
                        ->label('Net Weight (kg)')
                        ->placeholder('—'),

                    TextEntry::make('product.specification.length')
                        ->label('Length (cm)')
                        ->placeholder('—'),

                    TextEntry::make('product.specification.width')
                        ->label('Width (cm)')
                        ->placeholder('—'),

                    TextEntry::make('product.specification.height')
                        ->label('Height (cm)')
                        ->placeholder('—'),

                    TextEntry::make('product.specification.material')
                        ->label('Material')
                        ->placeholder('—'),

                    TextEntry::make('product.specification.color')
                        ->label('Color')
                        ->placeholder('—'),
                ])
                ->columns(3)
                ->collapsible()
                ->columnSpanFull(),

            Section::make('Notes')
                ->schema([
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
        ]);
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
