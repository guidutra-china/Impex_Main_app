<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use App\Domain\Catalog\Enums\ProductStatus;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
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
                    ->label('Variant Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(50),
                Select::make('status')
                    ->label('Status')
                    ->options(ProductStatus::class)
                    ->required()
                    ->default(ProductStatus::DRAFT),
                Select::make('category_id')
                    ->label('Category')
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
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Variant')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['category_id'] = $data['category_id'] ?? $this->getOwnerRecord()->category_id;
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
