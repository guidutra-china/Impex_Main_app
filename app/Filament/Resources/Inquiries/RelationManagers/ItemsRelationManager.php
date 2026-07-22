<?php

namespace App\Filament\Resources\Inquiries\RelationManagers;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Filament\Actions\PasteItemsFromSpreadsheetAction;
use App\Filament\Pages\Assistant;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Inquiry Items';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clipboard-document-list';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Toggle::make('create_new_product')
                    ->label(__('forms.labels.create_new_draft_product'))
                    ->live()
                    ->dehydrated(false)
                    ->default(false)
                    ->columnSpanFull()
                    ->afterStateUpdated(function (Set $set, bool $state) {
                        if ($state) {
                            $set('product_id', null);
                        } else {
                            $set('new_product_name', null);
                            $set('new_product_category_id', null);
                            $set('new_product_description', null);
                        }
                    }),

                // --- Existing product selection with filters ---
                Select::make('filter_category_id')
                    ->label(__('forms.tabs.filter_by_category'))
                    ->options(function () {
                        return Category::active()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => $c->reverse_path]);
                    })
                    ->allowHtml()
                    ->searchable()
                    ->placeholder(__('forms.placeholders.all_categories'))
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function (Set $set) {
                        $set('filter_supplier_id', null);
                        $set('product_id', null);
                    })
                    ->visible(fn (Get $get) => ! $get('create_new_product')),

                Select::make('filter_supplier_id')
                    ->label(__('forms.tabs.filter_by_supplier'))
                    ->options(function (Get $get) {
                        $categoryId = $get('filter_category_id');

                        $query = Company::withRole(CompanyRole::SUPPLIER);

                        if ($categoryId) {
                            $categoryIds = $this->getCategoryWithDescendantIds((int) $categoryId);
                            $query->whereHas('products', function ($q) use ($categoryIds) {
                                $q->whereIn('category_id', $categoryIds)
                                    ->where('company_product.role', 'supplier');
                            });
                        }

                        return $query->orderBy('name')->pluck('name', 'id');
                    })
                    ->searchable()
                    ->placeholder(__('forms.placeholders.all_suppliers'))
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(fn (Set $set) => $set('product_id', null))
                    ->visible(fn (Get $get) => ! $get('create_new_product')),

                Select::make('product_id')
                    ->label(__('forms.labels.product'))
                    ->searchable()
                    ->options(function (Get $get) {
                        $categoryId = $get('filter_category_id');
                        $supplierId = $get('filter_supplier_id');

                        if (! $categoryId && ! $supplierId) {
                            return [];
                        }

                        return $this->buildProductQuery(null, $get);
                    })
                    ->getSearchResultsUsing(function (string $search, Get $get) {
                        return $this->buildProductQuery($search, $get);
                    })
                    ->getOptionLabelUsing(function ($value) {
                        $product = Product::find($value);
                        if (! $product) {
                            return null;
                        }
                        $prefix = $product->status === ProductStatus::DRAFT ? '[DRAFT] ' : '';
                        $model = $product->model_number ? ' · '.$product->model_number : '';

                        return $prefix.$product->sku.' — '.$product->name.$model;
                    })
                    ->live()
                    ->afterStateUpdated(function (Set $set, ?string $state) {
                        if ($state) {
                            $product = Product::find($state);
                            if ($product) {
                                $set('description', $product->name);
                            }
                        }
                    })
                    ->helperText(__('forms.helpers.type_to_search_by_name_sku_or_code_use_filters_above'))
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => ! $get('create_new_product')),

                // --- New draft product creation ---
                TextInput::make('new_product_name')
                    ->label(__('forms.labels.product_name'))
                    ->required(fn (Get $get) => (bool) $get('create_new_product'))
                    ->maxLength(255)
                    ->visible(fn (Get $get) => (bool) $get('create_new_product'))
                    ->columnSpan(2),
                Select::make('new_product_category_id')
                    ->label(__('forms.labels.category_optional'))
                    ->options(fn () => Category::active()->orderBy('name')->get()->mapWithKeys(fn (Category $cat) => [$cat->id => $cat->full_path]))
                    ->searchable()
                    ->visible(fn (Get $get) => (bool) $get('create_new_product')),

                // --- Common fields ---
                TextInput::make('description')
                    ->label(__('forms.labels.item_description'))
                    ->maxLength(255)
                    ->visible(fn (Get $get) => ! $get('create_new_product'))
                    ->columnSpanFull(),
                TextInput::make('quantity')
                    ->label(__('forms.labels.quantity'))
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required(),
                TextInput::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->default('pcs')
                    ->maxLength(20)
                    ->required(),
                TextInput::make('target_price')
                    ->label(__('forms.labels.target_price'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix('$')
                    ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null),
                Textarea::make('specifications')
                    ->label(__('forms.labels.specifications'))
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }

    protected function buildProductQuery(?string $search, Get $get): array
    {
        $categoryId = $get('filter_category_id');
        $supplierId = $get('filter_supplier_id');
        $clientId = $this->getOwnerRecord()->company_id;

        $query = Product::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhereHas('companies', function ($sub) use ($search) {
                        $sub->where('company_product.external_code', 'like', "%{$search}%")
                            ->orWhere('company_product.external_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($categoryId) {
            $categoryIds = $this->getCategoryWithDescendantIds((int) $categoryId);
            $query->whereIn('category_id', $categoryIds);
        }

        if ($supplierId) {
            $query->whereHas('suppliers', fn ($q) => $q->where('companies.id', $supplierId));
        }

        $products = $query->limit(50)->get();

        // Prioritize client products
        if ($clientId) {
            $clientProductIds = $products
                ->filter(fn ($p) => $p->clients()->where('companies.id', $clientId)->exists())
                ->pluck('id')
                ->toArray();

            $products = $products->sortBy(function ($p) use ($clientProductIds) {
                return in_array($p->id, $clientProductIds) ? 0 : 1;
            });
        }

        return $products->mapWithKeys(function ($p) use ($clientId) {
            $prefix = '';
            if ($p->status === ProductStatus::DRAFT) {
                $prefix = '[DRAFT] ';
            }

            $isClientProduct = false;
            if ($clientId) {
                $isClientProduct = $p->clients()->where('companies.id', $clientId)->exists();
            }

            $clientBadge = $isClientProduct ? ' ★' : '';
            $model = $p->model_number ? ' · '.$p->model_number : '';

            return [$p->id => $prefix.$p->sku.' — '.$p->name.$model.$clientBadge];
        })->toArray();
    }

    protected function getCategoryWithDescendantIds(int $categoryId): array
    {
        $ids = [$categoryId];
        $children = Category::where('parent_id', $categoryId)->pluck('id')->toArray();

        foreach ($children as $childId) {
            $ids = array_merge($ids, $this->getCategoryWithDescendantIds($childId));
        }

        return $ids;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $companyId = $this->getOwnerRecord()->company_id;

                // Só o pivot do cliente da inquiry — é dele que saem MODEL NO e descrição (mesma regra do CI/PL).
                return $query->with([
                    'product.companies' => fn ($q) => $q
                        ->where('companies.id', $companyId)
                        ->where('company_product.role', 'client'),
                ]);
            })
            ->columns([
                \Filament\Tables\Columns\ImageColumn::make('product.avatar')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?background=e2e8f0&color=94a3b8&name=P&size=40')
                    ->toggleable(),
                TextColumn::make('product.sku')
                    ->label(__('forms.labels.sku'))
                    ->placeholder('—')
                    ->searchable()
                    ->badge()
                    ->color(fn ($record) => $record->product?->status === ProductStatus::DRAFT ? 'warning' : 'gray')
                    ->toggleable(),
                TextColumn::make('model_no')
                    ->label(__('forms.labels.model_number'))
                    // Mesma regra do CI/PL: código do cliente (pivot) > modelo > SKU.
                    ->state(function ($record) {
                        $product = $record->product;
                        if (! $product) {
                            return null;
                        }

                        $pivot = $product->companies->first()?->pivot;

                        return $pivot?->external_code ?: ($product->model_number ?: $product->sku);
                    })
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('displayName')
                    ->label(__('forms.labels.item'))
                    ->searchable(['description'])
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('model_description')
                    ->label(__('forms.labels.description'))
                    // Mesma regra do CI/PL: descrição do cliente (pivot) > descrição do produto.
                    ->state(function ($record) {
                        $pivot = $record->product?->companies->first()?->pivot;

                        return $pivot?->external_description ?: $record->product?->description;
                    })
                    ->placeholder('—')
                    ->limit(30)
                    ->toggleable(),

                // --- Inline editable columns ---
                TextInputColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->rules(['required', 'integer', 'min:1'])
                    ->alignCenter()
                    ->toggleable(),
                TextInputColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->rules(['required', 'max:20'])
                    ->alignCenter()
                    ->toggleable(),
                TextInputColumn::make('target_price')
                    ->label(__('forms.labels.target_price'))
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.0001')
                    ->prefix('$')
                    ->rules(['nullable', 'numeric', 'min:0'])
                    ->getStateUsing(fn ($record) => $record->target_price ? number_format(Money::toMajor($record->target_price), 4, '.', '') : null)
                    // updateStateUsing REPLACES Filament's default save entirely,
                    // preventing the raw major-unit string from being written to DB.
                    ->updateStateUsing(function ($record, $state) {
                        if (blank($state)) {
                            $record->target_price = null;
                            $record->save();

                            return null;
                        }
                        $floatValue = (float) str_replace(',', '', (string) $state);
                        $record->target_price = Money::toMinor($floatValue);
                        $record->save();

                        return number_format($floatValue, 4, '.', '');
                    })
                    ->alignEnd()
                    ->toggleable(),
                TextInputColumn::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rules(['nullable', 'max:1000'])
                    ->toggleable(),
                TextColumn::make('specifications')
                    ->label(__('forms.labels.specs'))
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-inquiries'))
                    ->preserveFormDataWhenCreatingAnother([
                        'filter_category_id',
                        'filter_supplier_id',
                    ])
                    ->using(function (array $data, string $model) {
                        return $this->createItemWithDraftProduct($data);
                    }),
                $this->bulkAddProductsAction(),
                PasteItemsFromSpreadsheetAction::forInquiryItems(),
                Action::make('importWithAi')
                    ->label(__('assistant.import_with_ai'))
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->visible(fn () => (auth()->user()?->can('edit-inquiries') ?? false)
                        && (auth()->user()?->can('view-assistant') ?? false)
                        && in_array($this->getOwnerRecord()->status, [InquiryStatus::RECEIVED, InquiryStatus::QUOTING], true))
                    ->url(fn () => Assistant::getUrl([
                        'import' => 'inquiry',
                        'inquiry_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-inquiries')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-inquiries')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-inquiries')),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->emptyStateHeading('No items')
            ->emptyStateDescription('Add products or create new draft products for items the client is requesting.');
    }

    protected function bulkAddProductsAction(): Action
    {
        return Action::make('bulkAddProducts')
            ->label(__('forms.labels.bulk_add_products'))
            ->icon('heroicon-o-squares-plus')
            ->color('info')
            ->visible(fn () => auth()->user()?->can('edit-inquiries'))
            ->modalWidth('2xl')
            ->form([
                Select::make('filter_category_id')
                    ->label(__('forms.tabs.filter_by_category'))
                    ->options(function () {
                        return Category::active()
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($c) => [$c->id => $c->reverse_path]);
                    })
                    ->allowHtml()
                    ->searchable()
                    ->placeholder(__('forms.placeholders.all_categories'))
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function (Set $set) {
                        $set('filter_supplier_id', null);
                        $set('product_ids', []);
                    }),

                Select::make('filter_supplier_id')
                    ->label(__('forms.tabs.filter_by_supplier'))
                    ->options(function (Get $get) {
                        $categoryId = $get('filter_category_id');

                        $query = Company::withRole(CompanyRole::SUPPLIER);

                        if ($categoryId) {
                            $categoryIds = $this->getCategoryWithDescendantIds((int) $categoryId);
                            $query->whereHas('products', function ($q) use ($categoryIds) {
                                $q->whereIn('category_id', $categoryIds)
                                    ->where('company_product.role', 'supplier');
                            });
                        }

                        return $query->orderBy('name')->pluck('name', 'id');
                    })
                    ->searchable()
                    ->placeholder(__('forms.placeholders.all_suppliers'))
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(fn (Set $set) => $set('product_ids', [])),

                CheckboxList::make('product_ids')
                    ->label(__('forms.labels.select_products_to_add'))
                    ->options(function (Get $get) {
                        return $this->bulkProductOptions(
                            $get('filter_category_id') ? (int) $get('filter_category_id') : null,
                            $get('filter_supplier_id') ? (int) $get('filter_supplier_id') : null,
                        );
                    })
                    ->required()
                    ->searchable()
                    ->bulkToggleable()
                    ->columns(1)
                    ->helperText(__('forms.helpers.bulk_add_products_qty_hint')),
            ])
            ->action(function (array $data) {
                $inquiry = $this->getOwnerRecord();
                $productIds = array_map('intval', $data['product_ids'] ?? []);

                if (empty($productIds)) {
                    return;
                }

                $existingProductIds = $inquiry->items()
                    ->whereNotNull('product_id')
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $products = Product::whereIn('id', $productIds)->orderBy('name')->get();

                $maxSort = $inquiry->items()->max('sort_order') ?? 0;
                $created = 0;
                $skipped = 0;

                foreach ($products as $product) {
                    if (in_array($product->id, $existingProductIds, true)) {
                        $skipped++;

                        continue;
                    }

                    $inquiry->items()->create([
                        'product_id' => $product->id,
                        'description' => $product->name,
                        'quantity' => 1,
                        'unit' => 'pcs',
                        'sort_order' => ++$maxSort,
                    ]);

                    $created++;
                }

                Notification::make()
                    ->title($created.' '.__('messages.products_added_to_inquiry'))
                    ->body($skipped > 0 ? $skipped.' '.__('messages.products_skipped_already_in_inquiry') : null)
                    ->success()
                    ->send();
            });
    }

    /**
     * Options for the bulk-add CheckboxList: filtered by category/supplier,
     * client products (★) first. Text search happens client-side in the list.
     *
     * @return array<int, string>
     */
    protected function bulkProductOptions(?int $categoryId, ?int $supplierId): array
    {
        $clientId = $this->getOwnerRecord()->company_id;

        $query = Product::query()
            ->with(['clients' => fn ($q) => $q->where('companies.id', $clientId)]);

        if ($categoryId) {
            $categoryIds = $this->getCategoryWithDescendantIds($categoryId);
            $query->whereIn('category_id', $categoryIds);
        }

        if ($supplierId) {
            $query->whereHas('suppliers', fn ($q) => $q->where('companies.id', $supplierId));
        }

        $products = $query->orderBy('name')->limit(200)->get();

        return $products
            ->sortBy(fn (Product $p) => $p->clients->isEmpty() ? 1 : 0)
            ->mapWithKeys(function (Product $p) {
                $prefix = $p->status === ProductStatus::DRAFT ? '[DRAFT] ' : '';
                $clientBadge = $p->clients->isNotEmpty() ? ' ★' : '';
                $model = $p->model_number ? ' · '.$p->model_number : '';

                return [$p->id => $prefix.$p->sku.' — '.$p->name.$model.$clientBadge];
            })
            ->toArray();
    }

    protected function createItemWithDraftProduct(array $data): \Illuminate\Database\Eloquent\Model
    {
        $newName = $data['new_product_name'] ?? null;
        $newCategoryId = $data['new_product_category_id'] ?? null;
        $newDescription = $data['new_product_description'] ?? null;

        unset(
            $data['new_product_name'],
            $data['new_product_category_id'],
            $data['new_product_description'],
            $data['filter_category_id'],
            $data['filter_supplier_id'],
        );

        if ($newName && empty($data['product_id'])) {
            $product = Product::create([
                'name' => $newName,
                'description' => $newDescription,
                'category_id' => $newCategoryId,
                'status' => ProductStatus::DRAFT,
            ]);

            $data['product_id'] = $product->id;

            if (empty($data['description'])) {
                $data['description'] = $newName;
            }

            $inquiry = $this->getOwnerRecord();
            if ($inquiry->company_id) {
                $product->companies()->attach($inquiry->company_id, [
                    'role' => 'client',
                    'external_name' => $newName,
                    'is_preferred' => true,
                ]);
            }

            Notification::make()
                ->title(__('messages.draft_product_created').': '.$product->sku)
                ->body($product->name.' — '.__('messages.linked_to').' '.($inquiry->company?->name ?? __('messages.client')))
                ->info()
                ->send();
        }

        return $this->getOwnerRecord()->items()->create($data);
    }
}
