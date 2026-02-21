<?php

namespace App\Filament\Resources\Catalog\Categories\RelationManagers;

use App\Domain\Catalog\Enums\AttributeType;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;

class CategoryAttributesRelationManager extends RelationManager
{
    protected static string $relationship = 'categoryAttributes';

    protected static ?string $title = 'Attribute Templates';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Attribute')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('default_value')
                    ->label('Default')
                    ->placeholder('(none)'),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('options')
                    ->label('Options')
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : '-')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_required')
                    ->label('Required')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Attribute')
                    ->modalHeading('Add Attribute Template')
                    ->modalDescription('Define an attribute that will be automatically added to products in this category.'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('sort_order', 'asc')
            ->reorderable('sort_order')
            ->emptyStateHeading('No attribute templates')
            ->emptyStateDescription('Add attribute templates that will be automatically applied to products in this category.')
            ->emptyStateIcon('heroicon-o-sparkles');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Attribute Name')
                    ->required()
                    ->maxLength(255)
                    ->placeholder('e.g., Watts, CCT, Material'),
                Select::make('type')
                    ->label('Field Type')
                    ->options(AttributeType::class)
                    ->default('text')
                    ->required()
                    ->live(),
                TextInput::make('default_value')
                    ->label('Default Value')
                    ->maxLength(255)
                    ->placeholder('e.g., 100, 6500K, Aluminum')
                    ->visible(fn (Get $get) => in_array($this->resolveTypeValue($get), ['text', 'number'])),
                TagsInput::make('options')
                    ->label('Options')
                    ->placeholder('Add option and press Enter')
                    ->helperText('Define the selectable options for this attribute.')
                    ->visible(fn (Get $get) => $this->resolveTypeValue($get) === 'select'),
                TextInput::make('unit')
                    ->label('Unit of Measure')
                    ->maxLength(50)
                    ->placeholder('e.g., W, lm, kg, cm'),
                Checkbox::make('is_required')
                    ->label('Required Attribute')
                    ->default(false),
                TextInput::make('sort_order')
                    ->label('Sort Order')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
            ]);
    }

    private function resolveTypeValue(Get $get): ?string
    {
        $type = $get('type');

        if ($type instanceof AttributeType) {
            return $type->value;
        }

        return $type;
    }
}
