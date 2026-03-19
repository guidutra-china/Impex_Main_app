<?php

namespace App\Filament\Resources\SupplierQuotations\RelationManagers;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Filament\Actions\PasteItemsFromSpreadsheetAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
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
use Filament\Tables\Columns\TextInputColumn;
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

                // --- Inline editable columns ---
                TextInputColumn::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->rules(['required', 'integer', 'min:1'])
                    ->updateStateUsing(function ($record, $state) {
                        $record->quantity = (int) $state;
                        $record->save(); // model's saving() hook recalculates total_cost
                        return $state;
                    })
                    ->alignCenter(),
                TextInputColumn::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->rules(['required', 'max:20'])
                    ->alignCenter(),
                TextInputColumn::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->type('number')
                    ->inputMode('decimal')
                    ->step('0.0001')
                    ->prefix('$')
                    ->rules(['required', 'numeric', 'min:0'])
                    // Display: convert minor units (DB) → major units for the input field.
                    ->getStateUsing(fn ($record) => number_format(Money::toMajor($record->unit_cost ?? 0), 4, '.', ''))
                    // Save: convert major units (user input) → minor units (DB).
                    // updateStateUsing REPLACES Filament's default save entirely,
                    // so the raw string never touches $record->setAttribute().
                    ->updateStateUsing(function ($record, $state) {
                        $floatValue = (float) str_replace(',', '', (string) $state);
                        $record->unit_cost = Money::toMinor($floatValue);
                        $record->save(); // model's saving() hook recalculates total_cost
                        return number_format($floatValue, 4, '.', ''); // return display value
                    })
                    ->alignEnd(),
                TextColumn::make('total_cost')
                    ->label(__('forms.labels.total'))
                    ->formatStateUsing(fn ($state) => $state ? '$ ' . Money::format($state) : '—')
                    ->alignEnd()
                    ->weight('bold'),
                TextInputColumn::make('moq')
                    ->label(__('forms.labels.moq'))
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->alignCenter(),
                TextInputColumn::make('lead_time_days')
                    ->label(__('forms.labels.lead_time'))
                    ->type('number')
                    ->inputMode('numeric')
                    ->step('1')
                    ->rules(['nullable', 'integer', 'min:0'])
                    ->suffix(' d')
                    ->alignCenter(),
                TextInputColumn::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->rules(['nullable', 'max:1000']),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-supplier-quotations')),
                Action::make('importInquiryItems')
                    ->label(__('forms.labels.import_inquiry_items'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->visible(fn () => $this->getOwnerRecord()->inquiry_id
                        && $this->getOwnerRecord()->items()->count() === 0
                        && auth()->user()?->can('edit-supplier-quotations'))
                    ->requiresConfirmation()
                    ->modalHeading('Import Items from Inquiry')
                    ->modalDescription('This will copy all items from the linked inquiry into this supplier quotation.')
                    ->action(function () {
                        try {
                            $record = $this->getOwnerRecord();
                            $inquiry = $record->inquiry;
                            if (! $inquiry) {
                                throw new \RuntimeException('No inquiry linked.');
                            }

                            $count = 0;
                            DB::transaction(function () use ($record, $inquiry, &$count) {
                                foreach ($inquiry->items as $item) {
                                    SupplierQuotationItem::create([
                                        'supplier_quotation_id' => $record->id,
                                        'inquiry_item_id' => $item->id,
                                        'product_id' => $item->product_id,
                                        'description' => $item->description,
                                        'quantity' => $item->quantity,
                                        'unit' => $item->unit,
                                        'unit_cost' => 0,
                                        'specifications' => $item->specifications,
                                        'notes' => $item->notes,
                                        'sort_order' => $item->sort_order,
                                    ]);
                                    $count++;
                                }
                            });

                            Notification::make()
                                ->title("{$count} items imported from inquiry")
                                ->body($inquiry->reference)
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title(__('messages.import_failed'))
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                PasteItemsFromSpreadsheetAction::forSupplierQuotationItems(),
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
