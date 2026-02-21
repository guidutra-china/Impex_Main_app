<?php

namespace App\Filament\Resources\Quotations\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Quotation Items';

    protected static string | \BackedEnum | null $icon = 'heroicon-o-shopping-cart';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->options(
                        fn () => Product::query()
                            ->orderBy('name')
                            ->limit(100)
                            ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->getSearchResultsUsing(
                        fn (string $search) => Product::where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->limit(50)
                            ->pluck('name', 'id')
                    )
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->default(1),
                Select::make('selected_supplier_id')
                    ->label('Selected Supplier')
                    ->options(
                        fn () => Company::query()
                            ->whereHas('companyRoles', fn ($q) => $q->where('role', \App\Domain\CRM\Enums\CompanyRole::SUPPLIER))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->placeholder('Select supplier...'),
                TextInput::make('unit_cost')
                    ->label('Unit Cost')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('$')
                    ->inputMode('decimal')
                    ->default(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                        static::calculateUnitPrice($get, $set);
                    }),
                TextInput::make('commission_rate')
                    ->label('Commission Rate (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->default(0)
                    ->visible(fn () => $this->getOwnerRecord()->commission_type === CommissionType::EMBEDDED)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, \Filament\Schemas\Components\Utilities\Set $set) {
                        static::calculateUnitPrice($get, $set);
                    })
                    ->helperText('Commission embedded in the unit price.'),
                TextInput::make('unit_price')
                    ->label('Unit Price (to Client)')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('$')
                    ->inputMode('decimal')
                    ->default(0)
                    ->helperText('Auto-calculated from cost + commission. Override manually if needed.'),
                Select::make('incoterm')
                    ->label('Incoterm')
                    ->options(Incoterm::class)
                    ->placeholder('Select incoterm...'),
                TextInput::make('sort_order')
                    ->label('Sort Order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
                Textarea::make('notes')
                    ->label('Item Notes')
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->alignCenter()
                    ->width('50px'),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->limit(35)
                    ->weight('bold'),
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->searchable()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('selectedSupplier.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : '—')
                    ->alignEnd(),
                TextColumn::make('commission_rate')
                    ->label('Comm. %')
                    ->suffix('%')
                    ->alignCenter()
                    ->visible(fn () => $this->getOwnerRecord()->commission_type === CommissionType::EMBEDDED),
                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : '—')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('line_total')
                    ->label('Line Total')
                    ->getStateUsing(fn ($record) => number_format(($record->unit_price * $record->quantity) / 100, 2))
                    ->alignEnd()
                    ->weight('bold')
                    ->color('success'),
                TextColumn::make('incoterm')
                    ->label('Incoterm')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('Add Item')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = (int) round(($data['unit_cost'] ?? 0) * 100);
                        $data['unit_price'] = (int) round(($data['unit_price'] ?? 0) * 100);
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $data = $record->toArray();
                        $data['unit_cost'] = $data['unit_cost'] / 100;
                        $data['unit_price'] = $data['unit_price'] / 100;
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = (int) round(($data['unit_cost'] ?? 0) * 100);
                        $data['unit_price'] = (int) round(($data['unit_price'] ?? 0) * 100);
                        return $data;
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected static function calculateUnitPrice(Get $get, \Filament\Schemas\Components\Utilities\Set $set): void
    {
        $cost = (float) ($get('unit_cost') ?? 0);
        $commissionRate = (float) ($get('commission_rate') ?? 0);

        if ($cost > 0 && $commissionRate > 0) {
            $price = $cost * (1 + ($commissionRate / 100));
            $set('unit_price', round($price, 2));
        } elseif ($cost > 0) {
            $set('unit_price', round($cost, 2));
        }
    }
}
