<?php

namespace App\Filament\Resources\SupplierQuotations\RelationManagers;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Quotation Items';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-queue-list';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.product'))
                    ->schema([
                        Select::make('product_id')
                            ->label(__('forms.labels.product'))
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
                                            ->orWhere('sku', 'like', "%{$search}%");
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

                                        $supplierQuotation = $this->getOwnerRecord();
                                        $pivot = $product->companies()
                                            ->where('company_id', $supplierQuotation->company_id)
                                            ->where('role', 'supplier')
                                            ->first();

                                        if ($pivot && $pivot->pivot->unit_price) {
                                            $majorValue = Money::toMajor($pivot->pivot->unit_price);
                                            $set('unit_cost', number_format($majorValue, 4, '.', ''));
                                            static::recalculateTotal($set, fn ($key) => match ($key) {
                                                'quantity' => 1,
                                                'unit_cost' => number_format($majorValue, 4, '.', ''),
                                                default => null,
                                            });
                                        }
                                    }
                                }
                            })
                            ->helperText(__('forms.helpers.search_by_name_or_sku_price_autofills_from_supplier_catalog'))
                            ->columnSpanFull(),
                        TextInput::make('description')
                            ->label(__('forms.labels.description'))
                            ->maxLength(500)
                            ->helperText(__('forms.helpers.item_description_as_provided_by_the_supplier'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make(__('forms.sections.pricing'))
                    ->schema([
                        TextInput::make('quantity')
                            ->label(__('forms.labels.quantity'))
                            ->numeric()
                            ->minValue(1)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                static::recalculateTotal($set, $get);
                            }),
                        TextInput::make('unit')
                            ->label(__('forms.labels.unit'))
                            ->default('pcs')
                            ->maxLength(20)
                            ->required(),
                        TextInput::make('unit_cost')
                            ->label(__('forms.labels.unit_cost'))
                            ->numeric()
                            ->minValue(0)
                            ->step(0.0001)
                            ->prefix('$')
                            ->required()
                            ->default(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                static::recalculateTotal($set, $get);
                            })
                            ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : '0.0000')
                            ->dehydrateStateUsing(fn ($state) => Money::toMinor($state)),
                        TextInput::make('total_cost')
                            ->label(__('forms.labels.total_cost'))
                            ->numeric()
                            ->prefix('$')
                            ->disabled()
                            ->dehydrated()
                            ->formatStateUsing(fn ($state) => $state ? number_format(Money::toMajor($state), 4, '.', '') : '0.0000')
                            ->dehydrateStateUsing(fn ($state) => Money::toMinor($state)),
                    ])
                    ->columns(4)
                    ->columnSpanFull(),

                Section::make(__('forms.sections.additional_info'))
                    ->schema([
                        TextInput::make('moq')
                            ->label(__('forms.labels.item_moq'))
                            ->numeric()
                            ->minValue(0)
                            ->helperText(__('forms.helpers.itemspecific_moq_if_different_from_header')),
                        TextInput::make('lead_time_days')
                            ->label(__('forms.labels.item_lead_time_days'))
                            ->numeric()
                            ->minValue(0)
                            ->helperText(__('forms.helpers.itemspecific_lead_time_if_different_from_header')),
                        Textarea::make('specifications')
                            ->label(__('forms.labels.specifications'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->helperText(__('forms.helpers.supplierprovided_specs_certifications_materials_etc'))
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('forms.labels.notes'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->collapsed()
                    ->columnSpanFull(),
            ]);
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
                TextColumn::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->formatStateUsing(fn ($state) => $state ? '$ ' . Money::format($state, 4) : '—')
                    ->alignEnd(),
                TextColumn::make('total_cost')
                    ->label(__('forms.labels.total'))
                    ->formatStateUsing(fn ($state) => $state ? '$ ' . Money::format($state) : '—')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('lead_time_days')
                    ->label(__('forms.labels.lead_time'))
                    ->suffix(' d')
                    ->alignCenter()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('moq')
                    ->label(__('forms.labels.moq'))
                    ->alignCenter()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
                ]),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->emptyStateHeading('No items')
            ->emptyStateDescription('Add items manually or use "Import Inquiry Items" button above to copy items from the linked inquiry.');
    }

    protected static function recalculateTotal(Set $set, Get|callable $get): void
    {
        $quantity = (int) ($get('quantity') ?? 0);
        $unitCost = (float) ($get('unit_cost') ?? 0);
        $total = $quantity * $unitCost;
        $set('total_cost', number_format($total, 4, '.', ''));
    }
}
