<?php

namespace App\Filament\Resources\Settings\PaymentTerms\Schemas;

use App\Domain\Settings\Enums\CalculationBase;
use Filament\Forms\Components\Repeater;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PaymentTermForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.payment_term_information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('forms.labels.name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.eg_3070_30_deposit_70_before_shipment')),
                        Textarea::make('description')
                            ->label(__('forms.labels.description'))
                            ->rows(3)
                            ->maxLength(65535)
                            ->columnSpanFull(),
                        Toggle::make('is_default')
                            ->label(__('forms.labels.default_payment_term'))
                            ->helperText(__('forms.helpers.setting_this_will_unset_the_current_default')),
                        Toggle::make('is_active')
                            ->label(__('forms.labels.active'))
                            ->default(true),
                    ])
                    ->columns(2),
                Section::make(__('forms.sections.payment_stages'))
                    ->description(__('forms.descriptions.define_the_payment_stages_the_total_percentage_across_all'))
                    ->schema([
                        Repeater::make('stages')
                            ->relationship()
                            ->schema([
                                TextInput::make('percentage')
                                    ->label(__('forms.labels.percentage'))
                                    ->required()
                                    ->numeric()
                                    ->suffix('%')
                                    ->minValue(1)
                                    ->maxValue(100),
                                TextInput::make('days')
                                    ->label(__('forms.labels.days'))
                                    ->required()
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0)
                                    ->helperText(__('forms.helpers.days_after_the_calculation_base_date')),
                                Select::make('calculation_base')
                                    ->label(__('forms.labels.calculation_base'))
                                    ->options(CalculationBase::class)
                                    ->required()
                                    ->default(CalculationBase::ORDER_DATE->value),
                            ])
                            ->columns(3)
                            ->orderColumn('sort_order')
                            ->reorderable()
                            ->addActionLabel('Add Stage')
                            ->defaultItems(1)
                            ->minItems(1)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
