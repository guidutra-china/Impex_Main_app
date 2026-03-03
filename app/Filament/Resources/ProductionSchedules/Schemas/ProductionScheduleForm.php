<?php

namespace App\Filament\Resources\ProductionSchedules\Schemas;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProductionScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make(__('forms.sections.production_schedule_information'))
                ->schema([
                    Select::make('proforma_invoice_id')
                        ->label(__('forms.labels.proforma_invoice'))
                        ->options(
                            fn () => ProformaInvoice::with('company')
                                ->orderByDesc('created_at')
                                ->get()
                                ->mapWithKeys(fn ($pi) => [
                                    $pi->id => "{$pi->reference} — {$pi->company?->name}",
                                ])
                        )
                        ->searchable()
                        ->required()
                        ->disabled(fn (?\Illuminate\Database\Eloquent\Model $record) => $record !== null)
                        ->dehydrated(),
                    TextInput::make('version')
                        ->label(__('forms.labels.version'))
                        ->numeric()
                        ->default(1)
                        ->required(),
                    DatePicker::make('received_at')
                        ->label(__('forms.labels.received_date'))
                        ->default(now()),
                ])
                ->columns(3)
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
