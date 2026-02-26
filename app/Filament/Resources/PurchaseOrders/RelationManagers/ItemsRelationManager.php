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
                ->label(__('forms.labels.product'))
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
                ->label(__('forms.labels.description'))
                ->maxLength(255),

            Textarea::make('specifications')
                ->label(__('forms.labels.specifications'))
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('quantity')
                ->label(__('forms.labels.quantity'))
                ->numeric()
                ->required()
                ->minValue(1)
                ->default(1),

            TextInput::make('unit')
                ->label(__('forms.labels.unit'))
                ->default('pcs')
                ->maxLength(20),

            TextInput::make('unit_cost')
                ->label(__('forms.labels.unit_cost_supplier'))
                ->numeric()
                ->required()
                ->prefix('$')
                ->step(0.0001)
                ->minValue(0),

            Select::make('incoterm')
                ->label(__('forms.labels.incoterm'))
                ->options(Incoterm::class)
                ->searchable(),

            Textarea::make('notes')
                ->label(__('forms.labels.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('forms.labels.hash'))
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('product.name')
                    ->label(__('forms.labels.product'))
                    ->searchable()
                    ->limit(30)
                    ->placeholder(__('forms.placeholders.manual_item')),
                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->alignCenter(),
                TextColumn::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->formatStateUsing(fn ($state) => \App\Domain\Infrastructure\Support\Money::format($state, 4))
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('line_total')
                    ->label(__('forms.labels.total'))
                    ->getStateUsing(fn ($record) => $record->line_total)
                    ->formatStateUsing(fn ($state) => \App\Domain\Infrastructure\Support\Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('proformaInvoiceItem.id')
                    ->label(__('forms.labels.pi_item'))
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-purchase-orders'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = \App\Domain\Infrastructure\Support\Money::toMinor($data['unit_cost'] ?? 0);
                        $data['sort_order'] = $this->getOwnerRecord()->items()->max('sort_order') + 1;

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-purchase-orders'))
                    ->mountUsing(function ($form, $record) {
                        $data = $record->toArray();
                        $data['unit_cost'] = \App\Domain\Infrastructure\Support\Money::toMajor($data['unit_cost']);
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = \App\Domain\Infrastructure\Support\Money::toMinor($data['unit_cost'] ?? 0);

                        return $data;
                    }),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-purchase-orders')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-purchase-orders')),
                ]),
            ])
            ->reorderable('sort_order');
    }
}
