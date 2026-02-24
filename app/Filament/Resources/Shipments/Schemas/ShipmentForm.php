<?php

namespace App\Filament\Resources\Shipments\Schemas;

use App\Domain\Logistics\Enums\ContainerType;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Enums\TransportMode;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ShipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Shipment Information')
                ->schema([
                    Select::make('company_id')
                        ->label('Client')
                        ->relationship('company', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->options(ShipmentStatus::class)
                        ->default(ShipmentStatus::DRAFT)
                        ->required(),
                    TextInput::make('currency_code')
                        ->label('Currency')
                        ->maxLength(3)
                        ->placeholder('USD'),
                    Select::make('transport_mode')
                        ->options(TransportMode::class),
                    Select::make('container_type')
                        ->options(ContainerType::class),
                ])
                ->columns(3),

            Section::make('Carrier & Booking')
                ->schema([
                    TextInput::make('carrier')
                        ->maxLength(255),
                    TextInput::make('freight_forwarder')
                        ->maxLength(255),
                    TextInput::make('booking_number')
                        ->maxLength(255),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Transport Details')
                ->schema([
                    TextInput::make('bl_number')
                        ->label('B/L Number')
                        ->maxLength(255),
                    TextInput::make('container_number')
                        ->maxLength(255),
                    TextInput::make('vessel_name')
                        ->maxLength(255),
                    TextInput::make('voyage_number')
                        ->maxLength(255),
                    TextInput::make('origin_port')
                        ->maxLength(255),
                    TextInput::make('destination_port')
                        ->maxLength(255),
                ])
                ->columns(3)
                ->collapsible(),

            Section::make('Dates')
                ->schema([
                    DatePicker::make('etd')
                        ->label('ETD (Estimated Departure)'),
                    DatePicker::make('eta')
                        ->label('ETA (Estimated Arrival)'),
                    DatePicker::make('actual_departure')
                        ->label('Actual Departure'),
                    DatePicker::make('actual_arrival')
                        ->label('Actual Arrival'),
                ])
                ->columns(4)
                ->collapsible(),

            Section::make('Weight & Volume')
                ->schema([
                    TextInput::make('total_gross_weight')
                        ->numeric()
                        ->suffix('kg'),
                    TextInput::make('total_net_weight')
                        ->numeric()
                        ->suffix('kg'),
                    TextInput::make('total_volume')
                        ->numeric()
                        ->suffix('CBM'),
                    TextInput::make('total_packages')
                        ->numeric()
                        ->integer(),
                ])
                ->columns(4)
                ->collapsible()
                ->collapsed(),

            Section::make('Notes')
                ->schema([
                    Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('internal_notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed(),
        ]);
    }
}
