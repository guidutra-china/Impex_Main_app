<?php

namespace App\Filament\Resources\ShipmentPlans\RelationManagers;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Planning\Models\ShipmentPlanItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Planned Items';
    protected static BackedEnum|string|null $icon = 'heroicon-o-cube';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('proforma_invoice_id')
                ->label(__('forms.labels.proforma_invoice'))
                ->options(function () {
                    $companyId = $this->getOwnerRecord()->supplier_company_id;
                    return ProformaInvoice::where('company_id', $companyId)
                        ->pluck('reference', 'id');
                })
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set) {
                    $set('proforma_invoice_item_id', null);
                    $set('quantity', null);
                })
                ->dehydrated()
                ->columnSpanFull(),

            Select::make('proforma_invoice_item_id')
                ->label(__('forms.labels.product_item'))
                ->options(function (Get $get) {
                    $piId = $get('proforma_invoice_id');
                    if (! $piId) {
                        return [];
                    }

                    return ProformaInvoiceItem::where('proforma_invoice_id', $piId)
                        ->with('product')
                        ->get()
                        ->mapWithKeys(function ($item) {
                            $shipped = ShipmentItem::where('proforma_invoice_item_id', $item->id)->sum('quantity');
                            $planned = ShipmentPlanItem::where('proforma_invoice_item_id', $item->id)->sum('quantity');
                            $remaining = $item->quantity - $shipped - $planned;
                            $label = $item->product_name
                                . ' — Qty: ' . $item->quantity
                                . ' | Shipped: ' . $shipped
                                . ' | Planned: ' . $planned
                                . ' | Available: ' . max(0, $remaining);
                            return [$item->id => $label];
                        });
                })
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                    if (! $state) {
                        return;
                    }

                    $piItem = ProformaInvoiceItem::find($state);
                    if (! $piItem) {
                        return;
                    }

                    $shipped = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)->sum('quantity');
                    $planned = ShipmentPlanItem::where('proforma_invoice_item_id', $piItem->id)->sum('quantity');
                    $remaining = $piItem->quantity - $shipped - $planned;

                    $set('max_quantity', max(0, $remaining));
                    $set('unit_price', $piItem->unit_price);
                })
                ->columnSpanFull(),

            Hidden::make('max_quantity'),
            Hidden::make('unit_price'),

            TextInput::make('quantity')
                ->required()
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(fn (Get $get) => $get('max_quantity') ?: 999999)
                ->helperText(fn (Get $get) => $get('max_quantity') ? 'Max available: ' . $get('max_quantity') : null)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    $qty = (int) $get('quantity');
                    $unitPrice = (int) $get('unit_price');
                    $set('line_total', $qty * $unitPrice);
                }),

            TextInput::make('line_total')
                ->label(__('forms.labels.line_total'))
                ->numeric()
                ->disabled()
                ->dehydrated(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proformaInvoice.reference')
                    ->label(__('forms.labels.pi_ref'))
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('proformaInvoiceItem.product.name')
                    ->label(__('forms.labels.product'))
                    ->default(fn ($record) => $record->proformaInvoiceItem?->description ?? '—')
                    ->searchable(query: function ($query, string $search) {
                        $query->whereHas('proformaInvoiceItem.product', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                    })
                    ->limit(40),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter()
                    ->weight('bold')
                    ->summarize(Sum::make()->label(__('forms.labels.total'))),
                TextColumn::make('unit_price')
                    ->label(__('forms.labels.unit_price'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('line_total')
                    ->label(__('forms.labels.total'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->weight('bold')
                    ->summarize(Sum::make()->label(__('forms.labels.total'))),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $form->fill(array_merge($record->toArray(), [
                            'proforma_invoice_id' => $record->proforma_invoice_id,
                        ]));
                    }),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('import_from_pi')
                    ->label(__('forms.labels.import_from_pi'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('warning')
                    ->form([
                        Select::make('proforma_invoice_id')
                            ->label(__('forms.labels.proforma_invoice'))
                            ->options(function () {
                                $companyId = $this->getOwnerRecord()->supplier_company_id;
                                return ProformaInvoice::where('company_id', $companyId)
                                    ->pluck('reference', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->required(),

                        Checkbox::make('only_available')
                            ->label(__('forms.labels.only_import_available_quantities'))
                            ->default(true),
                    ])
                    ->action(function (array $data) {
                        $piId = $data['proforma_invoice_id'];
                        $onlyAvailable = $data['only_available'] ?? true;
                        $plan = $this->getOwnerRecord();

                        $piItems = ProformaInvoiceItem::where('proforma_invoice_id', $piId)
                            ->with('product')
                            ->get();

                        $created = 0;
                        $skipped = 0;
                        $maxSort = $plan->items()->max('sort_order') ?? 0;

                        foreach ($piItems as $piItem) {
                            $shipped = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)->sum('quantity');
                            $planned = ShipmentPlanItem::where('proforma_invoice_item_id', $piItem->id)
                                ->where('shipment_plan_id', '!=', $plan->id)
                                ->sum('quantity');

                            $qty = $onlyAvailable
                                ? ($piItem->quantity - $shipped - $planned)
                                : ($piItem->quantity - $shipped);

                            if ($qty <= 0) {
                                $skipped++;
                                continue;
                            }

                            $maxSort++;
                            ShipmentPlanItem::create([
                                'shipment_plan_id' => $plan->id,
                                'proforma_invoice_item_id' => $piItem->id,
                                'proforma_invoice_id' => $piId,
                                'quantity' => $qty,
                                'unit_price' => $piItem->unit_price,
                                'line_total' => $qty * $piItem->unit_price,
                                'sort_order' => $maxSort,
                            ]);

                            $created++;
                        }

                        $message = "{$created} item(s) imported";
                        if ($skipped > 0) {
                            $message .= ", {$skipped} skipped (fully allocated)";
                        }

                        Notification::make()
                            ->success()
                            ->title('Items imported from PI')
                            ->body($message)
                            ->send();
                    }),

                \Filament\Actions\CreateAction::make()
                    ->label(__('forms.labels.add_item'))
                    ->icon('heroicon-o-plus')
                    ->mutateFormDataUsing(function (array $data): array {
                        unset($data['max_quantity']);

                        if (! isset($data['unit_price']) || ! $data['unit_price']) {
                            $piItem = ProformaInvoiceItem::find($data['proforma_invoice_item_id']);
                            $data['unit_price'] = $piItem?->unit_price ?? 0;
                        }

                        $data['line_total'] = ($data['quantity'] ?? 0) * ($data['unit_price'] ?? 0);

                        return $data;
                    }),
            ])
            ->emptyStateHeading('No planned items')
            ->emptyStateDescription('Add items from Proforma Invoices to plan this shipment.')
            ->emptyStateIcon('heroicon-o-cube')
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }
}
