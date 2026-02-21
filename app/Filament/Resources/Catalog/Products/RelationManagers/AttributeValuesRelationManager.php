<?php

namespace App\Filament\Resources\Catalog\Products\RelationManagers;

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\CategoryAttribute;
use App\Domain\Catalog\Services\DuplicateProductDetector;
use App\Domain\Catalog\Services\ProductNameGenerator;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
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
use Filament\Notifications\Notification;

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
                    ->formatStateUsing(function ($state, $record) {
                        $attr = $record->categoryAttribute;
                        if (!$attr) {
                            return $state;
                        }

                        if ($attr->type === AttributeType::BOOLEAN) {
                            return $state === '1' ? 'Yes' : 'No';
                        }

                        return $state;
                    })
                    ->searchable(),
                TextColumn::make('categoryAttribute.unit')
                    ->label('Unit')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                TextColumn::make('categoryAttribute.type')
                    ->label('Type')
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('categoryAttribute.category.name')
                    ->label('Inherited From')
                    ->placeholder('(own category)')
                    ->color('gray')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add Attribute Value')
                    ->mutateFormDataUsing(fn (array $data) => $this->normalizeValueField($data))
                    ->after(fn () => $this->afterAttributeSaved()),
            ])
            ->actions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data) => $this->expandValueField($data))
                    ->mutateFormDataUsing(fn (array $data) => $this->normalizeValueField($data))
                    ->after(fn () => $this->afterAttributeSaved()),
                DeleteAction::make()
                    ->after(fn () => $this->afterAttributeSaved()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->modifyQueryUsing(fn ($query) => $query
                ->join('category_attributes', 'product_attribute_values.category_attribute_id', '=', 'category_attributes.id')
                ->orderBy('category_attributes.sort_order')
                ->select('product_attribute_values.*')
            )
            ->emptyStateHeading('No attributes')
            ->emptyStateDescription('Attributes are auto-populated when a product is created with a category.')
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

        return $schema
            ->components([
                Select::make('category_attribute_id')
                    ->label('Attribute')
                    ->options(function (?string $operation, $record) use ($availableAttributes, $product) {
                        $existingIds = $product->attributeValues()
                            ->pluck('category_attribute_id')
                            ->toArray();

                        if ($operation === 'edit' && $record) {
                            $existingIds = array_values(
                                array_filter($existingIds, fn ($id) => $id != $record->category_attribute_id)
                            );
                        }

                        return $availableAttributes
                            ->reject(fn ($attr) => in_array($attr->id, $existingIds))
                            ->mapWithKeys(fn ($attr) => [
                                $attr->id => $attr->name
                                    . ($attr->unit ? " ({$attr->unit})" : '')
                                    . ' [' . $attr->category->name . ']',
                            ]);
                    })
                    ->required()
                    ->searchable()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('value_text', null);
                        $set('value_number', null);
                        $set('value_select', null);
                        $set('value_boolean', false);
                    })
                    ->disabledOn('edit'),

                TextInput::make('value_text')
                    ->label('Value')
                    ->maxLength(255)
                    ->dehydratedWhenHidden()
                    ->visible(fn (Get $get) => $this->resolveType($get) === AttributeType::TEXT)
                    ->required(fn (Get $get) => $this->resolveType($get) === AttributeType::TEXT)
                    ->suffix(fn (Get $get) => $this->resolveUnit($get)),

                TextInput::make('value_number')
                    ->label('Value')
                    ->numeric()
                    ->dehydratedWhenHidden()
                    ->visible(fn (Get $get) => $this->resolveType($get) === AttributeType::NUMBER)
                    ->required(fn (Get $get) => $this->resolveType($get) === AttributeType::NUMBER)
                    ->suffix(fn (Get $get) => $this->resolveUnit($get)),

                Select::make('value_select')
                    ->label('Value')
                    ->options(function (Get $get) {
                        $attr = $this->resolveAttr($get);
                        if (!$attr || !is_array($attr->options)) {
                            return [];
                        }
                        return array_combine($attr->options, $attr->options);
                    })
                    ->searchable()
                    ->dehydratedWhenHidden()
                    ->visible(fn (Get $get) => $this->resolveType($get) === AttributeType::SELECT)
                    ->required(fn (Get $get) => $this->resolveType($get) === AttributeType::SELECT),

                Toggle::make('value_boolean')
                    ->label('Value')
                    ->dehydratedWhenHidden()
                    ->visible(fn (Get $get) => $this->resolveType($get) === AttributeType::BOOLEAN),
            ]);
    }

    /**
     * Before filling the edit form: expand `value` into the correct typed field.
     */
    protected function expandValueField(array $data): array
    {
        $data['value_text'] = null;
        $data['value_number'] = null;
        $data['value_select'] = null;
        $data['value_boolean'] = false;

        $attrId = $data['category_attribute_id'] ?? null;
        $attr = $attrId ? CategoryAttribute::find($attrId) : null;
        $value = $data['value'] ?? null;

        if ($attr) {
            match ($attr->type) {
                AttributeType::TEXT => $data['value_text'] = $value,
                AttributeType::NUMBER => $data['value_number'] = $value,
                AttributeType::SELECT => $data['value_select'] = $value,
                AttributeType::BOOLEAN => $data['value_boolean'] = $value === '1',
            };
        }

        return $data;
    }

    /**
     * Before saving: collapse the typed field back into `value`.
     */
    protected function normalizeValueField(array $data): array
    {
        $attrId = $data['category_attribute_id'] ?? null;
        $attr = $attrId ? CategoryAttribute::find($attrId) : null;

        if ($attr) {
            $data['value'] = match ($attr->type) {
                AttributeType::TEXT => $data['value_text'] ?? null,
                AttributeType::NUMBER => $data['value_number'] ?? null,
                AttributeType::SELECT => $data['value_select'] ?? null,
                AttributeType::BOOLEAN => ($data['value_boolean'] ?? false) ? '1' : '0',
            };
        } else {
            $data['value'] = $data['value_text']
                ?? $data['value_number']
                ?? $data['value_select']
                ?? (($data['value_boolean'] ?? false) ? '1' : '0');
        }

        unset($data['value_text'], $data['value_number'], $data['value_select'], $data['value_boolean']);

        return $data;
    }

    private function resolveAttr(Get $get): ?CategoryAttribute
    {
        $attrId = $get('category_attribute_id');
        return $attrId ? CategoryAttribute::find($attrId) : null;
    }

    private function resolveType(Get $get): ?AttributeType
    {
        return $this->resolveAttr($get)?->type;
    }

    private function resolveUnit(Get $get): ?string
    {
        return $this->resolveAttr($get)?->unit;
    }

    protected function afterAttributeSaved(): void
    {
        $product = $this->getOwnerRecord()->fresh();

        ProductNameGenerator::updateProductName($product);

        $this->dispatch('product-name-updated');

        if (! $product->category_id) {
            return;
        }

        $attributeMap = DuplicateProductDetector::getAttributeMap($product);

        if (empty($attributeMap)) {
            return;
        }

        $duplicates = DuplicateProductDetector::findSimilar(
            $product->category_id,
            $attributeMap,
            $product->id,
        );

        if ($duplicates->isNotEmpty()) {
            $names = $duplicates->pluck('name')->implode(', ');

            Notification::make()
                ->title('Possible Duplicate Detected')
                ->body("Products with matching attributes already exist: {$names}. Consider adding a supplier to the existing product instead.")
                ->warning()
                ->persistent()
                ->send();
        }
    }
}
