<?php

namespace App\Filament\Resources\Settings\ContainerTypes\Schemas;

use Filament\Forms\Components\Section;
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
                Section::make('Container Information')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder("e.g., 20' Standard, 40' High Cube"),
                        TextInput::make('code')
                            ->label('Code')
                            ->required()
                            ->maxLength(20)
                            ->unique(ignoreRecord: true)
                            ->placeholder('e.g., 20ST, 40HC'),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make('Dimensions & Capacity')
                    ->description('Physical dimensions and weight capacity of the container.')
                    ->schema([
                        TextInput::make('length_ft')
                            ->label('Length (ft)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('ft'),
                        TextInput::make('width_ft')
                            ->label('Width (ft)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('ft'),
                        TextInput::make('height_ft')
                            ->label('Height (ft)')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('ft'),
                        TextInput::make('max_weight_kg')
                            ->label('Max Weight')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('kg'),
                        TextInput::make('cubic_capacity_cbm')
                            ->label('Cubic Capacity')
                            ->numeric()
                            ->step(0.01)
                            ->suffix('CBM'),
                    ])
                    ->columns(3),
            ]);
    }
}
