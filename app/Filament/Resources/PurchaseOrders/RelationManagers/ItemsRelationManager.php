<?php

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Domain\Quotations\Enums\Incoterm;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    protected static BackedEnum|string|null $icon = 'heroicon-o-cube';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')
                ->label('Product')
                ->options(
                    fn () => Product::active()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn ($p) => [$p->id => $p->sku . ' — ' . $p->name])
                )
                ->searchable()
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if ($state) {
                        $product = Product::with('specification')->find($state);
                        if ($product) {
                            $set('description', $product->name);
                            $set('specifications', $product->specification?->description);
                        }
                    }
                })
                ->columnSpanFull(),

            TextInput::make('description')
                ->label('Description')
                ->maxLength(255),

            Textarea::make('specifications')
                ->label('Specifications')
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('quantity')
                ->label('Quantity')
                ->numeric()
                ->required()
                ->minValue(1)
                ->default(1),

            TextInput::make('unit')
                ->label('Unit')
                ->default('pcs')
                ->maxLength(20),

            TextInput::make('unit_cost')
                ->label('Unit Cost (Supplier)')
                ->numeric()
                ->required()
                ->prefix('$')
                ->step(0.01)
                ->minValue(0),

            Select::make('incoterm')
                ->label('Incoterm')
                ->options(Incoterm::class)
                ->searchable(),

            Textarea::make('notes')
                ->label('Notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->limit(30)
                    ->placeholder('Manual item'),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->alignCenter(),
                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('line_total')
                    ->label('Total')
                    ->getStateUsing(fn ($record) => $record->line_total)
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('proformaInvoiceItem.id')
                    ->label('PI Item')
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = (int) round(($data['unit_cost'] ?? 0) * 100);
                        $data['sort_order'] = $this->getOwnerRecord()->items()->max('sort_order') + 1;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $data = $record->toArray();
                        $data['unit_cost'] = $data['unit_cost'] / 100;
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = (int) round(($data['unit_cost'] ?? 0) * 100);

                        return $data;
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('sort_order');
    }
}
