<?php

namespace App\Filament\Resources\Shipments\Schemas;

use App\Domain\Settings\Models\ContainerType;
use App\Domain\Settings\Models\Currency;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Enums\TransportMode;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ShipmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Grid::make(3)->schema([
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
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(fn () => Currency::pluck('code', 'code'))
                            ->default('USD')
                            ->searchable()
                            ->required(),
                        Select::make('transport_mode')
                            ->options(TransportMode::class),
                        Select::make('container_type')
                            ->options(
                                ContainerType::active()
                                    ->pluck('name', 'code')
                                    ->toArray()
                            )
                            ->searchable(),
                    ])
                    ->columns(2)
                    ->columnSpan(2),

                Section::make('Document')
                    ->schema([
                        DatePicker::make('issue_date')
                            ->label('Issue Date')
                            ->helperText('Date printed on CI / PL'),
                    ])
                    ->columnSpan(1),
            ]),

            Section::make('Route & Transport')
                ->schema([
                    Grid::make(3)->schema([
                        TextInput::make('origin_port')
                            ->label('Port of Loading')
                            ->maxLength(255),
                        TextInput::make('destination_port')
                            ->label('Port of Destination')
                            ->maxLength(255),
                        TextInput::make('vessel_name')
                            ->maxLength(255),
                    ]),
                    Grid::make(3)->schema([
                        TextInput::make('bl_number')
                            ->label('B/L Number')
                            ->maxLength(255),
                        TextInput::make('container_number')
                            ->maxLength(255),
                        TextInput::make('voyage_number')
                            ->maxLength(255),
                    ]),
                ])
                ->collapsible(),

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
                ->collapsible()
                ->collapsed(),

            Section::make('Dates')
                ->schema([
                    Fieldset::make('Estimated')
                        ->schema([
                            DatePicker::make('etd')
                                ->label('ETD (Departure)'),
                            DatePicker::make('eta')
                                ->label('ETA (Arrival)'),
                        ])
                        ->columns(2),
                    Fieldset::make('Actual')
                        ->schema([
                            DatePicker::make('actual_departure')
                                ->label('Departure'),
                            DatePicker::make('actual_arrival')
                                ->label('Arrival'),
                        ])
                        ->columns(2),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Weight & Volume')
                ->description('Auto-calculated from Packing List. Manual override available.')
                ->schema([
                    TextInput::make('total_gross_weight')
                        ->label('Gross Weight')
                        ->numeric()
                        ->suffix('kg'),
                    TextInput::make('total_net_weight')
                        ->label('Net Weight')
                        ->numeric()
                        ->suffix('kg'),
                    TextInput::make('total_volume')
                        ->label('Volume')
                        ->numeric()
                        ->suffix('CBM'),
                    TextInput::make('total_packages')
                        ->label('Packages')
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
