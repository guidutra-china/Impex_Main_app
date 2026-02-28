<?php

namespace App\Filament\Resources\Shipments\Schemas;

use App\Domain\Settings\Models\ContainerType;
use App\Domain\Settings\Models\Currency;
use App\Domain\Logistics\Enums\ImportModality;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Enums\TransportMode;
use Filament\Forms\Components\DatePicker;
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

            Section::make(__('forms.sections.shipment_information'))
                ->schema([
                    Select::make('company_id')
                        ->label(__('forms.labels.client'))
                        ->relationship('company', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->options(ShipmentStatus::class)
                        ->default(ShipmentStatus::DRAFT->value)
                        ->required()
                        ->disabled(fn (?\Illuminate\Database\Eloquent\Model $record) => $record !== null)
                        ->dehydrated(),
                    Select::make('currency_code')
                        ->label(__('forms.labels.currency'))
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
                    Select::make('import_modality')
                        ->label(__('forms.labels.import_modality'))
                        ->options(ImportModality::class)
                        ->default(ImportModality::DIRECT->value)
                        ->helperText(__('forms.helpers.import_modality_affects_ci_and_packing_list')),
                    DatePicker::make('issue_date')
                        ->label(__('forms.labels.document_issue_date'))
                        ->helperText(__('forms.helpers.date_printed_on_ci_and_packing_list')),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('forms.sections.route_transport'))
                ->schema([
                    TextInput::make('origin_port')
                        ->label(__('forms.labels.port_of_loading'))
                        ->maxLength(255),
                    TextInput::make('destination_port')
                        ->label(__('forms.labels.port_of_destination'))
                        ->maxLength(255),
                    TextInput::make('vessel_name')
                        ->maxLength(255),
                    TextInput::make('bl_number')
                        ->label(__('forms.labels.bl_number'))
                        ->maxLength(255),
                    TextInput::make('container_number')
                        ->maxLength(255),
                    TextInput::make('voyage_number')
                        ->maxLength(255),
                ])
                ->columns(3)
                ->collapsible()
                ->columnSpanFull(),

            Section::make(__('forms.sections.carrier_booking'))
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
                ->collapsed()
                ->columnSpanFull(),

            Section::make(__('forms.sections.dates'))
                ->schema([
                    DatePicker::make('etd')
                        ->label(__('forms.labels.etd_estimated_departure')),
                    DatePicker::make('eta')
                        ->label(__('forms.labels.eta_estimated_arrival')),
                    DatePicker::make('actual_departure')
                        ->label(__('forms.labels.actual_departure')),
                    DatePicker::make('actual_arrival')
                        ->label(__('forms.labels.actual_arrival')),
                ])
                ->columns(4)
                ->collapsible()
                ->columnSpanFull(),

            Section::make(__('forms.sections.weight_volume'))
                ->description(__('forms.descriptions.autocalculated_from_packing_list_manual_override_available'))
                ->schema([
                    TextInput::make('total_gross_weight')
                        ->label(__('forms.labels.gross_weight'))
                        ->numeric()
                        ->suffix('kg'),
                    TextInput::make('total_net_weight')
                        ->label(__('forms.labels.net_weight'))
                        ->numeric()
                        ->suffix('kg'),
                    TextInput::make('total_volume')
                        ->label(__('forms.labels.volume'))
                        ->numeric()
                        ->suffix('CBM'),
                    TextInput::make('total_packages')
                        ->label(__('forms.labels.packages'))
                        ->numeric()
                        ->integer(),
                ])
                ->columns(4)
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),

            Section::make(__('forms.sections.notes'))
                ->schema([
                    Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                    Textarea::make('internal_notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }
}
