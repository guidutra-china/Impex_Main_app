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
                    ->label(__('forms.labels.attribute'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('default_value')
                    ->label(__('forms.labels.default'))
                    ->placeholder('(none)'),
                TextColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('options')
                    ->label(__('forms.labels.options'))
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : '-')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_required')
                    ->label(__('forms.labels.required'))
                    ->boolean()
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->label(__('forms.labels.order'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('forms.labels.add_attribute'))
                    ->visible(fn () => auth()->user()?->can('manage-categories'))
                    ->modalHeading('Add Attribute Template')
                    ->modalDescription('Define an attribute that will be automatically added to products in this category.'),
            ])
            ->actions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('manage-categories')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('manage-categories')),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('manage-categories')),
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
                    ->label(__('forms.labels.attribute_name'))
                    ->required()
                    ->maxLength(255)
                    ->placeholder(__('forms.placeholders.eg_watts_cct_material')),
                Select::make('type')
                    ->label(__('forms.labels.field_type'))
                    ->options(AttributeType::class)
                    ->default('text')
                    ->required()
                    ->live(),
                TextInput::make('default_value')
                    ->label(__('forms.labels.default_value'))
                    ->maxLength(255)
                    ->placeholder(__('forms.placeholders.eg_100_6500k_aluminum'))
                    ->visible(fn (Get $get) => in_array($this->resolveTypeValue($get), ['text', 'number'])),
                TagsInput::make('options')
                    ->label(__('forms.labels.options'))
                    ->placeholder(__('forms.placeholders.add_option_and_press_enter'))
                    ->helperText(__('forms.helpers.define_the_selectable_options_for_this_attribute'))
                    ->visible(fn (Get $get) => $this->resolveTypeValue($get) === 'select'),
                TextInput::make('unit')
                    ->label(__('forms.labels.unit_of_measure'))
                    ->maxLength(50)
                    ->placeholder(__('forms.placeholders.eg_w_lm_kg_cm')),
                Checkbox::make('is_required')
                    ->label(__('forms.labels.required_attribute'))
                    ->default(false),
                TextInput::make('sort_order')
                    ->label(__('forms.labels.sort_order'))
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
