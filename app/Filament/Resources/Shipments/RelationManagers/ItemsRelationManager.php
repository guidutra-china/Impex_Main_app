<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use BackedEnum;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';
    protected static ?string $title = 'Shipment Items';

    protected static BackedEnum|string|null $icon = 'heroicon-o-cube';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('proforma_invoice_id')
                ->label('Proforma Invoice')
                ->options(function () {
                    $companyId = $this->getOwnerRecord()->company_id;
                    return ProformaInvoice::where('company_id', $companyId)
                        ->pluck('reference', 'id');
                })
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set) {
                    $set('proforma_invoice_item_id', null);
                    $set('purchase_order_item_id', null);
                    $set('quantity', null);
                    $set('unit_weight', null);
                    $set('total_weight', null);
                    $set('total_volume', null);
                })
                ->dehydrated(false)
                ->columnSpanFull(),

            Select::make('proforma_invoice_item_id')
                ->label('Product / Item')
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
                            $remaining = $item->quantity - $shipped;
                            $label = $item->product_name
                                . ' — Qty: ' . $item->quantity
                                . ' | Shipped: ' . $shipped
                                . ' | Remaining: ' . $remaining;
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

                    $piItem = ProformaInvoiceItem::with('product.packaging', 'product.specification')->find($state);
                    if (! $piItem) {
                        return;
                    }

                    $shipped = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)->sum('quantity');
                    $remaining = $piItem->quantity - $shipped;
                    $set('max_quantity', $remaining);

                    $poItem = PurchaseOrderItem::where('proforma_invoice_item_id', $piItem->id)->first();
                    if ($poItem) {
                        $set('purchase_order_item_id', $poItem->id);
                    }

                    $set('unit', $piItem->unit);

                    $packaging = $piItem->product?->packaging;
                    if ($packaging && $packaging->pcs_per_carton > 0 && $packaging->carton_weight > 0) {
                        $unitWeight = round((float) $packaging->carton_weight / $packaging->pcs_per_carton, 3);
                        $set('unit_weight', $unitWeight);
                    }
                })
                ->columnSpanFull(),

            Hidden::make('purchase_order_item_id'),
            Hidden::make('max_quantity'),

            TextInput::make('quantity')
                ->required()
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(fn (Get $get) => $get('max_quantity') ?: 999999)
                ->helperText(fn (Get $get) => $get('max_quantity') ? 'Max available: ' . $get('max_quantity') : null)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    static::recalculateTotals($get, $set);
                }),

            TextInput::make('unit')
                ->placeholder('pcs, sets, etc.')
                ->maxLength(20),

            TextInput::make('unit_weight')
                ->label('Unit Weight (kg)')
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    static::recalculateTotals($get, $set);
                })
                ->helperText('Auto-filled from product packaging data'),

            TextInput::make('total_weight')
                ->label('Total Weight (kg)')
                ->numeric()
                ->helperText('Auto-calculated: unit weight × quantity'),

            TextInput::make('total_volume')
                ->label('Total Volume (CBM)')
                ->numeric()
                ->helperText('Auto-calculated from product carton CBM'),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proformaInvoiceItem.proformaInvoice.reference')
                    ->label('PI Ref')
                    ->sortable()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('product_name')
                    ->label('Product')
                    ->searchable(query: function ($query, string $search) {
                        $query->whereHas('proformaInvoiceItem.product', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                    })
                    ->limit(40),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->alignCenter()
                    ->weight('bold')
                    ->summarize(Sum::make()->label('Total')),
                TextColumn::make('unit')
                    ->placeholder('—'),
                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd(),
                TextColumn::make('line_total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('unit_weight')
                    ->label('Unit Wt (kg)')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_weight')
                    ->label('Weight (kg)')
                    ->placeholder('—')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->suffix(' kg')),
                TextColumn::make('total_volume')
                    ->label('Vol. (CBM)')
                    ->placeholder('—')
                    ->alignEnd()
                    ->summarize(Sum::make()->label('Total')->suffix(' CBM')),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
                    ->mountUsing(function ($form, $record) {
                        $piItem = $record->proformaInvoiceItem;
                        $piId = $piItem?->proforma_invoice_id;

                        $form->fill(array_merge($record->toArray(), [
                            'proforma_invoice_id' => $piId,
                        ]));
                    }),
                \Filament\Actions\DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    }),
            ])
            ->headerActions([
                \Filament\Actions\Action::make('import_from_pi')
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
                    ->label('Import from PI')
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('warning')
                    ->form([
                        Select::make('proforma_invoice_id')
                            ->label('Proforma Invoice')
                            ->options(function () {
                                $companyId = $this->getOwnerRecord()->company_id;
                                return ProformaInvoice::where('company_id', $companyId)
                                    ->pluck('reference', 'id');
                            })
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live(),

                        Checkbox::make('only_remaining')
                            ->label('Only import remaining quantities (exclude already shipped)')
                            ->default(true),
                    ])
                    ->action(function (array $data) {
                        $piId = $data['proforma_invoice_id'];
                        $onlyRemaining = $data['only_remaining'] ?? true;
                        $shipment = $this->getOwnerRecord();

                        $piItems = ProformaInvoiceItem::where('proforma_invoice_id', $piId)
                            ->with('product.packaging', 'product.specification')
                            ->get();

                        $created = 0;
                        $skipped = 0;
                        $maxSort = $shipment->items()->max('sort_order') ?? 0;

                        foreach ($piItems as $piItem) {
                            $alreadyShipped = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)->sum('quantity');
                            $qty = $onlyRemaining ? ($piItem->quantity - $alreadyShipped) : $piItem->quantity;

                            if ($qty <= 0) {
                                $skipped++;
                                continue;
                            }

                            $packaging = $piItem->product?->packaging;
                            $unitWeight = null;
                            $totalWeight = null;
                            $totalVolume = null;

                            if ($packaging && $packaging->pcs_per_carton > 0 && $packaging->carton_weight > 0) {
                                $unitWeight = round((float) $packaging->carton_weight / $packaging->pcs_per_carton, 3);
                                $totalWeight = round($unitWeight * $qty, 3);
                            }

                            if ($packaging && $packaging->pcs_per_carton > 0 && $packaging->carton_cbm > 0) {
                                $numCartons = ceil($qty / $packaging->pcs_per_carton);
                                $totalVolume = round($numCartons * (float) $packaging->carton_cbm, 4);
                            }

                            $poItem = PurchaseOrderItem::where('proforma_invoice_item_id', $piItem->id)->first();

                            $maxSort++;
                            ShipmentItem::create([
                                'shipment_id' => $shipment->id,
                                'proforma_invoice_item_id' => $piItem->id,
                                'purchase_order_item_id' => $poItem?->id,
                                'quantity' => $qty,
                                'unit' => $piItem->unit,
                                'unit_weight' => $unitWeight,
                                'total_weight' => $totalWeight,
                                'total_volume' => $totalVolume,
                                'sort_order' => $maxSort,
                            ]);

                            $created++;
                        }

                        app(RecalculateShipmentTotalsAction::class)->execute($shipment);

                        $message = "{$created} item(s) imported";
                        if ($skipped > 0) {
                            $message .= ", {$skipped} skipped (fully shipped)";
                        }

                        Notification::make()
                            ->success()
                            ->title('Items imported from PI')
                            ->body($message)
                            ->send();
                    }),

                \Filament\Actions\CreateAction::make()
                    ->label('Add Item')
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
                    ->mutateFormDataUsing(function (array $data): array {
                        unset($data['proforma_invoice_id'], $data['max_quantity']);
                        return $data;
                    })
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());

                        Notification::make()
                            ->success()
                            ->title('Item added to shipment')
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No items')
            ->emptyStateDescription('Add items from Proforma Invoices to this shipment.')
            ->emptyStateIcon('heroicon-o-cube')
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }

    protected static function recalculateTotals(Get $get, Set $set): void
    {
        $unitWeight = (float) $get('unit_weight');
        $qty = (int) $get('quantity');

        if ($unitWeight > 0 && $qty > 0) {
            $set('total_weight', round($unitWeight * $qty, 3));
        }

        $piItemId = $get('proforma_invoice_item_id');
        if ($piItemId && $qty > 0) {
            $piItem = ProformaInvoiceItem::with('product.packaging')->find($piItemId);
            $packaging = $piItem?->product?->packaging;

            if ($packaging && $packaging->pcs_per_carton > 0 && $packaging->carton_cbm > 0) {
                $numCartons = ceil($qty / $packaging->pcs_per_carton);
                $totalVolume = round($numCartons * (float) $packaging->carton_cbm, 4);
                $set('total_volume', $totalVolume);
            }
        }
    }
}
