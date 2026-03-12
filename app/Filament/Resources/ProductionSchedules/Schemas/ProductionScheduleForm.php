<?php

namespace App\Filament\Resources\ProductionSchedules\Schemas;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
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
                        ->live()
                        ->disabled(fn (?\Illuminate\Database\Eloquent\Model $record) => $record !== null)
                        ->dehydrated(),
                    Select::make('purchase_order_id')
                        ->label(__('forms.labels.purchase_order'))
                        ->options(function (\Filament\Schemas\Components\Utilities\Get $get) {
                            $piId = $get('proforma_invoice_id');
                            if (! $piId) {
                                return [];
                            }

                            return PurchaseOrder::where('proforma_invoice_id', $piId)
                                ->with('supplierCompany')
                                ->get()
                                ->mapWithKeys(fn ($po) => [
                                    $po->id => "{$po->reference} — {$po->supplierCompany?->name}",
                                ]);
                        })
                        ->searchable()
                        ->nullable()
                        ->live()
                        ->afterStateUpdated(function (\Filament\Schemas\Components\Utilities\Set $set, $state) {
                            if ($state) {
                                $po = PurchaseOrder::find($state);
                                if ($po) {
                                    $set('supplier_company_id', $po->supplier_company_id);
                                }
                            }
                        })
                        ->disabled(fn (?\Illuminate\Database\Eloquent\Model $record) => $record !== null)
                        ->dehydrated(),
                    TextInput::make('version')
                        ->label(__('forms.labels.version'))
                        ->numeric()
                        ->default(1)
                        ->required(),
                    DatePicker::make('received_date')
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
