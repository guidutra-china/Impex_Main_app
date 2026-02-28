<?php

namespace App\Filament\Resources\Catalog\Categories\Schemas;

use Filament\Infolists\Components\IconEntry;
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
                        ->label(__('forms.labels.full_path')),
                    IconEntry::make('is_active')
                        ->label(__('forms.labels.active'))
                        ->boolean(),
                    TextEntry::make('sort_order')
                        ->label(__('forms.labels.sort_order')),
                    TextEntry::make('products_count')
                        ->label(__('forms.labels.products'))
                        ->state(fn ($record) => $record->products()->count())
                        ->badge()
                        ->color('success'),
                    TextEntry::make('companies_count')
                        ->label(__('forms.labels.companies'))
                        ->state(fn ($record) => $record->companies()->count())
                        ->badge()
                        ->color('info'),
                    TextEntry::make('description')
                        ->label(__('forms.labels.description'))
                        ->placeholder(__('messages.no_notes'))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
