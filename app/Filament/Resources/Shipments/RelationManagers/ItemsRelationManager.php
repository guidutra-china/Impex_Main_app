<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use BackedEnum;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
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

                    $piItem = ProformaInvoiceItem::find($state);
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
                ->helperText(fn (Get $get) => $get('max_quantity') ? 'Max available: ' . $get('max_quantity') : null),

            TextInput::make('unit')
                ->placeholder('pcs, sets, etc.')
                ->maxLength(20),

            TextInput::make('unit_weight')
                ->label('Unit Weight (kg)')
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    $unitWeight = (float) $get('unit_weight');
                    $qty = (int) $get('quantity');
                    if ($unitWeight && $qty) {
                        $set('total_weight', round($unitWeight * $qty, 3));
                    }
                }),

            TextInput::make('total_weight')
                ->label('Total Weight (kg)')
                ->numeric(),

            TextInput::make('total_volume')
                ->label('Total Volume (CBM)')
                ->numeric(),

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
                    ->weight('bold'),
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
                TextColumn::make('total_weight')
                    ->label('Weight (kg)')
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('total_volume')
                    ->label('Vol. (CBM)')
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->headerActions([
                \Filament\Actions\CreateAction::make()
                    ->label('Add Item')
                    ->icon('heroicon-o-plus')
                    ->mutateFormDataUsing(function (array $data): array {
                        unset($data['proforma_invoice_id'], $data['max_quantity']);
                        return $data;
                    })
                    ->after(function () {
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
}
