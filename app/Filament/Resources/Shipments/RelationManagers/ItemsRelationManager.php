<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Actions\ResolvePurchaseOrderItemForShipmentAction;
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
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    /** Cache por request: o cache de pivots vive enquanto a tabela é montada. */
    private ?ProductIdentityResolver $productIdentity = null;

    protected static ?string $title = 'Shipment Items';

    protected static BackedEnum|string|null $icon = 'heroicon-o-cube';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('proforma_invoice_id')
                ->label(__('forms.labels.proforma_invoice'))
                ->options(function () {
                    $companyId = $this->getOwnerRecord()->company_id;

                    return ProformaInvoice::where('company_id', $companyId)
                        ->whereNotIn('status', ['draft', 'cancelled'])
                        ->orderByDesc('id')
                        ->get()
                        ->mapWithKeys(fn ($pi) => [
                            $pi->id => $pi->reference.($pi->client_reference ? ' — '.$pi->client_reference : ''),
                        ]);
                })
                ->searchable()
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
                            $shipped = ShipmentItem::where('proforma_invoice_item_id', $item->id)
                                ->whereHas('shipment', fn ($q) => $q->countsAsShipped())
                                ->sum('quantity');
                            $remaining = $item->quantity - $shipped;
                            $label = ($item->product?->model_number ?? '').' — '.$item->product_name
                                .' | Qty: '.$item->quantity
                                .' | Remaining: '.$remaining;

                            return [$item->id => $label];
                        });
                })
                ->searchable()
                ->required()
                // Não permite incluir no embarque um item sem PO vinculada (o
                // vínculo com a PO é o que sustenta o acompanhamento do embarque
                // no lado do fornecedor).
                ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                    if ($value && ! PurchaseOrderItem::where('proforma_invoice_item_id', $value)->exists()) {
                        $fail('Este item não tem PO vinculada — gere/vincule a PO antes de incluí-lo no embarque.');
                    }
                })
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                    if (! $state) {
                        return;
                    }

                    $piItem = ProformaInvoiceItem::with('product.packaging', 'product.specification')->find($state);
                    if (! $piItem) {
                        return;
                    }

                    $shipped = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)
                        ->whereHas('shipment', fn ($q) => $q->countsAsShipped())
                        ->sum('quantity');
                    $remaining = $piItem->quantity - $shipped;
                    $set('max_quantity', $remaining);

                    // Prefill otimista com quantidade 1 — a quantidade real
                    // ainda não foi digitada. O vínculo definitivo é resolvido
                    // de novo em mutateFormDataUsing, já com a quantidade.
                    $poItem = app(ResolvePurchaseOrderItemForShipmentAction::class)->execute($piItem->id);
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
                ->helperText(fn (Get $get) => $get('max_quantity') ? 'Max available: '.$get('max_quantity') : null)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    static::recalculateTotals($get, $set);
                }),

            TextInput::make('unit')
                ->placeholder(__('forms.placeholders.pcs_sets_etc'))
                ->maxLength(20),

            TextInput::make('unit_weight')
                ->label(__('forms.labels.unit_weight_kg'))
                ->numeric()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set) {
                    static::recalculateTotals($get, $set);
                })
                ->helperText(__('forms.helpers.autofilled_from_product_packaging_data')),

            TextInput::make('total_weight')
                ->label(__('forms.labels.total_weight_kg'))
                ->numeric()
                ->helperText(__('forms.helpers.autocalculated_unit_weight_quantity')),

            TextInput::make('total_volume')
                ->label(__('forms.labels.total_volume_cbm'))
                ->numeric()
                ->helperText(__('forms.helpers.autocalculated_from_product_carton_cbm')),

            Textarea::make('notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Itens da PI oferecidos no "Import from PI".
     *
     * Com "somente quantidades restantes" marcado, itens já totalmente
     * embarcados saem da lista: selecioná-los não importaria nada (a ação os
     * descarta), e deixá-los visíveis fazia a opção parecer quebrada.
     * Desmarcado, tudo aparece — é o caso de reembarcar a quantidade cheia.
     *
     * @return array<int, string>
     */
    protected static function piItemPickerOptions(mixed $piId, bool $onlyRemaining): array
    {
        if (! $piId) {
            return [];
        }

        $currency = ProformaInvoice::find($piId)?->currency_code ?? '';

        return ProformaInvoiceItem::where('proforma_invoice_id', $piId)
            ->whereHas('purchaseOrderItem') // só itens com PO vinculada
            ->with('product')
            ->get()
            ->mapWithKeys(function ($item) use ($currency, $onlyRemaining) {
                $shipped = ShipmentItem::where('proforma_invoice_item_id', $item->id)
                    ->whereHas('shipment', fn ($q) => $q->countsAsShipped())
                    ->sum('quantity');

                $remaining = $item->quantity - $shipped;

                if ($onlyRemaining && $remaining <= 0) {
                    return [];
                }

                return [$item->id => static::piItemPickerLabel($item, $remaining, $currency)];
            })
            ->all();
    }

    /**
     * Rótulo de uma linha da PI no seletor do "Import from PI".
     *
     * O preço unitário entra porque o mesmo produto pode aparecer duas vezes na
     * PI — com desconto ou modificação — e sem o valor as duas opções ficam
     * visualmente idênticas na lista.
     */
    protected static function piItemPickerLabel(ProformaInvoiceItem $item, int $remaining, string $currency): string
    {
        return implode(' | ', [
            ($item->product?->model_number ?? '').' — '.$item->product_name,
            'Qty: '.$item->quantity,
            'Remaining: '.$remaining,
            trim($currency.' '.Money::format($item->unit_price, 4)),
        ]);
    }

    private function productIdentity(): ProductIdentityResolver
    {
        return $this->productIdentity ??= ProductIdentityResolver::forClient(
            $this->getOwnerRecord()->company_id,
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $companyId = $this->getOwnerRecord()->company_id;

                return $query->with([
                    'proformaInvoiceItem.proformaInvoice',
                    // Só o pivot do cliente do embarque — é dele que sai o MODEL NO.
                    'proformaInvoiceItem.product.companies' => fn ($q) => $q
                        ->where('companies.id', $companyId)
                        ->where('company_product.role', 'client'),
                ]);
            })
            ->columns([
                TextColumn::make('proformaInvoiceItem.proformaInvoice.reference')
                    ->label(__('forms.labels.pi_ref'))
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->description(fn ($record) => $record->proformaInvoiceItem?->proformaInvoice?->client_reference ?: null)
                    ->toggleable(),
                TextColumn::make('model_no')
                    ->label(__('forms.labels.model_number'))
                    // Mesma regra do CI/PL: código do cliente (pivot) > modelo > SKU.
                    ->state(fn ($record) => $this->productIdentity()
                        ->resolve($record->proformaInvoiceItem?->product)
                        ->code ?: null)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('product_name')
                    ->label(__('forms.labels.product'))
                    ->searchable(query: function ($query, string $search) {
                        $query->whereHas('proformaInvoiceItem.product', function ($q) use ($search) {
                            $q->where('name', 'like', "%{$search}%");
                        });
                    })
                    ->limit(40)
                    ->toggleable(),
                TextInputColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter()
                    ->rules(['required', 'integer', 'min:1'])
                    ->afterStateUpdated(function ($record) {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    })
                    ->summarize(Sum::make()->label(__('forms.labels.total')))
                    ->toggleable(),
                TextColumn::make('unit')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('unit_price')
                    ->label(__('forms.labels.unit_price'))
                    ->formatStateUsing(fn ($state) => Money::format($state, 4))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('line_total')
                    ->label(__('forms.labels.total'))
                    ->formatStateUsing(fn ($state) => Money::format($state))
                    ->alignEnd()
                    ->weight('bold')
                    // line_total é accessor (qty × unit_price do item da PI), não
                    // coluna — a soma precisa do join com proforma_invoice_items.
                    ->summarize(
                        Summarizer::make()
                            ->label(__('forms.labels.total'))
                            ->using(fn ($query): int => (int) $query
                                ->join('proforma_invoice_items as pii_total', 'pii_total.id', '=', 'shipment_items.proforma_invoice_item_id')
                                ->sum(DB::raw('pii_total.unit_price * shipment_items.quantity')))
                            ->formatStateUsing(fn ($state) => Money::format((int) $state))
                    )
                    ->toggleable(),
                TextColumn::make('unit_weight')
                    ->label(__('forms.labels.unit_wt_kg'))
                    ->placeholder('—')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_weight')
                    ->label(__('forms.labels.weight_kg'))
                    ->placeholder('—')
                    ->alignEnd()
                    ->summarize(Sum::make()->label(__('forms.labels.total'))->suffix(' kg'))
                    ->toggleable(),
                TextColumn::make('total_volume')
                    ->label(__('forms.labels.vol_cbm'))
                    ->placeholder('—')
                    ->alignEnd()
                    ->summarize(Sum::make()->label(__('forms.labels.total'))->suffix(' CBM'))
                    ->toggleable(),
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
                    })
                    ->mutateFormDataUsing(function (array $data, $record): array {
                        unset($data['proforma_invoice_id'], $data['max_quantity']);

                        return $this->resolvePoLink($data, $record->getKey());
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
                    ->label(__('forms.labels.import_from_pi'))
                    ->icon('heroicon-o-document-arrow-down')
                    ->color('warning')
                    ->form(function () {
                        $companyId = $this->getOwnerRecord()->company_id;
                        $piOptions = ProformaInvoice::where('company_id', $companyId)
                            ->whereNotIn('status', ['draft', 'cancelled'])
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn ($pi) => [
                                $pi->id => $pi->reference.($pi->client_reference ? ' — '.$pi->client_reference : ''),
                            ]);

                        return [
                            Select::make('proforma_invoice_id')
                                ->label(__('forms.labels.proforma_invoice'))
                                ->options($piOptions)
                                ->searchable()
                                ->required()
                                ->live(),

                            // Antes da lista: é ela que decide quais itens
                            // aparecem, então o usuário escolhe o modo primeiro.
                            Checkbox::make('only_remaining')
                                ->label(__('forms.labels.only_import_remaining_quantities_exclude_already_shipped'))
                                ->default(true)
                                ->live(),

                            \Filament\Forms\Components\CheckboxList::make('pi_item_ids')
                                ->label(__('forms.labels.select_items_to_import'))
                                ->options(fn (Get $get) => static::piItemPickerOptions(
                                    $get('proforma_invoice_id'),
                                    // null antes do default ser aplicado: o
                                    // padrão do checkbox é marcado.
                                    $get('only_remaining') ?? true,
                                ))
                                ->helperText('Apenas itens com PO vinculada aparecem. Itens sem PO precisam ter a PO gerada antes de embarcar.')
                                ->visible(fn (Get $get) => filled($get('proforma_invoice_id')))
                                ->bulkToggleable()
                                ->required()
                                ->columns(1),
                        ];
                    })
                    ->action(function (array $data) {
                        $onlyRemaining = $data['only_remaining'] ?? true;
                        $shipment = $this->getOwnerRecord();
                        $itemIds = $data['pi_item_ids'] ?? [];

                        if (empty($itemIds)) {
                            return;
                        }

                        $piItems = ProformaInvoiceItem::whereIn('id', $itemIds)
                            ->with('product.packaging', 'product.specification')
                            ->get();

                        $created = 0;
                        $skipped = 0;
                        $maxSort = $shipment->items()->max('sort_order') ?? 0;

                        foreach ($piItems as $piItem) {
                            $alreadyShipped = ShipmentItem::where('proforma_invoice_item_id', $piItem->id)
                                ->whereHas('shipment', fn ($q) => $q->countsAsShipped())
                                ->sum('quantity');
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

                            $poItem = app(ResolvePurchaseOrderItemForShipmentAction::class)
                                ->execute($piItem->id, (int) $qty);

                            // Segurança: não embarcar item sem PO vinculada.
                            if (! $poItem) {
                                $skipped++;

                                continue;
                            }

                            $maxSort++;
                            ShipmentItem::create([
                                'shipment_id' => $shipment->id,
                                'proforma_invoice_item_id' => $piItem->id,
                                'purchase_order_item_id' => $poItem->id,
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
                    ->label(__('forms.labels.add_item'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
                    ->createAnother()
                    ->mutateFormDataUsing(function (array $data): array {
                        unset($data['proforma_invoice_id'], $data['max_quantity']);

                        return $this->resolvePoLink($data);
                    })
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    })
                    ->successNotificationTitle('Item added to shipment'),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-shipments'))
                    ->after(function () {
                        app(RecalculateShipmentTotalsAction::class)->execute($this->getOwnerRecord());
                    }),
            ])
            ->emptyStateHeading('No items')
            ->emptyStateDescription('Add items from Proforma Invoices to this shipment.')
            ->emptyStateIcon('heroicon-o-cube')
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }

    /**
     * Re-resolve o vínculo com a PO já sabendo a quantidade real da linha.
     *
     * O prefill do formulário roda quando o item da PI é escolhido, antes de a
     * quantidade ser digitada; com a linha da PI dividida entre duas POs, isso
     * é justamente o que decide qual das duas deve receber o embarque.
     */
    protected function resolvePoLink(array $data, ?int $ignoreShipmentItemId = null): array
    {
        $piItemId = $data['proforma_invoice_item_id'] ?? null;

        if (! $piItemId) {
            return $data;
        }

        $poItem = app(ResolvePurchaseOrderItemForShipmentAction::class)
            ->execute((int) $piItemId, (int) ($data['quantity'] ?? 1), $ignoreShipmentItemId);

        if ($poItem) {
            $data['purchase_order_item_id'] = $poItem->id;
        }

        return $data;
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
