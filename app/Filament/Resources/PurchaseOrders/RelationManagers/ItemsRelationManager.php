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
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

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
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Product::active()
                    ->where(function ($query) use ($search) {
                        $query->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('model_number', 'like', "%{$search}%");
                    })
                    ->orderBy('name')
                    ->limit(50)
                    ->get()
                    ->mapWithKeys(fn ($p) => [$p->id => self::productOptionLabel($p)])
                )
                ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? self::productOptionLabel($p) : null)
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
            // A relação items() traz orderBy('sort_order') embutido, que vence
            // qualquer sort de coluna. reorder() limpa; defaultSort restaura o
            // comportamento padrão sem bloquear a ordenação do usuário.
            ->modifyQueryUsing(fn ($query) => $query->reorder())
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label(__('forms.labels.hash'))
                    ->sortable()
                    ->alignCenter(),
                \Filament\Tables\Columns\ImageColumn::make('product.avatar')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?background=e2e8f0&color=94a3b8&name=P&size=40'),
                TextColumn::make('product.name')
                    ->label(__('forms.labels.product'))
                    ->searchable()
                    ->sortable()
                    ->limit(30)
                    ->placeholder(__('forms.placeholders.manual_item'))
                    ->toggleable(),
                TextColumn::make('product.model_number')
                    ->label(__('forms.labels.model_number'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter()
                    ->sortable()
                    ->toggleable()
                    ->summarize(Sum::make()->label(__('forms.labels.total'))),
                TextColumn::make('quantity_shipped')
                    ->label(__('forms.labels.shipped'))
                    ->getStateUsing(fn ($record) => $record->quantity_shipped)
                    ->alignCenter()
                    ->color(fn ($record) => $record->quantity_shipped > 0 ? 'success' : 'gray')
                    ->toggleable(),
                TextColumn::make('quantity_remaining')
                    ->label(__('forms.labels.remaining'))
                    ->getStateUsing(fn ($record) => $record->quantity_remaining)
                    ->alignCenter()
                    ->color(fn ($record) => $record->quantity_remaining <= 0 ? 'success' : 'warning')
                    ->toggleable(),
                TextColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->formatStateUsing(fn ($state) => Money::format($state, 4))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('line_total')
                    ->label(__('forms.labels.total'))
                    ->getStateUsing(fn ($record) => $record->line_total)
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->weight('bold')
                    ->toggleable()
                    ->summarize(
                        Summarizer::make()
                            ->label(__('forms.labels.total'))
                            ->using(fn ($query): int => (int) $query->sum(DB::raw('unit_cost * quantity')))
                            ->formatStateUsing(fn ($state) => '$ '.Money::format((int) $state))
                    ),
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
                    ->visible(fn () => auth()->user()?->can('edit-purchase-orders'))
                    ->disabled(fn ($record) => $record->shipmentItems()->exists())
                    ->tooltip(fn ($record) => $record->shipmentItems()->exists()
                        ? 'Linha já embarcada — não pode ser excluída (desfaça o embarque primeiro).'
                        : null),
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

                // Saldo por PI item contra TODAS as POs ativas desta PI — pode
                // haver mais de uma PO do mesmo fornecedor (pedido dividido),
                // e o que importa é a quantidade ainda não coberta por PO.
                $ordered = $this->orderedQuantitiesByPiItem($po->proforma_invoice_id);

                $available = $piItems->filter(
                    fn ($item) => $item->quantity - (int) ($ordered[$item->id] ?? 0) > 0
                );

                if ($available->isEmpty()) {
                    return [
                        Placeholder::make('no_items')
                            ->label('')
                            ->content('All PI items for this supplier are fully covered by POs of this PI.'),
                    ];
                }

                $options = $available->mapWithKeys(function ($item) use ($ordered) {
                    $remaining = $item->quantity - (int) ($ordered[$item->id] ?? 0);

                    return [
                        $item->id => ($item->product?->name ?? $item->description ?? 'Item #'.$item->id)
                            .' — Qty: '.$item->quantity.' | Remaining: '.$remaining
                            .' — $'.Money::format($item->unit_cost, 4),
                    ];
                })->toArray();

                return [
                    CheckboxList::make('item_ids')
                        ->label(__('forms.labels.select_items_to_import'))
                        ->options($options)
                        ->required()
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText('Import items from the linked Proforma Invoice that belong to this supplier. Quantities already on POs of this PI are deducted.'),
                    \Filament\Forms\Components\Checkbox::make('only_remaining')
                        ->label(__('forms.labels.only_import_remaining_quantities_exclude_already_imported'))
                        ->default(true),
                ];
            })
            ->action(function (array $data) {
                $po = $this->getOwnerRecord();
                $itemIds = $data['item_ids'] ?? [];
                $onlyRemaining = $data['only_remaining'] ?? true;

                if (empty($itemIds)) {
                    return;
                }

                // Recalcula o saldo no momento do import: o modal pode estar
                // aberto enquanto outra PO da mesma PI absorve quantidades.
                $ordered = $this->orderedQuantitiesByPiItem($po->proforma_invoice_id);

                $items = ProformaInvoiceItem::whereIn('id', $itemIds)
                    ->with(['product', 'product.specification'])
                    ->get();

                $maxSort = $po->items()->max('sort_order') ?? 0;
                $imported = 0;
                $skipped = 0;

                foreach ($items as $piItem) {
                    $remaining = $piItem->quantity - (int) ($ordered[$piItem->id] ?? 0);
                    $quantity = $onlyRemaining ? $remaining : $piItem->quantity;

                    if ($quantity <= 0) {
                        $skipped++;

                        continue;
                    }

                    PurchaseOrderItem::create([
                        'purchase_order_id' => $po->id,
                        'product_id' => $piItem->product_id,
                        'proforma_invoice_item_id' => $piItem->id,
                        'supplier_quotation_item_id' => $this->findSqItemId($piItem),
                        'description' => $piItem->description,
                        'specifications' => $piItem->specifications,
                        'quantity' => $quantity,
                        'unit' => $piItem->unit,
                        'unit_cost' => $piItem->unit_cost,
                        'incoterm' => $piItem->incoterm,
                        'notes' => $piItem->notes,
                        'sort_order' => ++$maxSort,
                    ]);
                    $imported++;
                }

                Notification::make()
                    ->title($imported.' '.__('messages.items_imported'))
                    ->body(
                        'Items imported from Proforma Invoice.'
                        .($skipped > 0
                            ? ' '.$skipped.' skipped (no remaining quantity on this PI).'
                            : '')
                    )
                    ->success()
                    ->send();
            });
    }

    /**
     * Quantidades já cobertas por POs ativas da PI, somadas por PI item.
     *
     * @return \Illuminate\Support\Collection<int, int|string>
     */
    protected function orderedQuantitiesByPiItem(int $proformaInvoiceId): \Illuminate\Support\Collection
    {
        return PurchaseOrderItem::query()
            ->whereNotNull('proforma_invoice_item_id')
            ->whereHas('purchaseOrder', fn ($q) => $q->where('proforma_invoice_id', $proformaInvoiceId))
            ->selectRaw('proforma_invoice_item_id, SUM(quantity) AS total')
            ->groupBy('proforma_invoice_item_id')
            ->pluck('total', 'proforma_invoice_item_id');
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
                                    .' ('.$sq->status->getLabel().')'
                                    .($sq->inquiry ? ' — '.$sq->inquiry->reference : ''),
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
                                    $item->id => ($item->product?->name ?? $item->description ?? 'Item #'.$item->id)
                                        .' — Qty: '.$item->quantity
                                        .' — $'.Money::format($item->unit_cost, 4),
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
                    ->title($imported.' '.__('messages.items_imported'))
                    ->body('Items imported from '.($items->first()?->supplierQuotation?->reference ?? 'Supplier Quotation').'.')
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

    /**
     * Label exibido na busca de produto: SKU — Nome (Model Number).
     */
    protected static function productOptionLabel(Product $product): string
    {
        $label = $product->sku.' — '.$product->name;

        if ($product->model_number) {
            $label .= ' ('.$product->model_number.')';
        }

        return $label;
    }
}
