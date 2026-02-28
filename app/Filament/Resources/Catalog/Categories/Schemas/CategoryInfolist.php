<?php

namespace App\Filament\Resources\Catalog\Categories\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.category_details'))
                ->columns(3)
                ->schema([
                    TextEntry::make('name')
                        ->label(__('forms.labels.name'))
                        ->weight('bold'),
                    TextEntry::make('slug')
                        ->label(__('forms.labels.slug'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('sku_prefix')
                        ->label(__('forms.labels.sku_prefix'))
                        ->badge()
                        ->color('primary')
                        ->placeholder('—'),
                    TextEntry::make('parent.name')
                        ->label(__('forms.labels.parent_category'))
                        ->placeholder(__('forms.placeholders.root')),
                    TextEntry::make('full_path')
                        ->label(__('forms.labels.full_path'))
                        ->placeholder('—'),
                    IconEntry::make('is_active')
                        ->label(__('forms.labels.active'))
                        ->boolean(),
                    TextEntry::make('sort_order')
                        ->label(__('forms.labels.sort_order')),
                    TextEntry::make('description')
                        ->label(__('forms.labels.description'))
                        ->placeholder(__('messages.no_notes'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('forms.labels.companies') . ' (' . __('forms.labels.suppliers') . ')')
                ->description(__('forms.descriptions.companies_associated_with_this_category'))
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('companies')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('name')
                                ->label(__('forms.labels.company'))
                                ->weight('bold'),
                            TextEntry::make('pivot.notes')
                                ->label(__('forms.labels.notes'))
                                ->placeholder('—'),
                        ])
                        ->columns(2)
                        ->placeholder(__('forms.placeholders.no_companies_assigned')),
                ]),

            Section::make(__('forms.labels.products'))
                ->description(__('forms.descriptions.products_in_this_category'))
                ->collapsible()
                ->schema([
                    RepeatableEntry::make('products')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('sku')
                                ->label(__('forms.labels.sku'))
                                ->badge()
                                ->color('primary'),
                            TextEntry::make('name')
                                ->label(__('forms.labels.product'))
                                ->weight('bold'),
                            TextEntry::make('client_name')
                                ->label(__('forms.labels.client_name'))
                                ->placeholder('—'),
                        ])
                        ->columns(3)
                        ->placeholder(__('forms.placeholders.no_products_in_category')),
                ]),

            Section::make(__('forms.labels.subcategories'))
                ->description(__('forms.descriptions.direct_subcategories'))
                ->collapsible()
                ->collapsed()
                ->schema([
                    RepeatableEntry::make('children')
                        ->hiddenLabel()
                        ->schema([
                            TextEntry::make('name')
                                ->label(__('forms.labels.name'))
                                ->weight('bold'),
                            TextEntry::make('sku_prefix')
                                ->label(__('forms.labels.sku_prefix'))
                                ->badge()
                                ->color('primary')
                                ->placeholder('—'),
                            TextEntry::make('products_count')
                                ->label(__('forms.labels.products'))
                                ->state(fn ($record) => $record->products()->count()),
                            IconEntry::make('is_active')
                                ->label(__('forms.labels.active'))
                                ->boolean(),
                        ])
                        ->columns(4)
                        ->placeholder(__('forms.placeholders.no_subcategories')),
                ]),
        ]);
    }
}
