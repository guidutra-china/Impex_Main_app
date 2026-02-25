<?php

namespace App\Filament\Resources\Inquiries\RelationManagers;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
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
                    ->label('Create new draft product')
                    ->helperText('Enable to create a new product in the catalog as draft.')
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

                // --- Existing product selection ---
                Section::make('Select Existing Product')
                    ->schema([
                        Select::make('product_id')
                            ->label('Product')
                            ->options(function () {
                                return Product::query()
                                    ->orderBy('name')
                                    ->get()
                                    ->mapWithKeys(fn ($p) => [
                                        $p->id => ($p->status === ProductStatus::DRAFT ? '[DRAFT] ' : '') . $p->sku . ' — ' . $p->name,
                                    ]);
                            })
                            ->searchable()
                            ->getSearchResultsUsing(function (string $search) {
                                return Product::query()
                                    ->where(function ($query) use ($search) {
                                        $query->where('name', 'like', "%{$search}%")
                                            ->orWhere('sku', 'like', "%{$search}%")
                                            ->orWhereHas('companies', function ($q) use ($search) {
                                                $q->where('company_product.external_code', 'like', "%{$search}%");
                                            });
                                    })
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn ($p) => [
                                        $p->id => ($p->status === ProductStatus::DRAFT ? '[DRAFT] ' : '') . $p->sku . ' — ' . $p->name,
                                    ]);
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
                            ->helperText('Search by name, SKU, or supplier/client code.')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (Get $get) => ! $get('create_new_product')),

                // --- New draft product creation ---
                // Fields use dehydrated(true) so they reach the CreateAction->using() callback.
                // They are manually removed from $data before creating the InquiryItem.
                Section::make('New Draft Product')
                    ->description('A new product will be created in the catalog with DRAFT status. You can complete its details later.')
                    ->schema([
                        TextInput::make('new_product_name')
                            ->label('Product Name')
                            ->required(fn (Get $get) => (bool) $get('create_new_product'))
                            ->maxLength(255)
                            ->helperText('Name as described by the client. Can be refined later.'),
                        Select::make('new_product_category_id')
                            ->label('Category (optional)')
                            ->options(fn () => Category::active()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->helperText('Assign a category if known. Affects SKU prefix generation.'),
                    ])
                    ->visible(fn (Get $get) => (bool) $get('create_new_product'))
                    ->columns(2),

                // --- Common fields ---
                Section::make('Item Details')
                    ->schema([
                        TextInput::make('description')
                            ->label('Item Description')
                            ->maxLength(255)
                            ->helperText('Description for this inquiry line item. Auto-filled from product.')
                            ->visible(fn (Get $get) => ! $get('create_new_product'))
                            ->columnSpanFull(),
                        TextInput::make('quantity')
                            ->label('Quantity')
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required(),
                        TextInput::make('unit')
                            ->label('Unit')
                            ->default('pcs')
                            ->maxLength(20)
                            ->required(),
                        TextInput::make('target_price')
                            ->label('Target Price')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->helperText('Client target price per unit, if provided.')
                            ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : null)
                            ->dehydrateStateUsing(fn ($state) => $state ? Money::toMinor($state) : null),
                        Textarea::make('specifications')
                            ->label('Specifications')
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText('Client-provided specs, dimensions, certifications, etc.')
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->placeholder('—')
                    ->searchable()
                    ->badge()
                    ->color(fn ($record) => $record->product?->status === ProductStatus::DRAFT ? 'warning' : 'gray'),
                TextColumn::make('displayName')
                    ->label('Item')
                    ->searchable(['description'])
                    ->limit(40),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->alignCenter(),
                TextColumn::make('target_price')
                    ->label('Target Price')
                    ->formatStateUsing(fn ($state) => $state ? '$ ' . Money::format($state) : '—')
                    ->alignEnd(),
                TextColumn::make('specifications')
                    ->label('Specs')
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
        // Extract draft product fields from data (they are NOT InquiryItem columns)
        $newName = $data['new_product_name'] ?? null;
        $newCategoryId = $data['new_product_category_id'] ?? null;
        $newDescription = $data['new_product_description'] ?? null;

        // Remove non-InquiryItem fields before creating the record
        unset($data['new_product_name'], $data['new_product_category_id'], $data['new_product_description']);

        // If we have draft product data, create the product first
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

            // Auto-associate the Inquiry's client with this product
            $inquiry = $this->getOwnerRecord();
            if ($inquiry->company_id) {
                $product->companies()->attach($inquiry->company_id, [
                    'role' => 'client',
                    'external_name' => $newName,
                    'is_preferred' => true,
                ]);
            }

            Notification::make()
                ->title('Draft product created: ' . $product->sku)
                ->body($product->name . ' — linked to ' . ($inquiry->company?->name ?? 'client'))
                ->info()
                ->send();
        }

        return $this->getOwnerRecord()->items()->create($data);
    }
}
