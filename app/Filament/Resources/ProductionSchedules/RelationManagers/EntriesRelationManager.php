<?php

namespace App\Filament\Resources\ProductionSchedules\RelationManagers;

use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    protected static ?string $title = 'Production Entries';

    public function form(Schema $schema): Schema
    {
        $schedule = $this->getOwnerRecord();
        $piId = $schedule->proforma_invoice_id;
        $poId = $schedule->purchase_order_id;

        $scheduledByPiItem = $schedule->entries()->reorder()
            ->selectRaw('proforma_invoice_item_id, SUM(quantity) as total')
            ->groupBy('proforma_invoice_item_id')
            ->pluck('total', 'proforma_invoice_item_id');

        $scheduledByPoItem = $schedule->entries()->reorder()
            ->selectRaw('purchase_order_item_id, SUM(quantity) as total')
            ->groupBy('purchase_order_item_id')
            ->pluck('total', 'purchase_order_item_id');

        return $schema->components([
            Select::make('proforma_invoice_item_id')
                ->label(__('forms.labels.pi_item'))
                ->options(
                    fn () => ProformaInvoiceItem::where('proforma_invoice_id', $piId)
                        ->with('product')
                        ->get()
                        ->mapWithKeys(function ($item) use ($scheduledByPiItem) {
                            $scheduled = $scheduledByPiItem[$item->id] ?? 0;
                            $remaining = $item->quantity - $scheduled;

                            return [
                                $item->id => ($item->product?->name ?? $item->description) . " (Remaining: {$remaining} / {$item->quantity})",
                            ];
                        })
                )
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn (\Filament\Schemas\Components\Utilities\Set $set) => $set('purchase_order_item_id', null)),
            Select::make('purchase_order_item_id')
                ->label(__('forms.labels.po_item'))
                ->options(function (\Filament\Schemas\Components\Utilities\Get $get) use ($poId, $scheduledByPoItem) {
                    if (! $poId) {
                        return [];
                    }

                    $piItemId = $get('proforma_invoice_item_id');
                    $query = PurchaseOrderItem::where('purchase_order_id', $poId)->with('product');

                    if ($piItemId) {
                        $productId = ProformaInvoiceItem::find($piItemId)?->product_id;
                        if ($productId) {
                            $query->where('product_id', $productId);
                        }
                    }

                    return $query->get()
                        ->mapWithKeys(function ($item) use ($scheduledByPoItem) {
                            $scheduled = $scheduledByPoItem[$item->id] ?? 0;
                            $remaining = $item->quantity - $scheduled;

                            return [
                                $item->id => ($item->product?->name ?? $item->description) . " (Remaining: {$remaining} / {$item->quantity})",
                            ];
                        });
                })
                ->searchable()
                ->nullable()
                ->visible(fn () => $poId !== null),
            DatePicker::make('production_date')
                ->label(__('forms.labels.production_date'))
                ->required(),
            TextInput::make('quantity')
                ->label(__('forms.labels.quantity'))
                ->numeric()
                ->required()
                ->minValue(1),
            TextInput::make('actual_quantity')
                ->label(__('forms.labels.actual_quantity'))
                ->numeric()
                ->minValue(0)
                ->nullable(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('proformaInvoiceItem.product.name')
                    ->label(__('forms.labels.product'))
                    ->default(fn ($record) => $record->proformaInvoiceItem?->description ?? '—')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('production_date')
                    ->label(__('forms.labels.production_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.quantity'))
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('actual_quantity')
                    ->label(__('forms.labels.actual_quantity'))
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—')
                    ->badge()
                    ->color(fn ($state, $record) => match (true) {
                        $state === null => 'gray',
                        $state >= $record->quantity => 'success',
                        $state > 0 => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('delta')
                    ->label('Delta')
                    ->getStateUsing(fn ($record) => $record->actual_quantity !== null
                        ? $record->actual_quantity - $record->quantity
                        : null)
                    ->numeric()
                    ->alignEnd()
                    ->placeholder('—')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state >= 0 => 'success',
                        default => 'danger',
                    }),
            ])
            ->defaultSort('production_date', 'asc')
            ->groups([
                'proformaInvoiceItem.product.name',
                'production_date',
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No production entries')
            ->emptyStateDescription('Add entries manually or import from a supplier spreadsheet.')
            ->emptyStateIcon('heroicon-o-calendar-days');
    }
}
