<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\CategoryAttribute;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;

class AttributeValuesRelationManager extends RelationManager
{
    protected static string $relationship = 'attributeValues';

    protected static ?string $title = 'Attributes';

    protected static ?string $recordTitleAttribute = 'value';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('categoryAttribute.name')
                    ->label('Attribute')
                    ->weight('bold')
                    ->sortable(),
                TextColumn::make('value')
                    ->label('Value')
                    ->searchable(),
                TextColumn::make('categoryAttribute.unit')
                    ->label('Unit')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('categoryAttribute.category.name')
                    ->label('Inherited From')
                    ->placeholder('(own category)')
                    ->color('gray')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Attribute Value'),
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
            ->emptyStateHeading('No attributes')
            ->emptyStateDescription('Attributes are auto-populated when a product is created with a category. You can also add them manually.')
            ->emptyStateIcon('heroicon-o-sparkles');
    }

    public function form(Schema $schema): Schema
    {
        $product = $this->getOwnerRecord();
        $categoryId = $product->category_id;

        $availableAttributes = collect();
        if ($categoryId) {
            $category = \App\Domain\Catalog\Models\Category::find($categoryId);
            if ($category) {
                $availableAttributes = $category->getAllAttributes();
            }
        }

        $existingAttributeIds = $product->attributeValues()->pluck('category_attribute_id')->toArray();

        return $schema
            ->components([
                Select::make('category_attribute_id')
                    ->label('Attribute')
                    ->options(
                        $availableAttributes
                            ->reject(fn ($attr) => in_array($attr->id, $existingAttributeIds))
                            ->mapWithKeys(fn ($attr) => [
                                $attr->id => $attr->name . ($attr->unit ? " ({$attr->unit})" : '') . ' [' . $attr->category->name . ']',
                            ])
                    )
                    ->required()
                    ->searchable()
                    ->disabledOn('edit'),
                TextInput::make('value')
                    ->label('Value')
                    ->required()
                    ->maxLength(255),
            ]);
    }
}
