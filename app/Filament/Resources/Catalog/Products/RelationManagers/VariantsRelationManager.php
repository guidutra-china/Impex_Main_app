<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Infrastructure\Support\Money;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Variants';

    protected static BackedEnum|string|null $icon = 'heroicon-o-square-3-stack-3d';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('forms.labels.variant_name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('sku')
                    ->label(__('forms.labels.sku'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(50),
                Select::make('status')
                    ->label(__('forms.labels.status'))
                    ->options(ProductStatus::class)
                    ->required()
                    ->default(ProductStatus::DRAFT->value),
                Select::make('category_id')
                    ->label(__('forms.labels.category'))
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('avatar')
                    ->label('')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?name=P&background=e2e8f0&color=64748b&size=40'),
                TextColumn::make('sku')
                    ->label(__('forms.labels.sku'))
                    ->searchable()
                    ->weight('bold')
                    ->copyable()
                    ->size('sm'),
                TextColumn::make('name')
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->name),
                TextColumn::make('specification.color')
                    ->label(__('forms.labels.color'))
                    ->placeholder('—')
                    ->size('sm'),
                TextColumn::make('specification.material')
                    ->label(__('forms.labels.material'))
                    ->placeholder('—')
                    ->size('sm'),
                TextColumn::make('costing.base_price')
                    ->label(__('forms.labels.base_price'))
                    ->formatStateUsing(function ($state, $record) {
                        if (! $state) {
                            return '—';
                        }
                        $symbol = $record->costing?->currency?->symbol ?? '$';
                        return $symbol . ' ' . number_format(Money::toMajor($state), 2);
                    })
                    ->size('sm'),
                TextColumn::make('suppliers_count')
                    ->label(__('forms.labels.suppliers'))
                    ->counts('suppliers')
                    ->badge()
                    ->color('warning')
                    ->size('sm'),
                TextColumn::make('clients_count')
                    ->label(__('forms.labels.clients'))
                    ->counts('clients')
                    ->badge()
                    ->color('info')
                    ->size('sm'),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('forms.labels.add_variant'))
                    ->visible(fn () => auth()->user()?->can('edit-products'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['category_id'] = $data['category_id'] ?? $this->getOwnerRecord()->category_id;
                        return $data;
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => route('filament.admin.resources.catalog.products.view', $record)),
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('delete-products')),
            ]);
    }
}
