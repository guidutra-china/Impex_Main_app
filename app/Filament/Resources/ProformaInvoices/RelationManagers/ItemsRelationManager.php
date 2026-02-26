<?php

namespace App\Filament\Resources\ProformaInvoices\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
                ->default(0),

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
                TextColumn::make('supplierCompany.name')
                    ->label(__('forms.labels.supplier'))
                    ->limit(20)
                    ->placeholder('—'),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->alignCenter(),
                TextColumn::make('unit_price')
                    ->label(__('forms.labels.price'))
                    ->formatStateUsing(fn ($state) => Money::format($state, 4))
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('unit_cost')
                    ->label(__('forms.labels.cost'))
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
                TextColumn::make('margin')
                    ->label(__('forms.labels.margin'))
                    ->getStateUsing(fn ($record) => $record->margin)
                    ->suffix('%')
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
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
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-proforma-invoices')),
                ]),
            ])
            ->reorderable('sort_order');
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
                    ->with(['quotation', 'product'])
                    ->get();

                if ($quotationItems->isEmpty()) {
                    return [
                        \Filament\Forms\Components\Placeholder::make('no_items')
                            ->label('')
                            ->content('No quotation items found for this inquiry.'),
                    ];
                }

                $options = $quotationItems->mapWithKeys(fn ($item) => [
                    $item->id => '[' . $item->quotation->reference . '] '
                        . ($item->product?->name ?? 'Item #' . $item->id)
                        . ' — Qty: ' . $item->quantity
                        . ' — $' . Money::format($item->unit_price, 4),
                ])->toArray();

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

                foreach ($items as $item) {
                    $supplierId = $item->selected_supplier_id;

                    if (! $supplierId && $item->product) {
                        $preferred = $item->product->suppliers()
                            ->orderByDesc('company_product.is_preferred')
                            ->first();
                        $supplierId = $preferred?->id;
                    }

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
                        'incoterm' => $item->incoterm,
                        'notes' => $item->notes,
                        'sort_order' => ++$maxSort,
                    ]);

                    $linkedQuotationIds[] = $item->quotation_id;
                    $imported++;
                }

                $pi->quotations()->syncWithoutDetaching(array_unique($linkedQuotationIds));

                Notification::make()
                    ->title($imported . ' ' . __('messages.items_imported'))
                    ->body(__('messages.items_imported_from_quotations', ['count' => count(array_unique($linkedQuotationIds))]))
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
                    ->with('product')
                    ->orderBy('sort_order')
                    ->get();

                if ($inquiryItems->isEmpty()) {
                    return [
                        \Filament\Forms\Components\Placeholder::make('no_items')
                            ->label('')
                            ->content('No items found in this inquiry.'),
                    ];
                }

                $options = $inquiryItems->mapWithKeys(fn ($item) => [
                    $item->id => ($item->product?->name ?? $item->description ?? 'Item #' . $item->id)
                        . ' — Qty: ' . $item->quantity
                        . ($item->target_price ? ' — Target: $' . Money::format($item->target_price) : ''),
                ])->toArray();

                return [
                    \Filament\Forms\Components\CheckboxList::make('item_ids')
                        ->label(__('forms.labels.select_items_to_import'))
                        ->options($options)
                        ->required()
                        ->searchable()
                        ->bulkToggleable()
                        ->helperText(__('forms.helpers.import_items_directly_from_the_inquiry_use_this_for')),
                ];
            })
            ->action(function (array $data) {
                $pi = $this->getOwnerRecord();
                $itemIds = $data['item_ids'] ?? [];

                if (empty($itemIds)) {
                    return;
                }

                $items = InquiryItem::whereIn('id', $itemIds)
                    ->with(['product', 'product.suppliers', 'product.specification', 'product.clients'])
                    ->get();

                $maxSort = $pi->items()->max('sort_order') ?? 0;
                $imported = 0;

                foreach ($items as $item) {
                    $supplierId = null;
                    $unitCost = 0;
                    $unitPrice = $item->target_price ?? 0;
                    $incoterm = null;

                    if ($item->product) {
                        // Get preferred supplier and their unit_cost from product catalog
                        $preferred = $item->product->suppliers()
                            ->orderByDesc('company_product.is_preferred')
                            ->first();

                        if ($preferred) {
                            $supplierId = $preferred->id;
                            $unitCost = $preferred->pivot->unit_price ?? 0;
                            $incoterm = $preferred->pivot->incoterm ?? null;
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

                    ProformaInvoiceItem::create([
                        'proforma_invoice_id' => $pi->id,
                        'product_id' => $item->product_id,
                        'quotation_item_id' => null,
                        'supplier_company_id' => $supplierId,
                        'description' => $item->product?->name ?? $item->description,
                        'specifications' => $item->product?->specification?->description ?? $item->specifications,
                        'quantity' => $item->quantity,
                        'unit' => $item->unit ?? 'pcs',
                        'unit_price' => $unitPrice,
                        'unit_cost' => $unitCost,
                        'incoterm' => $incoterm,
                        'notes' => $item->notes,
                        'sort_order' => ++$maxSort,
                    ]);

                    $imported++;
                }

                Notification::make()
                    ->title($imported . ' ' . __('messages.items_imported_from_inquiry'))
                    ->body(__('messages.prices_prefilled'))
                    ->success()
                    ->send();
            });
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

        if ($preferred) {
            $set('supplier_company_id', $preferred->id);
            $set('unit_cost', Money::toMajor($preferred->pivot->unit_price));

            if ($preferred->pivot->incoterm ?? null) {
                $set('incoterm', $preferred->pivot->incoterm);
            }
        }

        $pi = $this->getOwnerRecord();
        $clientPivot = $product->clients()
            ->where('companies.id', $pi->company_id)
            ->first()
            ?->pivot;

        if ($clientPivot && $clientPivot->unit_price > 0) {
            $set('unit_price', Money::toMajor($clientPivot->unit_price));
        }
    }
}
