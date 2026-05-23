<?php

namespace App\Filament\Resources\TradeFairs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TradeFairForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados da feira')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('Canton Fair Phase 1, Yiwu Expo...'),
                        TextInput::make('location')
                            ->label('Local')
                            ->maxLength(255)
                            ->placeholder('Guangzhou, Yiwu, Shenzhen...'),
                        DatePicker::make('start_date')
                            ->label('Data de início')
                            ->native(false)
                            ->displayFormat('d/m/Y'),
                        DatePicker::make('end_date')
                            ->label('Data de término')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('start_date'),
                        Textarea::make('notes')
                            ->label('Notas')
                            ->rows(3)
                            ->maxLength(5000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }
}
