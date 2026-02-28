<?php

namespace App\Filament\Resources\Inquiries\RelationManagers;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use App\Domain\Infrastructure\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Inquiry Items';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clipboard-document-list';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('create_new_product')
                    ->label(__('forms.labels.create_new_draft_product'))
                    ->helperText(__('forms.helpers.enable_to_create_a_new_product_in_the_catalog_as_draft'))
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
                Section::make(__('forms.sections.select_existing_product'))
                    ->schema([
                        Select::make('filter_category_id')
                            ->label(__('forms.labels.filter_by_category'))
                            ->options(function () {
                                return Category::active()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn ($c) => [$c->id => $c->full_path]);
                            })
                            ->searchable()
                            ->placeholder(__('forms.placeholders.all_categories'))
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set) => $set('product_id', null)),

                        Select::make('filter_supplier_id')
                            ->label(__('forms.labels.filter_by_supplier'))
                            ->options(function () {
                                return Company::withRole(CompanyRole::SUPPLIER)
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->placeholder(__('forms.placeholders.all_suppliers'))
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn (Set $set) => $set('product_id', null)),

                        Select::make('product_id')
                            ->label(__('forms.labels.product'))
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search, Get $get) {
                                return $this->buildProductQuery($search, $get);
                            })
                            ->getOptionLabelUsing(function ($value) {
                                $product = Product::find($value);
                                return $product
                                    ? ($product->status === ProductStatus::DRAFT ? '[DRAFT] ' : '') . $product->sku . ' — ' . $product->name
                                    : null;
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
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->visible(fn (Get $get) => ! $get('create_new_product')),

                // --- New draft product creation ---
                Section::make(__('forms.sections.new_draft_product'))
                    ->description(__('forms.descriptions.a_new_product_will_be_created_in_the_catalog_with_draft'))
                    ->schema([
                        TextInput::make('new_product_name')
                            ->label(__('forms.labels.product_name'))
                            ->required(fn (Get $get) => (bool) $get('create_new_product'))
                            ->maxLength(255)
                            ->helperText(__('forms.helpers.name_as_described_by_the_client_can_be_refined_later')),
                        Select::make('new_product_category_id')
                            ->label(__('forms.labels.category_optional'))
                            ->options(fn () => Category::active()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText(__('forms.helpers.assign_a_category_if_known_affects_sku_prefix_generation')),
                    ])
                    ->visible(fn (Get $get) => (bool) $get('create_new_product'))
                    ->columns(2),

                // --- Common fields ---
                Section::make(__('forms.sections.item_details'))
                    ->schema([
                        TextInput::make('description')
                            ->label(__('forms.labels.item_description'))
                            ->maxLength(255)
                            ->helperText(__('forms.helpers.description_for_this_inquiry_line_item_autofilled_from'))
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
                            ->helperText(__('forms.helpers.client_target_price_per_unit_if_provided'))
                            ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                            ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null),
                        Textarea::make('specifications')
                            ->label(__('forms.labels.specifications'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText(__('forms.helpers.clientprovided_specs_dimensions_certifications_etc'))
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('forms.labels.notes'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    protected function buildProductQuery(string $search, Get $get): array
    {
        $categoryId = $get('filter_category_id');
        $supplierId = $get('filter_supplier_id');
        $clientId = $this->getOwnerRecord()->company_id;

        $query = Product::query()
            ->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhereHas('companies', function ($sub) use ($search) {
                        $sub->where('company_product.external_code', 'like', "%{$search}%")
                            ->orWhere('company_product.external_name', 'like', "%{$search}%");
                    });
            });

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

            return [$p->id => $prefix . $p->sku . ' — ' . $p->name . $clientBadge];
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
            ->columns([
                TextColumn::make('product.sku')
                    ->label(__('forms.labels.sku'))
                    ->placeholder('—')
                    ->searchable()
                    ->badge()
                    ->color(fn ($record) => $record->product?->status === ProductStatus::DRAFT ? 'warning' : 'gray'),
                TextColumn::make('displayName')
                    ->label(__('forms.labels.item'))
                    ->searchable(['description'])
                    ->limit(40),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->alignCenter(),
                TextColumn::make('target_price')
                    ->label(__('forms.labels.target_price'))
                    ->formatStateUsing(fn ($state) => $state ? '$ ' . Money::format($state) : '—')
                    ->alignEnd(),
                TextColumn::make('specifications')
                    ->label(__('forms.labels.specs'))
                    ->limit(30)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-inquiries'))
                    ->using(function (array $data, string $model) {
                        return $this->createItemWithDraftProduct($data);
                    }),
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
                ->title(__('messages.draft_product_created') . ': ' . $product->sku)
                ->body($product->name . ' — ' . __('messages.linked_to') . ' ' . ($inquiry->company?->name ?? __('messages.client')))
                ->info()
                ->send();
        }

        return $this->getOwnerRecord()->items()->create($data);
    }
}
