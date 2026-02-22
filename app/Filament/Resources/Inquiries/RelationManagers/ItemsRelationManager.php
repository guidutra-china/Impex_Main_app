<?php

namespace App\Filament\Resources\Inquiries\RelationManagers;

use App\Domain\Catalog\Models\Product;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Inquiry Items';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clipboard-document-list';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
                    ->options(function () {
                        return Product::query()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($p) => [$p->id => $p->sku . ' — ' . $p->name]);
                    })
                    ->searchable()
                    ->getSearchResultsUsing(function (string $search) {
                        return Product::query()
                            ->where(function ($query) use ($search) {
                                $query->where('name', 'like', "%{$search}%")
                                    ->orWhere('sku', 'like', "%{$search}%")
                                    ->orWhereHas('companies', function ($q) use ($search) {
                                        $q->where('company_product.external_code', 'like', "%{$search}%");
                                    });
                            })
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($p) => [$p->id => $p->sku . ' — ' . $p->name]);
                    })
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state) {
                        if ($state) {
                            $product = Product::find($state);
                            if ($product) {
                                $set('description', $product->name);
                            }
                        }
                    })
                    ->helperText('Search by name, SKU, or supplier/client code. Leave empty for free-text items.')
                    ->columnSpanFull(),

                TextInput::make('description')
                    ->label('Description')
                    ->maxLength(255)
                    ->helperText('Free-text description. Auto-filled from product if selected.')
                    ->columnSpanFull(),

                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),

                TextInput::make('unit')
                    ->label('Unit')
                    ->default('pcs')
                    ->maxLength(20)
                    ->required(),

                TextInput::make('target_price')
                    ->label('Target Price')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('$')
                    ->helperText('Client target price per unit, if provided.')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => $state ? (int) round((float) $state * 100) : null),

                Textarea::make('specifications')
                    ->label('Specifications')
                    ->rows(3)
                    ->maxLength(2000)
                    ->helperText('Client-provided specs, dimensions, certifications, etc.')
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('displayName')
                    ->label('Item')
                    ->searchable(['description'])
                    ->limit(40),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->alignCenter(),
                TextColumn::make('target_price')
                    ->label('Target Price')
                    ->formatStateUsing(fn ($state) => $state ? '$ ' . number_format($state / 100, 2) : '—')
                    ->alignEnd(),
                TextColumn::make('specifications')
                    ->label('Specs')
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->emptyStateHeading('No items')
            ->emptyStateDescription('Add products or free-text items that the client is requesting.');
    }
}
