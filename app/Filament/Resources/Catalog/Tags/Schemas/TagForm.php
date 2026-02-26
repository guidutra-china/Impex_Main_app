<?php

namespace App\Filament\Resources\Catalog\Tags\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.tag_details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('forms.labels.name'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state))),
                        TextInput::make('slug')
                            ->label(__('forms.labels.slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        ColorPicker::make('color')
                            ->label(__('forms.labels.color'))
                            ->helperText(__('forms.helpers.used_for_visual_identification_in_badges')),
                    ])
                    ->columns(3),
            ]);
    }
}
