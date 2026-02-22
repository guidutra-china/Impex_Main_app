<?php

namespace App\Filament\Resources\Quotations\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Quotation Items';

    protected static string | \BackedEnum | null $icon = 'heroicon-o-shopping-cart';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label('Product')
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
                            ->mapWithKeys(fn (Product $product) => [
                                $product->id => "{$product->sku} — {$product->name}",
                            ]);
                    })
                    ->getOptionLabelUsing(function ($value) {
                        $product = Product::find($value);
                        return $product ? "{$product->sku} — {$product->name}" : null;
                    })
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        if (! $state) {
                            return;
                        }

                        $set('supplier_quotation_item_id', null);
                        $set('selected_supplier_id', null);
                        $this->fillPricesFromProduct((int) $state, $get, $set);
                    })
                    ->columnSpanFull(),

                Select::make('supplier_quotation_item_id')
                    ->label('Source (Supplier Quotation)')
                    ->options(function (Get $get) {
                        $productId = $get('product_id');
                        $quotation = $this->getOwnerRecord();
                        $inquiryId = $quotation->inquiry_id;

                        if (! $productId || ! $inquiryId) {
                            return [];
                        }

                        return SupplierQuotationItem::query()
                            ->where('product_id', $productId)
                            ->where('unit_cost', '>', 0)
                            ->whereHas('supplierQuotation', function ($q) use ($inquiryId) {
                                $q->where('inquiry_id', $inquiryId)
                                    ->whereIn('status', [
                                        SupplierQuotationStatus::RECEIVED,
                                        SupplierQuotationStatus::UNDER_ANALYSIS,
                                        SupplierQuotationStatus::SELECTED,
                                    ]);
                            })
                            ->with('supplierQuotation.company')
                            ->get()
                            ->mapWithKeys(fn ($sqItem) => [
                                $sqItem->id => "{$sqItem->supplierQuotation->reference} — {$sqItem->supplierQuotation->company->name} — $" . number_format($sqItem->unit_cost / 100, 2),
                            ])
                            ->toArray();
                    })
                    ->searchable()
                    ->placeholder('Select supplier quotation source...')
                    ->helperText('Optional. Link this item to a specific supplier quotation for traceability.')
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        if (! $state) {
                            return;
                        }

                        $sqItem = SupplierQuotationItem::with('supplierQuotation')->find($state);
                        if (! $sqItem) {
                            return;
                        }

                        $set('selected_supplier_id', $sqItem->supplierQuotation->company_id);
                        $set('unit_cost', $sqItem->unit_cost / 100);

                        $quotation = $this->getOwnerRecord();
                        $clientId = $quotation->company_id;
                        $productId = $get('product_id');

                        $clientPivot = null;
                        if ($productId) {
                            $product = Product::find($productId);
                            $clientPivot = $product?->clients()
                                ->where('companies.id', $clientId)
                                ->first()
                                ?->pivot;
                        }

                        if ($clientPivot && $clientPivot->unit_price > 0) {
                            $set('unit_price', $clientPivot->unit_price / 100);
                        } else {
                            $this->recalculateUnitPrice($get, $set);
                        }
                    }),

                Select::make('selected_supplier_id')
                    ->label('Selected Supplier')
                    ->options(function (Get $get) {
                        $productId = $get('product_id');
                        if (! $productId) {
                            return [];
                        }

                        return Product::find($productId)
                            ?->suppliers()
                            ->pluck('companies.name', 'companies.id')
                            ->toArray() ?? [];
                    })
                    ->searchable()
                    ->placeholder('Select supplier...')
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        if (! $state || ! $get('product_id')) {
                            return;
                        }

                        $this->fillSupplierCost((int) $get('product_id'), (int) $state, $get, $set);
                    }),

                TextInput::make('quantity')
                    ->label('Quantity')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->default(1),

                TextInput::make('unit_cost')
                    ->label('Unit Cost')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('$')
                    ->inputMode('decimal')
                    ->default(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $this->recalculateUnitPrice($get, $set);
                    }),

                TextInput::make('commission_rate')
                    ->label('Commission Rate (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->default(fn () => $this->getOwnerRecord()->commission_rate ?? 0)
                    ->visible(fn () => $this->getOwnerRecord()->commission_type === CommissionType::EMBEDDED)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $this->recalculateUnitPrice($get, $set);
                    })
                    ->helperText('Commission embedded in the unit price.'),

                TextInput::make('unit_price')
                    ->label('Unit Price (to Client)')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix('$')
                    ->inputMode('decimal')
                    ->default(0)
                    ->helperText('Auto-filled from catalog, supplier quotation, or calculated from cost + commission.'),

                Select::make('incoterm')
                    ->label('Incoterm')
                    ->options(Incoterm::class)
                    ->placeholder('Select incoterm...'),

                Textarea::make('notes')
                    ->label('Item Notes')
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product.sku')
                    ->label('SKU')
                    ->searchable()
                    ->badge()
                    ->color(fn ($record) => $record->product?->status?->value === 'draft' ? 'warning' : 'gray'),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->limit(30)
                    ->weight('bold'),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('selectedSupplier.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('supplierQuotationItem.supplierQuotation.reference')
                    ->label('SQ Source')
                    ->badge()
                    ->color('info')
                    ->placeholder('Manual')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('unit_cost')
                    ->label('Unit Cost')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : '—')
                    ->alignEnd(),
                TextColumn::make('commission_rate')
                    ->label('Comm. %')
                    ->suffix('%')
                    ->alignCenter()
                    ->visible(fn () => $this->getOwnerRecord()->commission_type === CommissionType::EMBEDDED),
                TextColumn::make('unit_price')
                    ->label('Unit Price')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 2) : '—')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('line_total')
                    ->label('Line Total')
                    ->getStateUsing(fn ($record) => number_format(($record->unit_price * $record->quantity) / 100, 2))
                    ->alignEnd()
                    ->weight('bold')
                    ->color('success'),
                TextColumn::make('margin')
                    ->label('Margin')
                    ->getStateUsing(fn ($record) => $record->margin > 0 ? number_format($record->margin, 1) . '%' : '—')
                    ->alignCenter()
                    ->color(fn ($record) => match (true) {
                        $record->margin >= 15 => 'success',
                        $record->margin >= 5 => 'warning',
                        $record->margin > 0 => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('incoterm')
                    ->label('Incoterm')
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label('Add Item')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = (int) round(($data['unit_cost'] ?? 0) * 100);
                        $data['unit_price'] = (int) round(($data['unit_price'] ?? 0) * 100);
                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->mountUsing(function ($form, $record) {
                        $data = $record->toArray();
                        $data['unit_cost'] = $data['unit_cost'] / 100;
                        $data['unit_price'] = $data['unit_price'] / 100;
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = (int) round(($data['unit_cost'] ?? 0) * 100);
                        $data['unit_price'] = (int) round(($data['unit_price'] ?? 0) * 100);
                        return $data;
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    protected function fillPricesFromProduct(int $productId, Get $get, Set $set): void
    {
        $product = Product::with(['suppliers', 'clients'])->find($productId);
        if (! $product) {
            return;
        }

        $quotation = $this->getOwnerRecord();
        $clientId = $quotation->company_id;
        $inquiryId = $quotation->inquiry_id;

        if ($inquiryId) {
            $sqItem = SupplierQuotationItem::query()
                ->where('product_id', $productId)
                ->where('unit_cost', '>', 0)
                ->whereHas('supplierQuotation', function ($q) use ($inquiryId) {
                    $q->where('inquiry_id', $inquiryId)
                        ->whereIn('status', [
                            SupplierQuotationStatus::SELECTED,
                            SupplierQuotationStatus::UNDER_ANALYSIS,
                            SupplierQuotationStatus::RECEIVED,
                        ]);
                })
                ->with('supplierQuotation')
                ->first();

            if ($sqItem) {
                $set('supplier_quotation_item_id', $sqItem->id);
                $set('selected_supplier_id', $sqItem->supplierQuotation->company_id);
                $set('unit_cost', $sqItem->unit_cost / 100);

                $clientPivot = $product->clients()
                    ->where('companies.id', $clientId)
                    ->first()
                    ?->pivot;

                if ($clientPivot && $clientPivot->unit_price > 0) {
                    $set('unit_price', $clientPivot->unit_price / 100);
                } else {
                    $this->recalculateUnitPrice($get, $set);
                }

                return;
            }
        }

        $preferredSupplier = $product->suppliers()
            ->orderByDesc('company_product.is_preferred')
            ->first();

        if ($preferredSupplier) {
            $set('selected_supplier_id', $preferredSupplier->id);
            $set('unit_cost', $preferredSupplier->pivot->unit_price / 100);

            if ($preferredSupplier->pivot->incoterm ?? null) {
                $set('incoterm', $preferredSupplier->pivot->incoterm);
            }
        }

        $clientPivot = $product->clients()
            ->where('companies.id', $clientId)
            ->first()
            ?->pivot;

        if ($clientPivot && $clientPivot->unit_price > 0) {
            $set('unit_price', $clientPivot->unit_price / 100);
        } else {
            $this->recalculateUnitPrice($get, $set);
        }
    }

    protected function fillSupplierCost(int $productId, int $supplierId, Get $get, Set $set): void
    {
        $product = Product::find($productId);
        if (! $product) {
            return;
        }

        $supplierPivot = $product->suppliers()
            ->where('companies.id', $supplierId)
            ->first()
            ?->pivot;

        if ($supplierPivot) {
            $set('unit_cost', $supplierPivot->unit_price / 100);
        }

        $quotation = $this->getOwnerRecord();
        $clientPivot = $product->clients()
            ->where('companies.id', $quotation->company_id)
            ->first()
            ?->pivot;

        if (! $clientPivot || $clientPivot->unit_price <= 0) {
            $this->recalculateUnitPrice($get, $set);
        }
    }

    protected function recalculateUnitPrice(Get $get, Set $set): void
    {
        $cost = (float) ($get('unit_cost') ?? 0);
        $quotation = $this->getOwnerRecord();

        $commissionRate = $quotation->commission_type === CommissionType::EMBEDDED
            ? (float) ($get('commission_rate') ?? $quotation->commission_rate ?? 0)
            : 0;

        if ($cost > 0 && $commissionRate > 0) {
            $set('unit_price', round($cost * (1 + ($commissionRate / 100)), 2));
        } elseif ($cost > 0) {
            $set('unit_price', round($cost, 2));
        }
    }
}
