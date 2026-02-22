<?php

namespace App\Filament\Resources\ProformaInvoices\RelationManagers;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\Incoterm;
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
                ->label('Product')
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
                ->label('Supplier')
                ->options(
                    fn () => Company::query()
                        ->whereHas('companyRoles', fn ($q) => $q->where('role', CompanyRole::SUPPLIER))
                        ->orderBy('name')
                        ->pluck('name', 'id')
                )
                ->searchable()
                ->helperText('Which supplier provides this item.'),

            TextInput::make('description')
                ->label('Description')
                ->maxLength(255),

            Textarea::make('specifications')
                ->label('Specifications')
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('quantity')
                ->label('Quantity')
                ->numeric()
                ->required()
                ->minValue(1)
                ->default(1),

            TextInput::make('unit')
                ->label('Unit')
                ->default('pcs')
                ->maxLength(20),

            TextInput::make('unit_price')
                ->label('Unit Price (Client)')
                ->numeric()
                ->required()
                ->prefix('$')
                ->step(0.01)
                ->minValue(0),

            TextInput::make('unit_cost')
                ->label('Unit Cost (Internal)')
                ->numeric()
                ->prefix('$')
                ->step(0.01)
                ->minValue(0)
                ->default(0),

            Select::make('incoterm')
                ->label('Incoterm')
                ->options(Incoterm::class)
                ->searchable(),

            Textarea::make('notes')
                ->label('Notes')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('product.name')
                    ->label('Product')
                    ->searchable()
                    ->limit(30)
                    ->placeholder('Manual item'),
                TextColumn::make('description')
                    ->label('Description')
                    ->limit(40)
                    ->toggleable(),
                TextColumn::make('supplierCompany.name')
                    ->label('Supplier')
                    ->limit(20)
                    ->placeholder('—'),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->alignCenter(),
                TextColumn::make('unit')
                    ->label('Unit')
                    ->alignCenter(),
                TextColumn::make('unit_price')
                    ->label('Price')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('unit_cost')
                    ->label('Cost')
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                    ->prefix('$ ')
                    ->alignEnd(),
                TextColumn::make('line_total')
                    ->label('Total')
                    ->getStateUsing(fn ($record) => $record->line_total)
                    ->formatStateUsing(fn ($state) => number_format($state / 100, 2))
                    ->prefix('$ ')
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('margin')
                    ->label('Margin')
                    ->getStateUsing(fn ($record) => $record->margin)
                    ->suffix('%')
                    ->alignCenter()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['unit_cost'] = (int) round(($data['unit_cost'] ?? 0) * 100);
                        $data['unit_price'] = (int) round(($data['unit_price'] ?? 0) * 100);
                        $data['sort_order'] = $this->getOwnerRecord()->items()->max('sort_order') + 1;

                        return $data;
                    }),
                $this->importFromQuotationsAction(),
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
            ])
            ->reorderable('sort_order');
    }

    protected function importFromQuotationsAction(): Action
    {
        return Action::make('importFromQuotations')
            ->label('Import from Quotations')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('info')
            ->form([
                Select::make('quotation_id')
                    ->label('Quotation')
                    ->options(function () {
                        $pi = $this->getOwnerRecord();

                        return Quotation::query()
                            ->where('inquiry_id', $pi->inquiry_id)
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn ($q) => [
                                $q->id => $q->reference . ' — ' . $q->company?->name . ' (' . $q->status->getLabel() . ')',
                            ]);
                    })
                    ->searchable()
                    ->required()
                    ->live()
                    ->helperText('Only quotations from the same inquiry are shown.'),

                Select::make('item_ids')
                    ->label('Items to Import')
                    ->multiple()
                    ->options(function (Get $get) {
                        $quotationId = $get('quotation_id');
                        if (! $quotationId) {
                            return [];
                        }

                        return QuotationItem::where('quotation_id', $quotationId)
                            ->with('product')
                            ->get()
                            ->mapWithKeys(fn ($item) => [
                                $item->id => ($item->product?->name ?? 'Item #' . $item->id)
                                    . ' — Qty: ' . $item->quantity
                                    . ' — $' . number_format($item->unit_price / 100, 2),
                            ]);
                    })
                    ->required()
                    ->helperText('Select which items to import into this proforma invoice.'),
            ])
            ->action(function (array $data) {
                $pi = $this->getOwnerRecord();
                $quotationId = $data['quotation_id'];
                $itemIds = $data['item_ids'];

                $quotation = Quotation::find($quotationId);
                $items = QuotationItem::whereIn('id', $itemIds)
                    ->where('quotation_id', $quotationId)
                    ->with(['product', 'product.suppliers'])
                    ->get();

                $maxSort = $pi->items()->max('sort_order') ?? 0;
                $imported = 0;

                foreach ($items as $item) {
                    $supplierId = $item->selected_supplier_id;

                    if (! $supplierId && $item->product) {
                        $preferred = $item->product->suppliers()
                            ->orderByDesc('company_product.is_preferred')
                            ->first();
                        $supplierId = $preferred?->id;
                    }

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

                    $imported++;
                }

                $pi->quotations()->syncWithoutDetaching([$quotationId]);

                Notification::make()
                    ->title($imported . ' items imported')
                    ->body('Items imported from ' . $quotation->reference . '.')
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
            $set('unit_cost', $preferred->pivot->unit_price / 100);

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
            $set('unit_price', $clientPivot->unit_price / 100);
        }
    }
}
