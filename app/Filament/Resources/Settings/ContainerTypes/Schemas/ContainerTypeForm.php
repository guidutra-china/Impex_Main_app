<?php

namespace App\Filament\Resources\Settings\ContainerTypes\Schemas;

use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ContainerTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.container_information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('forms.labels.name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder("e.g., 20' Standard, 40' High Cube"),
                        TextInput::make('code')
                            ->label(__('forms.labels.code'))
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->placeholder(__('forms.placeholders.eg_20st_40hc')),
                        Textarea::make('description')
                            ->label(__('forms.labels.description'))
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label(__('forms.labels.active'))
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make(__('forms.sections.dimensions_capacity'))
                    ->description(__('forms.descriptions.physical_dimensions_and_weight_capacity_of_the_container'))
                    ->schema([
                        TextInput::make('length_ft')
                            ->label(__('forms.labels.length_ft'))
                            ->numeric()
                            ->step(0.01)
                            ->suffix('ft'),
                        TextInput::make('width_ft')
                            ->label(__('forms.labels.width_ft'))
                            ->numeric()
                            ->step(0.01)
                            ->suffix('ft'),
                        TextInput::make('height_ft')
                            ->label(__('forms.labels.height_ft'))
                            ->numeric()
                            ->step(0.01)
                            ->suffix('ft'),
                        TextInput::make('max_weight_kg')
                            ->label(__('forms.labels.max_weight'))
                            ->numeric()
                            ->step(0.01)
                            ->suffix('kg'),
                        TextInput::make('cubic_capacity_cbm')
                            ->label(__('forms.labels.cubic_capacity'))
                            ->numeric()
                            ->step(0.01)
                            ->suffix('CBM'),
                    ])
                    ->columns(3),
            ]);
    }
}
