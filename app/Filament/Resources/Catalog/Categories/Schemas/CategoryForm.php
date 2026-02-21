<?php

namespace App\Filament\Resources\Catalog\Categories\Schemas;

use App\Domain\Catalog\Models\Category;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Set;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category Details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->helperText('Auto-generated from name. Used in URLs.'),
                        TextInput::make('sku_prefix')
                            ->label('SKU Prefix')
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->placeholder('ELE')
                            ->helperText('Used as prefix for product SKUs in this category (e.g., ELE → ELE-0001).'),
                        Select::make('parent_id')
                            ->label('Parent Category')
                            ->options(function (?Category $record) {
                                return Category::query()
                                    ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn (Category $cat) => [$cat->id => $cat->full_path]);
                            })
                            ->searchable()
                            ->placeholder('None (root category)'),
                        TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
