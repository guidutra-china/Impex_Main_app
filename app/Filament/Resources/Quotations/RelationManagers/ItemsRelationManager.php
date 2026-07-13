<?php

namespace App\Filament\Resources\Quotations\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Quotations\Actions\PromoteQuotationItemSupplierAction;
use App\Domain\Quotations\Actions\RefreshQuotationFxRatesAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\QuotationItemSupplier;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry as InfolistTextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section as InfolistSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Quotation Items';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-shopping-cart';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('product_id')
                    ->label(__('forms.labels.product'))
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
                    ->label(__('forms.labels.source_supplier_quotation'))
                    ->options(function (Get $get) {
                        $productId = $get('product_id');
                        if (! $productId) {
                            return [];
                        }

                        $quotation = $this->getOwnerRecord();
                        $inquiryId = $quotation->inquiry_id;

                        $query = SupplierQuotationItem::query()
                            ->where('product_id', $productId)
                            ->where('unit_cost', '>', 0)
                            ->whereHas('supplierQuotation', function ($q) use ($inquiryId) {
                                if ($inquiryId) {
                                    $q->where('inquiry_id', $inquiryId);
                                }
                                $q->whereIn('status', [
                                    SupplierQuotationStatus::RECEIVED,
                                    SupplierQuotationStatus::UNDER_ANALYSIS,
                                    SupplierQuotationStatus::SELECTED,
                                ]);
                            })
                            ->with('supplierQuotation.company');

                        return $query->get()
                            ->mapWithKeys(fn ($sqItem) => [
                                $sqItem->id => "{$sqItem->supplierQuotation->reference} — {$sqItem->supplierQuotation->company->name} — $".Money::format($sqItem->unit_cost, 4),
                            ])
                            ->toArray();
                    })
                    ->getOptionLabelUsing(function ($value) {
                        $sqItem = SupplierQuotationItem::with('supplierQuotation.company')->find($value);
                        if (! $sqItem) {
                            return null;
                        }

                        return "{$sqItem->supplierQuotation->reference} — {$sqItem->supplierQuotation->company->name} — $".Money::format($sqItem->unit_cost, 4);
                    })
                    ->searchable()
                    ->placeholder(__('forms.placeholders.select_supplier_quotation_source'))
                    ->helperText(__('forms.helpers.optional_link_this_item_to_a_specific_supplier_quotation'))
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
                        $set('unit_cost', Money::toMajor($sqItem->unit_cost));
                        $set('cost_currency_code', $sqItem->supplierQuotation->currency_code ?? $this->getOwnerRecord()->currency_code);
                        $this->resolveFxRate($get, $set);

                        if ($sqItem->supplierQuotation->incoterm) {
                            $set('incoterm', $sqItem->supplierQuotation->incoterm);
                        }

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
                            $set('unit_price', Money::toMajor($clientPivot->unit_price));
                        } else {
                            $this->recalculateUnitPrice($get, $set);
                        }
                    }),

                Select::make('selected_supplier_id')
                    ->label(__('forms.labels.selected_supplier'))
                    ->options(function (Get $get) {
                        $productId = $get('product_id');
                        if (! $productId) {
                            return [];
                        }

                        $suppliers = Product::find($productId)
                            ?->suppliers()
                            ->pluck('companies.name', 'companies.id')
                            ->toArray() ?? [];

                        $currentSupplierId = $get('selected_supplier_id');
                        if ($currentSupplierId && ! isset($suppliers[$currentSupplierId])) {
                            $company = Company::find($currentSupplierId);
                            if ($company) {
                                $suppliers[$currentSupplierId] = $company->name;
                            }
                        }

                        return $suppliers;
                    })
                    ->getOptionLabelUsing(function ($value) {
                        return Company::find($value)?->name;
                    })
                    ->searchable()
                    ->placeholder(__('forms.placeholders.select_supplier'))
                    ->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set) {
                        if (! $state || ! $get('product_id')) {
                            return;
                        }

                        $this->fillSupplierCost((int) $get('product_id'), (int) $state, $get, $set);
                    }),

                TextInput::make('quantity')
                    ->label(__('forms.labels.quantity'))
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->default(1),

                TextInput::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix(fn (Get $get) => $get('cost_currency_code') ?: $this->getOwnerRecord()->currency_code ?: '$')
                    ->inputMode('decimal')
                    ->default(0)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $this->recalculateUnitPrice($get, $set);
                    }),

                Select::make('cost_currency_code')
                    ->label(__('forms.labels.cost_currency'))
                    ->options(fn () => Currency::where('is_active', true)->pluck('code', 'code'))
                    ->default(fn () => $this->getOwnerRecord()->currency_code)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $this->resolveFxRate($get, $set);
                        $this->recalculateUnitPrice($get, $set);
                    }),

                TextInput::make('cost_exchange_rate')
                    ->label(__('forms.labels.exchange_rate'))
                    ->numeric()
                    ->step(0.00000001)
                    ->default(1)
                    ->required()
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set) {
                        $set('cost_exchange_rate_captured_at', now()->toDateString());
                        $this->recalculateUnitPrice($get, $set);
                    })
                    ->suffixAction(
                        Action::make('useCurrentFxRate')
                            ->icon('heroicon-m-arrow-path')
                            ->tooltip(__('forms.tooltips.use_current_exchange_rate'))
                            ->action(function (Get $get, Set $set) {
                                $this->resolveFxRate($get, $set);
                                $this->recalculateUnitPrice($get, $set);
                            })
                    )
                    ->helperText(function (Get $get) {
                        $base = __('forms.helpers.cost_currency_to_quote_currency');
                        $capturedAt = $get('cost_exchange_rate_captured_at');
                        if (! $capturedAt) {
                            return $base;
                        }
                        $formatted = \Carbon\Carbon::parse($capturedAt)->format('d/m/Y');

                        return $base.' '.__('forms.helpers.fx_rate_captured_on', ['date' => $formatted]);
                    }),

                Hidden::make('cost_exchange_rate_captured_at'),

                Placeholder::make('converted_cost_preview')
                    ->label(__('forms.labels.cost_in_quote_currency'))
                    ->content(function (Get $get) {
                        $cost = (float) ($get('unit_cost') ?? 0);
                        $rate = (float) ($get('cost_exchange_rate') ?? 1);

                        return $this->getOwnerRecord()->currency_code.' '.number_format($cost * $rate, 4);
                    }),

                TextInput::make('commission_rate')
                    ->label(__('forms.labels.commission_rate_2'))
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
                    ->helperText(__('forms.helpers.commission_embedded_in_the_unit_price')),

                TextInput::make('unit_price')
                    ->label(__('forms.labels.unit_price_to_client'))
                    ->numeric()
                    ->minValue(0)
                    ->step(0.0001)
                    ->prefix(fn () => $this->getOwnerRecord()->currency_code ?: '$')
                    ->inputMode('decimal')
                    ->default(0)
                    ->helperText(__('forms.helpers.autofilled_from_catalog_supplier_quotation_or_calculated')),

                Select::make('incoterm')
                    ->label(__('forms.labels.incoterm'))
                    ->options(Incoterm::class)
                    ->default(fn () => $this->getOwnerRecord()->incoterm)
                    ->placeholder(__('forms.placeholders.select_incoterm')),

                Textarea::make('notes')
                    ->label(__('forms.labels.item_notes'))
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
                \Filament\Tables\Columns\ImageColumn::make('product.avatar')
                    ->label('')
                    ->disk('public')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn () => 'https://ui-avatars.com/api/?background=e2e8f0&color=94a3b8&name=P&size=40'),
                TextColumn::make('product.sku')
                    ->label(__('forms.labels.sku'))
                    ->searchable()
                    ->badge()
                    ->color(fn ($record) => $record->product?->status?->value === 'draft' ? 'warning' : 'gray'),
                TextColumn::make('product.name')
                    ->label(__('forms.labels.product'))
                    ->searchable()
                    ->limit(30)
                    ->weight('bold'),
                TextColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->numeric()
                    ->alignCenter(),
                TextColumn::make('selectedSupplier.name')
                    ->label(__('forms.labels.supplier'))
                    ->placeholder('—')
                    ->limit(20),
                TextColumn::make('supplierQuotationItem.supplierQuotation.reference')
                    ->label(__('forms.labels.sq_source'))
                    ->badge()
                    ->color('info')
                    ->placeholder(__('forms.placeholders.manual'))
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('cost_currency_code')
                    ->label(__('forms.labels.cost_currency_short'))
                    ->alignCenter()
                    ->badge()
                    ->color(fn ($record) => $record->cost_currency_code
                        && $record->quotation?->currency_code
                        && $record->cost_currency_code !== $record->quotation->currency_code
                            ? 'warning'
                            : 'gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->formatStateUsing(fn ($state, $record) => $state
                        ? trim(($record->cost_currency_code ?? $record->quotation?->currency_code ?? '').' '.Money::format($state, 4))
                        : '—')
                    ->tooltip(function ($record) {
                        $quoteCurrency = $record->quotation?->currency_code;
                        if (! $quoteCurrency
                            || ! $record->cost_currency_code
                            || $record->cost_currency_code === $quoteCurrency) {
                            return null;
                        }
                        $tooltip = '≈ '.$quoteCurrency.' '.Money::format($record->converted_unit_cost, 4)
                            .' @ '.number_format((float) $record->cost_exchange_rate, 6);
                        if ($record->cost_exchange_rate_captured_at) {
                            $tooltip .= ' · '.$record->cost_exchange_rate_captured_at->format('d/m/Y');
                        }

                        return $tooltip;
                    })
                    ->alignEnd(),
                TextColumn::make('cost_exchange_rate')
                    ->label(__('forms.labels.fx_rate'))
                    ->getStateUsing(function ($record) {
                        $quoteCurrency = $record->quotation?->currency_code;
                        if (! $record->cost_currency_code
                            || $record->cost_currency_code === $quoteCurrency
                            || $record->cost_exchange_rate === null) {
                            return null;
                        }

                        return number_format((float) $record->cost_exchange_rate, 4);
                    })
                    ->description(fn ($record) => $record->cost_currency_code !== $record->quotation?->currency_code
                        ? $record->cost_exchange_rate_captured_at?->format('d/m/Y')
                        : null)
                    ->placeholder('—')
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('commission_rate')
                    ->label(__('forms.labels.comm'))
                    ->suffix('%')
                    ->alignCenter()
                    ->visible(fn () => $this->getOwnerRecord()->commission_type === CommissionType::EMBEDDED),
                TextColumn::make('unit_price')
                    ->label(__('forms.labels.unit_price'))
                    ->formatStateUsing(fn ($state) => $state
                        ? trim(($this->getOwnerRecord()->currency_code ?? '').' '.Money::format($state, 4))
                        : '—')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('line_total')
                    ->label(__('forms.labels.line_total'))
                    ->getStateUsing(fn ($record) => trim(($this->getOwnerRecord()->currency_code ?? '').' '.Money::format($record->unit_price * $record->quantity)))
                    ->alignEnd()
                    ->weight('bold')
                    ->color('success'),
                TextColumn::make('margin')
                    ->label(__('forms.labels.margin'))
                    ->getStateUsing(fn ($record) => $record->margin > 0 ? number_format($record->margin, 1).'%' : '—')
                    ->alignCenter()
                    ->color(fn ($record) => match (true) {
                        $record->margin >= 15 => 'success',
                        $record->margin >= 5 => 'warning',
                        $record->margin > 0 => 'danger',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('incoterm')
                    ->label(__('forms.labels.incoterm'))
                    ->badge()
                    ->color('info')
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->headerActions([
                CreateAction::make()
                    ->label(__('forms.labels.add_item'))
                    ->visible(fn () => auth()->user()?->can('edit-quotations'))
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = Money::toMinor($data['unit_cost'] ?? 0);
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);

                        return $data;
                    }),
                $this->refreshFxRatesAction(),
            ])
            ->recordActions([
                Action::make('promoteSupplier')
                    ->label(__('forms.labels.make_this_selected'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn ($record) => $this->getOwnerRecord()->status === QuotationStatus::DRAFT
                        && $record->suppliers()->exists())
                    ->schema([
                        Select::make('alternative_id')
                            ->label(__('forms.labels.alternative'))
                            ->options(function ($record) {
                                $quoteCurrency = $record->quotation->currency_code;

                                return $record->suppliers()
                                    ->with('company')
                                    ->get()
                                    ->mapWithKeys(function (QuotationItemSupplier $alt) use ($quoteCurrency) {
                                        $cost = Money::format($alt->unit_cost, 4).' '.$alt->currency_code;
                                        $converted = Money::format($alt->converted_unit_cost).' '.$quoteCurrency;

                                        return [$alt->id => "{$alt->company->name} — {$cost} → {$converted}"];
                                    });
                            })
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->action(function (array $data): void {
                        $alt = QuotationItemSupplier::findOrFail($data['alternative_id']);
                        app(PromoteQuotationItemSupplierAction::class)->execute($alt);
                    }),
                ViewAction::make('alternatives')
                    ->label(__('forms.labels.view_alternatives'))
                    ->icon('heroicon-o-rectangle-stack')
                    ->modalHeading(__('forms.labels.alternatives'))
                    ->visible(fn ($record) => $record->suppliers()->exists())
                    ->infolist(fn (Schema $schema) => $schema
                        ->components([
                            InfolistSection::make(__('forms.labels.alternatives'))
                                ->schema([
                                    RepeatableEntry::make('suppliers')
                                        ->label('')
                                        ->schema([
                                            InfolistTextEntry::make('company.name')
                                                ->label(__('forms.labels.supplier')),
                                            InfolistTextEntry::make('unit_cost')
                                                ->label(__('forms.labels.cost'))
                                                ->formatStateUsing(fn ($state, $record) => Money::format($state, 4).' '.$record->currency_code),
                                            InfolistTextEntry::make('cost_exchange_rate')
                                                ->label(__('forms.labels.fx_rate'))
                                                ->formatStateUsing(function ($state, $record) {
                                                    if (! $state) {
                                                        return '—';
                                                    }
                                                    $rate = number_format((float) $state, 4);
                                                    if ($record->cost_exchange_rate_captured_at) {
                                                        $rate .= ' · '.$record->cost_exchange_rate_captured_at->format('d/m/Y');
                                                    }

                                                    return $rate;
                                                }),
                                            InfolistTextEntry::make('converted_unit_cost')
                                                ->label(__('forms.labels.converted_cost'))
                                                ->formatStateUsing(fn ($state, $record) => Money::format($state).' '.$record->quotationItem->quotation->currency_code),
                                            InfolistTextEntry::make('lead_time_days')
                                                ->label(__('forms.labels.lead_time'))
                                                ->suffix('d')
                                                ->placeholder('—'),
                                        ])
                                        ->columns(5),
                                ]),
                        ])),
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-quotations'))
                    ->mountUsing(function ($form, $record) {
                        $data = $record->toArray();
                        $data['unit_cost'] = Money::toMajor($data['unit_cost']);
                        $data['unit_price'] = Money::toMajor($data['unit_price']);
                        $data['cost_currency_code'] ??= $this->getOwnerRecord()->currency_code;
                        $data['cost_exchange_rate'] ??= 1;
                        $form->fill($data);
                    })
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = Money::toMinor($data['unit_cost'] ?? 0);
                        $data['unit_price'] = Money::toMinor($data['unit_price'] ?? 0);

                        return $data;
                    }),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-quotations')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-quotations')),
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
                $set('unit_cost', Money::toMajor($sqItem->unit_cost));
                $set('cost_currency_code', $sqItem->supplierQuotation->currency_code ?? $quotation->currency_code);
                $this->resolveFxRate($get, $set);

                if ($sqItem->supplierQuotation->incoterm) {
                    $set('incoterm', $sqItem->supplierQuotation->incoterm);
                }

                $clientPivot = $product->clients()
                    ->where('companies.id', $clientId)
                    ->first()
                    ?->pivot;

                if ($clientPivot && $clientPivot->unit_price > 0) {
                    $set('unit_price', Money::toMajor($clientPivot->unit_price));
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
            $set('unit_cost', Money::toMajor($preferredSupplier->pivot->unit_price));
            $set('cost_currency_code', $quotation->currency_code);
            $set('cost_exchange_rate', 1);

            if ($preferredSupplier->pivot->incoterm ?? null) {
                $set('incoterm', $preferredSupplier->pivot->incoterm);
            }
        }

        $clientPivot = $product->clients()
            ->where('companies.id', $clientId)
            ->first()
            ?->pivot;

        if ($clientPivot && $clientPivot->unit_price > 0) {
            $set('unit_price', Money::toMajor($clientPivot->unit_price));
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
            $set('unit_cost', Money::toMajor($supplierPivot->unit_price));
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

    /**
     * Header action: refresh every foreign-currency item with the current
     * Exchange Rates table rate, optionally recalculating client prices.
     */
    protected function refreshFxRatesAction(): Action
    {
        return Action::make('refreshFxRates')
            ->label(__('forms.labels.refresh_fx_rates'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(function () {
                $quotation = $this->getOwnerRecord();

                return auth()->user()?->can('edit-quotations')
                    && $quotation->items()
                        ->whereNotNull('cost_currency_code')
                        ->where('cost_currency_code', '!=', $quotation->currency_code)
                        ->exists();
            })
            ->schema([
                Placeholder::make('fx_rate_preview')
                    ->label(__('forms.labels.fx_rate'))
                    ->content(function () {
                        $quotation = $this->getOwnerRecord();
                        $resolver = app(CurrencyExchangeResolver::class);

                        $lines = $quotation->items()
                            ->whereNotNull('cost_currency_code')
                            ->where('cost_currency_code', '!=', $quotation->currency_code)
                            ->get()
                            ->groupBy('cost_currency_code')
                            ->map(function ($items, $currency) use ($quotation, $resolver) {
                                $current = $items->pluck('cost_exchange_rate')
                                    ->filter()
                                    ->map(fn ($rate) => number_format((float) $rate, 4))
                                    ->unique()
                                    ->implode(' / ') ?: '—';

                                $resolved = $resolver->resolve($currency, $quotation->currency_code, today()->toDateString());
                                $new = $resolved['rate_date'] !== null
                                    ? number_format($resolved['rate'], 4).' ('.\Carbon\Carbon::parse($resolved['rate_date'])->format('d/m/Y').')'
                                    : __('forms.helpers.fx_rate_unavailable');

                                return e("{$currency} → {$quotation->currency_code}: {$current} → {$new}");
                            })
                            ->values()
                            ->implode('<br>');

                        return new \Illuminate\Support\HtmlString($lines);
                    }),
                Checkbox::make('recalculate_prices')
                    ->label(__('forms.labels.recalculate_client_prices'))
                    ->default(true)
                    ->helperText(__('forms.helpers.recalculate_client_prices_help')),
            ])
            ->action(function (array $data) {
                $result = app(RefreshQuotationFxRatesAction::class)->execute(
                    $this->getOwnerRecord(),
                    (bool) ($data['recalculate_prices'] ?? true),
                );

                $notification = Notification::make()
                    ->title(__('messages.fx_rates_updated_title'))
                    ->body(__('messages.fx_rates_updated_body', [
                        'updated' => $result['updated'],
                        'skipped' => $result['skipped'],
                    ]));

                $result['skipped'] > 0 ? $notification->warning() : $notification->success();

                $notification->send();
            });
    }

    /**
     * Resolve the current Exchange Rates table rate from the selected cost
     * currency to the quotation currency and freeze it on the form state.
     */
    protected function resolveFxRate(Get $get, Set $set): void
    {
        $quotation = $this->getOwnerRecord();

        $resolved = app(CurrencyExchangeResolver::class)->resolve(
            $get('cost_currency_code'),
            $quotation->currency_code,
            today()->toDateString(),
        );

        $set('cost_exchange_rate', $resolved['rate']);
        $set('cost_exchange_rate_captured_at', $resolved['rate_date'] ?? now()->toDateString());
    }

    protected function recalculateUnitPrice(Get $get, Set $set): void
    {
        $cost = (float) ($get('unit_cost') ?? 0);
        $quotation = $this->getOwnerRecord();

        $costCurrency = $get('cost_currency_code') ?: $quotation->currency_code;
        if ($costCurrency !== $quotation->currency_code) {
            $cost *= (float) ($get('cost_exchange_rate') ?? 1);
        }

        $commissionRate = $quotation->commission_type === CommissionType::EMBEDDED
            ? (float) ($get('commission_rate') ?? $quotation->commission_rate ?? 0)
            : 0;

        if ($cost > 0 && $commissionRate > 0) {
            $set('unit_price', round($cost * (1 + ($commissionRate / 100)), 4));
        } elseif ($cost > 0) {
            $set('unit_price', round($cost, 4));
        }
    }
}
