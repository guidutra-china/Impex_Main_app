<?php

namespace App\Filament\Resources\Finance\Trips\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class TripForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.trip_details'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('title')
                    ->label(__('forms.labels.trip_title'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                // The trip belongs to a client/supplier OR is an internal
                // expense. The toggle and the company select are mutually
                // exclusive (enforced here and in the model/request).
                Toggle::make('is_internal')
                    ->label(__('forms.labels.internal_expense'))
                    ->helperText(__('forms.helpers.internal_expense'))
                    ->live()
                    ->afterStateUpdated(function (bool $state, Set $set) {
                        if ($state) {
                            $set('company_id', null);
                        }
                    })
                    ->columnSpanFull(),

                Select::make('company_id')
                    ->label(__('forms.labels.company'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get) => ! $get('is_internal'))
                    ->required(fn (Get $get) => ! $get('is_internal'))
                    ->columnSpanFull(),

                TextInput::make('destination_city')
                    ->label(__('forms.labels.destination_city'))
                    ->maxLength(255),

                TextInput::make('destination_country')
                    ->label(__('forms.labels.destination_country'))
                    ->maxLength(2)
                    ->placeholder('BR, CN, US...'),

                DatePicker::make('start_date')
                    ->label(__('forms.labels.start_date'))
                    ->required()
                    ->default(now()),

                DatePicker::make('end_date')
                    ->label(__('forms.labels.end_date'))
                    ->afterOrEqual('start_date'),
            ]),

            Section::make(__('forms.sections.additional_info'))->columnSpanFull()->schema([
                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rows(3)
                    ->maxLength(2000),
            ]),
        ]);
    }
}
