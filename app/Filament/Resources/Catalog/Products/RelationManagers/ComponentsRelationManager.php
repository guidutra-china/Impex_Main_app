<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'components';

    protected static ?string $title = 'Components / BOM';

    protected static BackedEnum|string|null $icon = 'heroicon-o-puzzle-piece';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Component Name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g. LCD Panel, Power Supply, PCB Board'),
                TextInput::make('quantity_required')
                    ->label('Qty Required')
                    ->numeric()
                    ->default(1)
                    ->minValue(0.01)
                    ->required(),
                TextInput::make('unit')
                    ->label('Unit')
                    ->maxLength(20)
                    ->placeholder('pcs, kg, m'),
                TextInput::make('default_supplier_name')
                    ->label('Default Supplier')
                    ->maxLength(255)
                    ->placeholder('Supplier name'),
                TextInput::make('lead_time_days')
                    ->label('Lead Time (days)')
                    ->numeric()
                    ->minValue(0),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Component')
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('quantity_required')
                    ->label('Qty')
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->placeholder('—')
                    ->size('sm'),
                TextColumn::make('default_supplier_name')
                    ->label('Default Supplier')
                    ->placeholder('—')
                    ->size('sm')
                    ->limit(30),
                TextColumn::make('lead_time_days')
                    ->label('Lead Time')
                    ->suffix(' days')
                    ->placeholder('—')
                    ->alignCenter()
                    ->size('sm'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Component')
                    ->visible(fn () => auth()->user()?->can('edit-products')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products')),
            ])
            ->bulkActions([
                DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-products')),
            ]);
    }
}
