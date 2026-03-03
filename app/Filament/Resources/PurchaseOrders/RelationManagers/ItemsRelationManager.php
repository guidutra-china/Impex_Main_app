<?php

namespace App\Filament\Resources\PurchaseOrders\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Items';

    protected static BackedEnum|string|null $icon = 'heroicon-o-cube';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('product_id')
                ->label(__('forms.labels.product'))
                ->options(
                    fn () => Product::active()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn ($p) => [$p->id => $p->sku . ' — ' . $p->name])
                )
                ->searchable()
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                    if ($state) {
                        $product = Product::with('specification')->find($state);
                        if ($product) {
                            $set('description', $product->name);
                            $set('specifications', $product->specification?->description);
                        }
                    }
                })
                ->columnSpanFull(),

            TextInput::make('description')
                ->label(__('forms.labels.description'))
                ->maxLength(255),

            Textarea::make('specifications')
                ->label(__('forms.labels.specifications'))
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('quantity')
                ->label(__('forms.labels.quantity'))
                ->numeric()
                ->required()
                ->minValue(1)
                ->default(1),

            TextInput::make('unit')
                ->label(__('forms.labels.unit'))
                ->default('pcs')
                ->maxLength(20),

            TextInput::make('unit_cost')
                ->label(__('forms.labels.unit_cost_supplier'))
                ->numeric()
                ->required()
                ->prefix('$')
                ->step(0.0001)
                ->minValue(0),

            Select::make('incoterm')
                ->label(__('forms.labels.incoterm'))
                ->options(Incoterm::class)
                ->searchable(),

            Textarea::make('notes')
                ->label(__('forms.labels.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('forms.labels.hash'))
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('product.name')
                    ->label(__('forms.labels.product'))
                    ->searchable()
                    ->limit(30)
                    ->placeholder(__('forms.placeholders.manual_item')),
                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->alignCenter(),
                TextColumn::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->formatStateUsing(fn ($state) => Money::format($state, 4))
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('line_total')
                    ->label(__('forms.labels.total'))
                    ->getStateUsing(fn ($record) => $record->line_total)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('source')
                    ->label(__('forms.labels.source'))
                    ->getStateUsing(function ($record) {
                        if ($record->proforma_invoice_item_id) {
                            return 'PI';
                        }
                        if ($record->supplier_quotation_item_id) {
                            $sq = $record->supplierQuotationItem?->supplierQuotation;
                            return $sq ? $sq->reference : 'SQ';
                        }
                        return '—';
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'PI' => 'info',
                        '—' => 'gray',
                        default => 'warning',
                    })
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('proformaInvoiceItem.id')
                    ->label(__('forms.labels.pi_item'))
                    ->formatStateUsing(fn ($state) => $state ? "#{$state}" : '—')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-purchase-orders'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = Money::toMinor($data['unit_cost'] ?? 0);
                        $data['sort_order'] = $this->getOwnerRecord()->items()->max('sort_order') + 1;

                        return $data;
                    }),
                $this->importFromPIAction(),
                $this->importFromSupplierQuotationAction(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-purchase-orders'))
                    ->mountUsing(function ($form, $record) {
                        $data = $record->toArray();
                        $data['unit_cost'] = Money::toMajor($data['unit_cost']);
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = Money::toMinor($data['unit_cost'] ?? 0);

                        return $data;
                    }),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-purchase-orders')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-purchase-orders')),
                ]),
            ])
            ->reorderable('sort_order');
    }

    protected function importFromPIAction(): Action
    {
        return Action::make('importFromPI')
            ->label(__('forms.labels.import_from_pi'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('info')
            ->visible(function () {
                if (! auth()->user()?->can('edit-purchase-orders')) {
                    return false;
                }
                $po = $this->getOwnerRecord();

                return $po->proforma_invoice_id !== null;
            })
            ->form(function () {
                $po = $this->getOwnerRecord();

                $piItems = ProformaInvoiceItem::query()
                    ->where('proforma_invoice_id', $po->proforma_invoice_id)
                    ->where('supplier_company_id', $po->supplier_company_id)
                    ->with('product')
                    ->orderBy('sort_order')
                    ->get();

                $existingPiItemIds = $po->items()
                    ->whereNotNull('proforma_invoice_item_id')
                    ->pluck('proforma_invoice_item_id')
                    ->toArray();

                $available = $piItems->filter(fn ($item) => ! in_array($item->id, $existingPiItemIds));

                if ($available->isEmpty()) {
                    return [
                        Placeholder::make('no_items')
                            ->label('')
                            ->content('All PI items for this supplier have already been imported.'),
                    ];
                }

                $options = $available->mapWithKeys(fn ($item) => [
                    $item->id => ($item->product?->name ?? $item->description ?? 'Item #' . $item->id)
                        . ' — Qty: ' . $item->quantity
                        . ' — $' . Money::format($item->unit_cost, 4),
                ])->toArray();

                return [
                    CheckboxList::make('item_ids')
                        ->label(__('forms.labels.select_items_to_import'))
                        ->options($options)
                        ->required()
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText('Import items from the linked Proforma Invoice that belong to this supplier. Already imported items are excluded.'),
                ];
            })
            ->action(function (array $data) {
                $po = $this->getOwnerRecord();
                $itemIds = $data['item_ids'] ?? [];

                if (empty($itemIds)) {
                    return;
                }

                $items = ProformaInvoiceItem::whereIn('id', $itemIds)
                    ->with(['product', 'product.specification'])
                    ->get();

                $maxSort = $po->items()->max('sort_order') ?? 0;
                $imported = 0;

                foreach ($items as $piItem) {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $po->id,
                        'product_id' => $piItem->product_id,
                        'proforma_invoice_item_id' => $piItem->id,
                        'supplier_quotation_item_id' => $this->findSqItemId($piItem),
                        'description' => $piItem->description,
                        'specifications' => $piItem->specifications,
                        'quantity' => $piItem->quantity,
                        'unit' => $piItem->unit,
                        'unit_cost' => $piItem->unit_cost,
                        'incoterm' => $piItem->incoterm,
                        'notes' => $piItem->notes,
                        'sort_order' => ++$maxSort,
                    ]);
                    $imported++;
                }

                Notification::make()
                    ->title($imported . ' ' . __('messages.items_imported'))
                    ->body('Items imported from Proforma Invoice.')
                    ->success()
                    ->send();
            });
    }

    protected function importFromSupplierQuotationAction(): Action
    {
        return Action::make('importFromSupplierQuotation')
            ->label(__('forms.labels.import_from_sq'))
            ->icon('heroicon-o-clipboard-document-list')
            ->color('warning')
            ->visible(fn () => auth()->user()?->can('edit-purchase-orders'))
            ->form(function () {
                $po = $this->getOwnerRecord();

                $supplierQuotations = SupplierQuotation::query()
                    ->where('company_id', $po->supplier_company_id)
                    ->whereIn('status', ['received', 'under_analysis', 'selected'])
                    ->orderByDesc('id')
                    ->get();

                if ($supplierQuotations->isEmpty()) {
                    return [
                        Placeholder::make('no_sqs')
                            ->label('')
                            ->content('No supplier quotations found for this supplier.'),
                    ];
                }

                return [
                    Select::make('supplier_quotation_id')
                        ->label(__('forms.labels.supplier_quotation'))
                        ->options(
                            $supplierQuotations->mapWithKeys(fn ($sq) => [
                                $sq->id => $sq->reference
                                    . ' (' . $sq->status->getLabel() . ')'
                                    . ($sq->inquiry ? ' — ' . $sq->inquiry->reference : ''),
                            ])
                        )
                        ->required()
                        ->searchable()
                        ->live()
                        ->helperText('Select a supplier quotation to import items from.'),

                    CheckboxList::make('item_ids')
                        ->label(__('forms.labels.select_items_to_import'))
                        ->options(function (Get $get) {
                            $sqId = $get('supplier_quotation_id');
                            if (! $sqId) {
                                return [];
                            }

                            return SupplierQuotationItem::query()
                                ->where('supplier_quotation_id', $sqId)
                                ->with('product')
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn ($item) => [
                                    $item->id => ($item->product?->name ?? $item->description ?? 'Item #' . $item->id)
                                        . ' — Qty: ' . $item->quantity
                                        . ' — $' . Money::format($item->unit_cost, 4),
                                ])
                                ->toArray();
                        })
                        ->required()
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText('Select which items to import into this Purchase Order.'),
                ];
            })
            ->action(function (array $data) {
                $po = $this->getOwnerRecord();
                $itemIds = $data['item_ids'] ?? [];

                if (empty($itemIds)) {
                    return;
                }

                $items = SupplierQuotationItem::whereIn('id', $itemIds)
                    ->with(['product', 'product.specification', 'supplierQuotation'])
                    ->get();

                $maxSort = $po->items()->max('sort_order') ?? 0;
                $imported = 0;

                foreach ($items as $sqItem) {
                    PurchaseOrderItem::create([
                        'purchase_order_id' => $po->id,
                        'product_id' => $sqItem->product_id,
                        'proforma_invoice_item_id' => null,
                        'supplier_quotation_item_id' => $sqItem->id,
                        'description' => $sqItem->description ?? $sqItem->product?->name,
                        'specifications' => $sqItem->specifications ?? $sqItem->product?->specification?->description,
                        'quantity' => $sqItem->quantity,
                        'unit' => $sqItem->unit ?? 'pcs',
                        'unit_cost' => $sqItem->unit_cost,
                        'incoterm' => null,
                        'notes' => $sqItem->notes,
                        'sort_order' => ++$maxSort,
                    ]);
                    $imported++;
                }

                Notification::make()
                    ->title($imported . ' ' . __('messages.items_imported'))
                    ->body('Items imported from ' . ($items->first()?->supplierQuotation?->reference ?? 'Supplier Quotation') . '.')
                    ->success()
                    ->send();
            });
    }

    protected function findSqItemId(ProformaInvoiceItem $piItem): ?int
    {
        if (! $piItem->quotation_item_id) {
            return null;
        }

        $quotationItem = $piItem->quotationItem;
        if (! $quotationItem) {
            return null;
        }

        return $quotationItem->supplier_quotation_item_id;
    }
}
