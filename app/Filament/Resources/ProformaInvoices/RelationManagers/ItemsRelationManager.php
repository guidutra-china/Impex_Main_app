<?php

namespace App\Filament\Resources\ProformaInvoices\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\ProformaInvoices\Actions\CreateQuotationCommissionCostsAction;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Settings\Models\Currency;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
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
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
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
                        $this->fillFromProduct((int) $state, $get, $set);
                    }
                })
                ->columnSpanFull(),

            Select::make('supplier_company_id')
                ->label(__('forms.labels.supplier'))
                ->options(
                    fn () => Company::query()
                        ->whereHas('companyRoles', fn ($q) => $q->where('role', CompanyRole::SUPPLIER))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                )
                ->searchable()
                ->helperText(__('forms.helpers.which_supplier_provides_this_item')),

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

            TextInput::make('unit_price')
                ->label(__('forms.labels.unit_price_client'))
                ->numeric()
                ->required()
                ->prefix('$')
                ->step(0.0001)
                ->minValue(0),

            TextInput::make('unit_cost')
                ->label(__('forms.labels.unit_cost_internal'))
                ->numeric()
                ->prefix('$')
                ->step(0.0001)
                ->minValue(0)
                ->default(0)
                ->live(onBlur: true),

            Select::make('cost_currency_code')
                ->label(__('forms.labels.cost_currency'))
                ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
                ->default(fn () => $this->getOwnerRecord()->currency_code)
                ->required()
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set) {
                    $pi = $this->getOwnerRecord();
                    $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
                    $resolved = $resolver->resolve(
                        $get('cost_currency_code'),
                        $pi->currency_code,
                        $pi->issue_date?->toDateString(),
                    );
                    $set('cost_exchange_rate', $resolved['rate']);
                    $set('cost_exchange_rate_captured_at', $resolved['rate_date'] ?? now()->toDateString());
                }),

            TextInput::make('cost_exchange_rate')
                ->label(__('forms.labels.exchange_rate'))
                ->numeric()
                ->step(0.00000001)
                ->default(1)
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(function (Set $set) {
                    $set('cost_exchange_rate_captured_at', now()->toDateString());
                })
                ->helperText(function (Get $get) {
                    $base = __('forms.helpers.cost_currency_to_pi_currency');
                    $capturedAt = $get('cost_exchange_rate_captured_at');
                    if (! $capturedAt) {
                        return $base;
                    }
                    $formatted = \Carbon\Carbon::parse($capturedAt)->format('d/m/Y');

                    return $base.' '.__('forms.helpers.fx_rate_captured_on', ['date' => $formatted]);
                }),

            Hidden::make('cost_exchange_rate_captured_at'),

            Placeholder::make('unit_cost_doc_preview')
                ->label(__('forms.labels.cost_in_pi_currency'))
                ->content(function (Get $get) {
                    $cost = (float) ($get('unit_cost') ?? 0);
                    $rate = (float) ($get('cost_exchange_rate') ?? 1);
                    $pi = $this->getOwnerRecord();

                    return $pi->currency_code.' '.number_format($cost * $rate, 2);
                }),

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
                    ->alignCenter()
                    ->toggleable(),
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
                    ->limit(20)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('description')
                    ->label(__('forms.labels.description'))
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('supplierCompany.name')
                    ->label(__('forms.labels.supplier'))
                    ->sortable()
                    ->limit(20)
                    ->placeholder('—')
                    ->toggleable(),
                TextInputColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->rules(['required', 'integer', 'min:1'])
                    ->alignCenter()
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
                TextInputColumn::make('unit_price')
                    ->label(__('forms.labels.price'))
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.0001')
                    ->prefix('$')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->getStateUsing(fn ($record) => number_format(Money::toMajor($record->unit_price ?? 0), 4, '.', ''))
                    ->updateStateUsing(function ($record, $state) {
                        $floatValue = (float) str_replace(',', '', (string) $state);
                        $record->unit_price = Money::toMinor($floatValue);
                        $record->save();

                        return number_format($floatValue, 4, '.', '');
                    })
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('cost_currency_code')
                    ->label(__('forms.labels.cost_currency_short'))
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($record) => $record->cost_currency_code
                        && $record->proformaInvoice?->currency_code
                        && $record->cost_currency_code !== $record->proformaInvoice->currency_code
                            ? 'warning'
                            : 'gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextInputColumn::make('unit_cost')
                    ->label(__('forms.labels.cost'))
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.0001')
                    ->prefix(fn ($record) => ($record->cost_currency_code
                        ?? $record->proformaInvoice?->currency_code
                        ?? '—').' ')
                    ->rules(['required', 'numeric', 'min:0'])
                    ->tooltip(function ($record) {
                        $piCurrency = $record->proformaInvoice?->currency_code;
                        if (! $piCurrency || ! $record->cost_currency_code || $record->cost_currency_code === $piCurrency) {
                            return null;
                        }
                        $converted = Money::format($record->unit_cost_in_document_currency ?? 0);
                        $tooltip = '≈ '.$piCurrency.' '.$converted
                            .' @ '.number_format((float) $record->cost_exchange_rate, 6);
                        if ($record->cost_exchange_rate_captured_at) {
                            $tooltip .= ' · '.$record->cost_exchange_rate_captured_at->format('d/m/Y');
                        }

                        return $tooltip;
                    })
                    ->getStateUsing(fn ($record) => number_format(Money::toMajor($record->unit_cost ?? 0), 4, '.', ''))
                    ->updateStateUsing(function ($record, $state) {
                        $floatValue = (float) str_replace(',', '', (string) $state);
                        $record->unit_cost = Money::toMinor($floatValue);
                        $record->save(); // saving hook recomputes unit_cost_in_document_currency

                        return number_format($floatValue, 4, '.', '');
                    })
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
                            ->using(fn ($query): int => (int) $query->sum(DB::raw('unit_price * quantity')))
                            ->formatStateUsing(fn ($state) => '$ '.Money::format((int) $state))
                    ),
                TextColumn::make('margin')
                    ->label(__('forms.labels.margin'))
                    ->getStateUsing(fn ($record) => $record->margin)
                    ->suffix('%')
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-proforma-invoices'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = Money::toMinor($data['unit_cost'] ?? 0);
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);
                        $data['sort_order'] = $this->getOwnerRecord()->items()->max('sort_order') + 1;

                        return $data;
                    }),
                $this->importFromQuotationsAction(),
                $this->importFromSupplierQuotationsAction(),
                $this->importFromInquiryAction(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-proforma-invoices'))
                    ->mountUsing(function ($form, $record) {
                        $data = $record->toArray();
                        $data['unit_cost'] = Money::toMajor($data['unit_cost']);
                        $data['unit_price'] = Money::toMajor($data['unit_price']);
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = Money::toMinor($data['unit_cost'] ?? 0);
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);

                        return $data;
                    }),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-proforma-invoices')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->bulkChangeCurrencyAction(),
                    $this->bulkChangeSupplierAction(),
                    $this->bulkChangeUnitAction(),
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-proforma-invoices')),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order');
    }

    protected function importFromQuotationsAction(): Action
    {
        return Action::make('importFromQuotations')
            ->label(__('forms.labels.import_from_quotations'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('info')
            ->visible(fn () => auth()->user()?->can('edit-proforma-invoices'))
            ->form(function () {
                $pi = $this->getOwnerRecord();

                $quotationItems = QuotationItem::query()
                    ->whereHas('quotation', fn ($q) => $q->where('inquiry_id', $pi->inquiry_id))
                    ->with(['quotation', 'product.clients' => fn ($q) => $q->where('companies.id', $pi->company_id)])
                    ->get();

                if ($quotationItems->isEmpty()) {
                    return [
                        \Filament\Forms\Components\Placeholder::make('no_items')
                            ->label('')
                            ->content('No quotation items found for this inquiry.'),
                    ];
                }

                $options = $quotationItems->mapWithKeys(function ($item) {
                    $product = $item->product;
                    // MODEL NO segue a regra do CI/PL: external_code do cliente > model_number > SKU.
                    $clientPivot = $product?->clients->first()?->pivot;
                    $modelNo = $clientPivot?->external_code ?: ($product?->model_number ?: $product?->sku);

                    return [
                        $item->id => '['.$item->quotation->reference.'] '
                            .($modelNo ? $modelNo.' — ' : '')
                            .($product?->name ?? 'Item #'.$item->id)
                            .' — Qty: '.$item->quantity
                            .' — $'.Money::format($item->unit_price, 4),
                    ];
                })->toArray();

                return [
                    \Filament\Forms\Components\CheckboxList::make('item_ids')
                        ->label(__('forms.labels.select_items_to_import'))
                        ->options($options)
                        ->required()
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText(__('forms.helpers.items_are_grouped_by_quotation_reference_select_which_items')),
                ];
            })
            ->action(function (array $data) {
                $pi = $this->getOwnerRecord();
                $itemIds = $data['item_ids'] ?? [];

                if (empty($itemIds)) {
                    return;
                }

                $items = QuotationItem::whereIn('id', $itemIds)
                    ->with(['quotation', 'product', 'product.suppliers'])
                    ->get();

                $maxSort = $pi->items()->max('sort_order') ?? 0;
                $imported = 0;
                $linkedQuotationIds = [];

                $resolver = app(ProformaInvoiceItemCurrencyResolver::class);

                foreach ($items as $item) {
                    $supplierId = $item->selected_supplier_id;

                    if (! $supplierId && $item->product) {
                        $preferred = $item->product->suppliers()
                            ->orderByDesc('company_product.is_preferred')
                            ->first();
                        $supplierId = $preferred?->id;
                    }

                    $resolved = $resolver->resolve(
                        $item->quotation?->currency_code,
                        $pi->currency_code,
                        $pi->issue_date?->toDateString(),
                    );

                    // Quotation import: use quotation values directly
                    ProformaInvoiceItem::create([
                        'proforma_invoice_id' => $pi->id,
                        'product_id' => $item->product_id,
                        'quotation_item_id' => $item->id,
                        'supplier_company_id' => $supplierId,
                        'description' => $item->product?->name,
                        'specifications' => $item->product?->specification?->description ?? null,
                        'quantity' => $item->quantity,
                        'unit' => 'pcs',
                        'unit_price' => $item->unit_price,
                        'unit_cost' => $item->unit_cost,
                        'cost_currency_code' => $resolved['currency'],
                        'cost_exchange_rate' => $resolved['rate'],
                        'incoterm' => $item->incoterm,
                        'notes' => $item->notes,
                        'sort_order' => ++$maxSort,
                    ]);

                    $linkedQuotationIds[] = $item->quotation_id;
                    $imported++;
                }

                $pi->quotations()->syncWithoutDetaching(array_unique($linkedQuotationIds));

                app(CreateQuotationCommissionCostsAction::class)
                    ->execute($pi, array_unique($linkedQuotationIds));

                Notification::make()
                    ->title($imported.' '.__('messages.items_imported'))
                    ->body(__('messages.items_imported_from_quotations', ['count' => count(array_unique($linkedQuotationIds))]))
                    ->success()
                    ->send();
            });
    }

    protected function importFromSupplierQuotationsAction(): Action
    {
        return Action::make('importFromSupplierQuotations')
            ->label(__('forms.labels.import_from_supplier_quotations'))
            ->icon('heroicon-o-arrow-down-on-square-stack')
            ->color('warning')
            ->visible(fn () => auth()->user()?->can('edit-proforma-invoices'))
            ->form(function () {
                $pi = $this->getOwnerRecord();

                // Get supplier quotations linked to this PI
                $linkedSqs = $pi->supplierQuotations()->with(['company', 'items.product'])->get();

                // Also look for SQs from the same inquiry (not yet linked)
                $inquirySqs = SupplierQuotation::query()
                    ->where('inquiry_id', $pi->inquiry_id)
                    ->whereNotIn('id', $linkedSqs->pluck('id')->toArray())
                    ->with(['company', 'items.product'])
                    ->get();

                $allSqs = $linkedSqs->concat($inquirySqs);

                if ($allSqs->isEmpty()) {
                    return [
                        \Filament\Forms\Components\Placeholder::make('no_sqs')
                            ->label('')
                            ->content('No supplier quotations found for this inquiry.'),
                    ];
                }

                // Build options: group items by SQ
                $options = [];
                foreach ($allSqs as $sq) {
                    foreach ($sq->items as $item) {
                        $label = '['.$sq->reference.' — '.($sq->company?->name ?? 'N/A').'] '
                            .($item->product?->name ?? $item->description ?? 'Item #'.$item->id)
                            .' — Qty: '.$item->quantity
                            .' — Cost: $'.Money::format($item->unit_cost, 4);
                        $options[$item->id] = $label;
                    }
                }

                if (empty($options)) {
                    return [
                        \Filament\Forms\Components\Placeholder::make('no_items')
                            ->label('')
                            ->content('No items found in the linked supplier quotations.'),
                    ];
                }

                return [
                    \Filament\Forms\Components\CheckboxList::make('item_ids')
                        ->label(__('forms.labels.select_items_to_import'))
                        ->options($options)
                        ->required()
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText('Items are grouped by supplier quotation reference. Duplicate products will be skipped.'),
                ];
            })
            ->action(function (array $data) {
                $pi = $this->getOwnerRecord();
                $itemIds = $data['item_ids'] ?? [];

                if (empty($itemIds)) {
                    return;
                }

                $items = SupplierQuotationItem::whereIn('id', $itemIds)
                    ->with(['product', 'product.clients', 'product.specification', 'supplierQuotation'])
                    ->get();

                $maxSort = $pi->items()->max('sort_order') ?? 0;
                $imported = 0;
                $existingProductIds = $pi->items()->pluck('product_id')->filter()->toArray();
                $newSqIds = [];

                $existingPiItemsByProduct = $pi->items()->get()->keyBy('product_id');

                $resolver = app(ProformaInvoiceItemCurrencyResolver::class);

                foreach ($items as $sqItem) {
                    $resolved = $resolver->resolve(
                        $sqItem->supplierQuotation?->currency_code,
                        $pi->currency_code,
                        $pi->issue_date?->toDateString(),
                    );

                    // If product already exists in PI, update cost + currency snapshot
                    if ($sqItem->product_id && isset($existingPiItemsByProduct[$sqItem->product_id])) {
                        $existingItem = $existingPiItemsByProduct[$sqItem->product_id];
                        $existingItem->update([
                            'unit_cost' => $sqItem->unit_cost,
                            'cost_currency_code' => $resolved['currency'],
                            'cost_exchange_rate' => $resolved['rate'],
                            'supplier_company_id' => $sqItem->supplierQuotation->company_id ?? $existingItem->supplier_company_id,
                        ]);
                        $imported++;
                        $newSqIds[] = $sqItem->supplier_quotation_id;

                        continue;
                    }

                    if ($sqItem->product_id) {
                        $existingProductIds[] = $sqItem->product_id;
                    }

                    // Try to get client-specific selling price
                    $unitPrice = 0;
                    if ($sqItem->product) {
                        $clientPivot = $sqItem->product->clients()
                            ->where('companies.id', $pi->company_id)
                            ->first()
                            ?->pivot;
                        if ($clientPivot && ($clientPivot->unit_price ?? 0) > 0) {
                            $unitPrice = $clientPivot->unit_price;
                        }
                    }

                    ProformaInvoiceItem::create([
                        'proforma_invoice_id' => $pi->id,
                        'product_id' => $sqItem->product_id,
                        'quotation_item_id' => null,
                        'supplier_company_id' => $sqItem->supplierQuotation->company_id ?? null,
                        'description' => $sqItem->product?->name ?? $sqItem->description,
                        'specifications' => $sqItem->product?->specification?->description ?? $sqItem->specifications,
                        'quantity' => $sqItem->quantity,
                        'unit' => $sqItem->unit ?? 'pcs',
                        'unit_price' => $unitPrice,
                        'unit_cost' => $sqItem->unit_cost,
                        'cost_currency_code' => $resolved['currency'],
                        'cost_exchange_rate' => $resolved['rate'],
                        'incoterm' => null,
                        'notes' => $sqItem->notes,
                        'sort_order' => ++$maxSort,
                    ]);

                    $newSqIds[] = $sqItem->supplier_quotation_id;
                    $imported++;
                }

                // Link any newly referenced SQs to the PI
                if (! empty($newSqIds)) {
                    $pi->supplierQuotations()->syncWithoutDetaching(array_unique($newSqIds));
                }

                Notification::make()
                    ->title($imported.' '.__('messages.items_imported'))
                    ->body(__('messages.items_imported_from_supplier_quotations', ['count' => $imported]))
                    ->success()
                    ->send();
            });
    }

    protected function importFromInquiryAction(): Action
    {
        return Action::make('importFromInquiry')
            ->label(__('forms.labels.import_from_inquiry'))
            ->icon('heroicon-o-clipboard-document-list')
            ->color('warning')
            ->visible(fn () => auth()->user()?->can('edit-proforma-invoices'))
            ->form(function () {
                $pi = $this->getOwnerRecord();

                $inquiryItems = InquiryItem::query()
                    ->where('inquiry_id', $pi->inquiry_id)
                    ->with(['product.clients' => fn ($q) => $q->where('companies.id', $pi->company_id)])
                    ->orderBy('sort_order')
                    ->get();

                if ($inquiryItems->isEmpty()) {
                    return [
                        \Filament\Forms\Components\Placeholder::make('no_items')
                            ->label('')
                            ->content('No items found in this inquiry.'),
                    ];
                }

                $importedByProduct = $this->importedQuantitiesByProduct($pi);
                $importedByDescription = $this->importedQuantitiesByDescription($pi);

                $options = $inquiryItems->mapWithKeys(function ($item) use ($importedByProduct, $importedByDescription) {
                    $product = $item->product;
                    // MODEL NO segue a regra do CI/PL: external_code do cliente > model_number > SKU.
                    $clientPivot = $product?->clients->first()?->pivot;
                    $modelNo = $clientPivot?->external_code ?: ($product?->model_number ?: $product?->sku);

                    $imported = $item->product_id
                        ? (int) ($importedByProduct[$item->product_id] ?? 0)
                        : (int) ($importedByDescription[$item->description] ?? 0);
                    $remaining = $item->quantity - $imported;

                    return [
                        $item->id => ($modelNo ? $modelNo.' — ' : '')
                            .($product?->name ?? $item->description ?? 'Item #'.$item->id)
                            .' — Qty: '.$item->quantity.' | Remaining: '.$remaining
                            .($item->target_price ? ' — Target: $'.Money::format($item->target_price) : ''),
                    ];
                })->toArray();

                return [
                    \Filament\Forms\Components\CheckboxList::make('item_ids')
                        ->label(__('forms.labels.select_items_to_import'))
                        ->options($options)
                        ->required()
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText(__('forms.helpers.import_items_directly_from_the_inquiry_use_this_for')),
                    \Filament\Forms\Components\Checkbox::make('only_remaining')
                        ->label(__('forms.labels.only_import_remaining_quantities_exclude_already_imported'))
                        ->default(true),
                ];
            })
            ->action(function (array $data) {
                $pi = $this->getOwnerRecord();
                $itemIds = $data['item_ids'] ?? [];
                $onlyRemaining = $data['only_remaining'] ?? true;

                if (empty($itemIds)) {
                    return;
                }

                $items = InquiryItem::whereIn('id', $itemIds)
                    ->with(['product', 'product.suppliers', 'product.specification', 'product.clients'])
                    ->get();

                $importedByProduct = $this->importedQuantitiesByProduct($pi);
                $importedByDescription = $this->importedQuantitiesByDescription($pi);

                $maxSort = $pi->items()->max('sort_order') ?? 0;
                $imported = 0;
                $skipped = 0;

                $resolver = app(ProformaInvoiceItemCurrencyResolver::class);

                foreach ($items as $item) {
                    $alreadyImported = $item->product_id
                        ? (int) ($importedByProduct[$item->product_id] ?? 0)
                        : (int) ($importedByDescription[$item->description] ?? 0);
                    $quantity = $onlyRemaining ? $item->quantity - $alreadyImported : $item->quantity;

                    if ($quantity <= 0) {
                        $skipped++;

                        continue;
                    }

                    $supplierId = null;
                    $unitCost = 0;
                    $unitPrice = $item->target_price ?? 0;
                    $incoterm = null;
                    $costCurrency = null;

                    if ($item->product) {
                        // Get preferred supplier and their unit_cost from product catalog
                        $preferred = $item->product->suppliers()
                            ->orderByDesc('company_product.is_preferred')
                            ->first();

                        if ($preferred) {
                            $supplierId = $preferred->id;
                            $unitCost = $preferred->pivot->unit_price ?? 0;
                            $incoterm = $preferred->pivot->incoterm ?? null;
                            $costCurrency = $preferred->pivot->currency_code ?? null;
                        }

                        // Get client-specific price if available
                        $clientPivot = $item->product->clients()
                            ->where('companies.id', $pi->company_id)
                            ->first()
                            ?->pivot;

                        if ($clientPivot && $clientPivot->unit_price > 0) {
                            $unitPrice = $clientPivot->unit_price;
                        }
                    }

                    $resolved = $resolver->resolve(
                        $costCurrency,
                        $pi->currency_code,
                        $pi->issue_date?->toDateString(),
                    );

                    ProformaInvoiceItem::create([
                        'proforma_invoice_id' => $pi->id,
                        'product_id' => $item->product_id,
                        'quotation_item_id' => null,
                        'supplier_company_id' => $supplierId,
                        'description' => $item->product?->name ?? $item->description,
                        'specifications' => $item->product?->specification?->description ?? $item->specifications,
                        'quantity' => $quantity,
                        'unit' => $item->unit ?? 'pcs',
                        'unit_price' => $unitPrice,
                        'unit_cost' => $unitCost,
                        'cost_currency_code' => $resolved['currency'],
                        'cost_exchange_rate' => $resolved['rate'],
                        'incoterm' => $incoterm,
                        'notes' => $item->notes,
                        'sort_order' => ++$maxSort,
                    ]);

                    $imported++;
                }

                Notification::make()
                    ->title($imported.' '.__('messages.items_imported_from_inquiry'))
                    ->body(
                        __('messages.prices_prefilled')
                        .($skipped > 0 ? ' '.$skipped.' '.__('messages.items_skipped_no_remaining_quantity') : '')
                    )
                    ->success()
                    ->send();
            });
    }

    /**
     * Quantidades já importadas na PI, somadas por produto.
     *
     * @return \Illuminate\Support\Collection<int, int|string>
     */
    protected function importedQuantitiesByProduct($pi): \Illuminate\Support\Collection
    {
        return $pi->items()
            ->whereNotNull('product_id')
            ->toBase()
            ->selectRaw('product_id, SUM(quantity) AS total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');
    }

    /**
     * Quantidades já importadas na PI para itens sem produto, somadas por descrição.
     *
     * @return \Illuminate\Support\Collection<string, int|string>
     */
    protected function importedQuantitiesByDescription($pi): \Illuminate\Support\Collection
    {
        return $pi->items()
            ->whereNull('product_id')
            ->toBase()
            ->selectRaw('description, SUM(quantity) AS total')
            ->groupBy('description')
            ->pluck('total', 'description');
    }

    protected function bulkChangeCurrencyAction(): BulkAction
    {
        return BulkAction::make('bulkChangeCurrency')
            ->label(__('forms.labels.change_cost_currency'))
            ->icon('heroicon-o-currency-dollar')
            ->color('warning')
            ->visible(fn () => auth()->user()?->can('edit-proforma-invoices'))
            ->form([
                Select::make('cost_currency_code')
                    ->label(__('forms.labels.cost_currency'))
                    ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
                    ->required(),
            ])
            ->action(function (Collection $records, array $data) {
                $pi = $this->getOwnerRecord();
                $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
                $resolved = $resolver->resolve(
                    $data['cost_currency_code'],
                    $pi->currency_code,
                    $pi->issue_date?->toDateString(),
                );

                foreach ($records as $record) {
                    $record->update([
                        'cost_currency_code' => $resolved['currency'],
                        'cost_exchange_rate' => $resolved['rate'],
                    ]);
                }

                Notification::make()
                    ->title(__('messages.currency_updated_for_items', ['count' => $records->count()]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    protected function bulkChangeSupplierAction(): BulkAction
    {
        return BulkAction::make('bulkChangeSupplier')
            ->label(__('forms.labels.change_supplier'))
            ->icon('heroicon-o-building-office')
            ->color('info')
            ->visible(fn () => auth()->user()?->can('edit-proforma-invoices'))
            ->form([
                Select::make('supplier_company_id')
                    ->label(__('forms.labels.supplier'))
                    ->options(
                        fn () => Company::query()
                            ->whereHas('companyRoles', fn ($q) => $q->where('role', CompanyRole::SUPPLIER))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                    )
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data) {
                $records->each(fn ($record) => $record->update([
                    'supplier_company_id' => $data['supplier_company_id'],
                ]));

                Notification::make()
                    ->title(__('messages.supplier_updated_for_items', ['count' => $records->count()]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    protected function bulkChangeUnitAction(): BulkAction
    {
        return BulkAction::make('bulkChangeUnit')
            ->label(__('forms.labels.change_unit'))
            ->icon('heroicon-o-scale')
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('edit-proforma-invoices'))
            ->form([
                TextInput::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->required()
                    ->maxLength(20)
                    ->default('pcs'),
            ])
            ->action(function (Collection $records, array $data) {
                $records->each(fn ($record) => $record->update([
                    'unit' => $data['unit'],
                ]));

                Notification::make()
                    ->title(__('messages.unit_updated_for_items', ['count' => $records->count()]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    protected function fillFromProduct(int $productId, Get $get, Set $set): void
    {
        $product = Product::with(['suppliers', 'specification'])->find($productId);
        if (! $product) {
            return;
        }

        $set('description', $product->name);
        $set('specifications', $product->specification?->description);

        $preferred = $product->suppliers()
            ->orderByDesc('company_product.is_preferred')
            ->first();

        $pi = $this->getOwnerRecord();

        if ($preferred) {
            $set('supplier_company_id', $preferred->id);
            $set('unit_cost', Money::toMajor($preferred->pivot->unit_price));

            if ($preferred->pivot->incoterm ?? null) {
                $set('incoterm', $preferred->pivot->incoterm);
            }

            $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
            $resolved = $resolver->resolve(
                $preferred->pivot->currency_code ?? null,
                $pi->currency_code,
                $pi->issue_date?->toDateString(),
            );

            $set('cost_currency_code', $resolved['currency']);
            $set('cost_exchange_rate', $resolved['rate']);
        }

        $clientPivot = $product->clients()
            ->where('companies.id', $pi->company_id)
            ->first()
            ?->pivot;

        if ($clientPivot && $clientPivot->unit_price > 0) {
            $set('unit_price', Money::toMajor($clientPivot->unit_price));
        }
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
