<?php

namespace App\Filament\Resources\Catalog\Categories\RelationManagers;

use App\Domain\Catalog\Enums\AttributeType;
use App\Domain\Catalog\Models\CategoryAttribute;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InheritedAttributesRelationManager extends RelationManager
{
    protected static string $relationship = 'parent';

    protected static ?string $title = 'Inherited Attributes';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $category = $this->getOwnerRecord();
        $inheritedAttributes = $this->getInheritedAttributes($category);
        $ids = $inheritedAttributes->pluck('id')->toArray();

        return $table
            ->query(
                CategoryAttribute::query()
                    ->whereIn('id', $ids ?: [0])
                    ->orderBy('sort_order')
            )
            ->columns([
                TextColumn::make('category.name')
                    ->label(__('forms.labels.from_category'))
                    ->badge()
                    ->color('info'),
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
                IconColumn::make('is_required')
                    ->label(__('forms.labels.required'))
                    ->boolean()
                    ->sortable(),
            ])
            ->paginated(false)
            ->emptyStateHeading('No inherited attributes')
            ->emptyStateDescription('This category has no parent, or the parent categories have no attributes defined.')
            ->emptyStateIcon('heroicon-o-arrow-up-circle');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    private function getInheritedAttributes($category): \Illuminate\Support\Collection
    {
        $attributes = collect();
        $current = $category->parent;

        while ($current) {
            foreach ($current->categoryAttributes()->orderBy('sort_order')->get() as $attr) {
                $attributes->push($attr);
            }
            $current = $current->parent;
        }

        return $attributes;
    }
}
