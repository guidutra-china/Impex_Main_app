<?php

namespace App\Filament\Resources\ShipmentPlans\Schemas;

use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Settings\Models\ContainerType;
use App\Domain\Settings\Models\Currency;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShipmentPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make(__('forms.sections.shipment_plan_information'))
                ->schema([
                    Select::make('supplier_company_id')
                        ->label(__('forms.labels.supplier'))
                        ->relationship('supplierCompany', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->options(ShipmentPlanStatus::class)
                        ->default(ShipmentPlanStatus::DRAFT->value)
                        ->required()
                        ->disabled(fn (?\Illuminate\Database\Eloquent\Model $record) => $record !== null)
                        ->dehydrated(),
                    Select::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->options(fn () => Currency::pluck('code', 'code'))
                        ->default('USD')
                        ->searchable()
                        ->required(),
                    Select::make('container_type')
                        ->label(__('forms.labels.container_type'))
                        ->options(
                            ContainerType::active()
                                ->pluck('name', 'code')
                                ->toArray()
                        )
                        ->searchable(),
                ])
                ->columns(4)
                ->columnSpanFull(),

            Section::make(__('forms.sections.planned_dates'))
                ->schema([
                    DatePicker::make('planned_shipment_date')
                        ->label(__('forms.labels.planned_shipment_date'))
                        ->required(),
                    DatePicker::make('planned_eta')
                        ->label(__('forms.labels.planned_eta'))
                        ->required(),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('forms.sections.capacity'))
                ->description(__('forms.descriptions.container_capacity_limits'))
                ->schema([
                    TextInput::make('max_cbm')
                        ->label(__('forms.labels.max_cbm'))
                        ->numeric()
                        ->suffix('CBM'),
                    TextInput::make('max_weight')
                        ->label(__('forms.labels.max_weight'))
                        ->numeric()
                        ->suffix('kg'),
                ])
                ->columns(2)
                ->collapsible()
                ->columnSpanFull(),

            Section::make(__('forms.sections.notes'))
                ->schema([
                    Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }
}
