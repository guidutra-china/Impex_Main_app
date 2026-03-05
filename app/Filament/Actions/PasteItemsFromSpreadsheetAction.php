<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class PasteItemsFromSpreadsheetAction
{
    /**
     * Create a "Paste from Excel" action for Inquiry Items with Wizard.
     */
    public static function forInquiryItems(): Action
    {
        return Action::make('pasteFromSpreadsheet')
            ->label('Paste from Excel')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('info')
            ->modalWidth('6xl')
            ->modalHeading('Import Inquiry Items from Spreadsheet')
            ->visible(fn () => auth()->user()?->can('edit-inquiries'))
            ->steps([
                Step::make('Paste')
                    ->label('Paste Data')
                    ->description('Paste tab-separated data from Excel')
                    ->schema([
                        Textarea::make('raw_data')
                            ->label('Paste Data')
                            ->placeholder("Product Name\tQty\tUnit\tTarget Price\tSpecs\tNotes")
                            ->rows(10)
                            ->helperText('Copy rows from Excel (including headers or not) and paste them here. Columns must be: Product Name | Qty | Unit | Target Price | Specs | Notes.')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                if (empty($state)) return;
                                
                                $lines = explode("\n", trim($state));
                                $items = [];
                                
                                foreach ($lines as $line) {
                                    $cols = explode("\t", $line);
                                    if (count($cols) < 1 || empty(trim($cols[0]))) continue;
                                    
                                    // Skip header if detected
                                    $firstCol = strtolower(trim($cols[0]));
                                    if ($firstCol === 'product name' || $firstCol === 'item' || $firstCol === 'product') continue;

                                    $items[] = [
                                        'product_name' => trim($cols[0]),
                                        'quantity' => trim($cols[1] ?? '1'),
                                        'unit' => trim($cols[2] ?? 'pcs'),
                                        'price' => trim($cols[3] ?? '0'),
                                        'specs' => trim($cols[4] ?? ''),
                                        'notes' => trim($cols[5] ?? ''),
                                    ];
                                }
                                
                                $set('items', $items);
                            }),
                    ]),
                Step::make('Preview & Edit')
                    ->label('Preview & Edit')
                    ->description('Review and correct items before importing')
                    ->schema([
                        Repeater::make('items')
                            ->schema([
                                TextInput::make('product_name')->label('Product Name')->required()->columnSpan(2),
                                TextInput::make('quantity')->label('Qty')->numeric()->required(),
                                TextInput::make('unit')->label('Unit')->required(),
                                TextInput::make('price')->label('Target Price')->numeric(),
                                TextInput::make('specs')->label('Specifications')->columnSpan(2),
                                TextInput::make('notes')->label('Notes')->columnSpan(2),
                            ])
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => $state['product_name'] ?? null)
                            ->collapsible()
                            ->defaultItems(0)
                            ->reorderable(false),
                    ]),
                Step::make('Confirm')
                    ->label('Confirm')
                    ->description('Finalize import')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('summary')
                            ->content(fn (Get $get) => 'You are about to import ' . count($get('items') ?? []) . ' items into this inquiry.'),
                    ]),
            ])
            ->action(function (array $data, $livewire): void {
                $items = $data['items'] ?? [];
                $inquiry = $livewire->getOwnerRecord();
                
                if (empty($items)) {
                    Notification::make()->title('No items to import')->warning()->send();
                    return;
                }

                try {
                    DB::transaction(function () use ($items, $inquiry) {
                        $maxSort = $inquiry->items()->max('sort_order') ?? 0;
                        
                        foreach ($items as $item) {
                            $maxSort++;
                            $productName = $item['product_name'];
                            
                            // Try to match an existing product
                            $product = Product::where('name', 'like', "%{$productName}%")
                                ->orWhere('sku', 'like', "%{$productName}%")
                                ->first();

                            $inquiry->items()->create([
                                'product_id' => $product?->id,
                                'description' => $product ? $product->name : $productName,
                                'quantity' => (float) ($item['quantity'] ?? 1),
                                'unit' => $item['unit'] ?? 'pcs',
                                'target_price' => !empty($item['price']) ? Money::toMinor((float) $item['price']) : null,
                                'specifications' => $item['specs'] ?: null,
                                'notes' => $item['notes'] ?: null,
                                'sort_order' => $maxSort,
                            ]);
                        }
                    });

                    Notification::make()
                        ->title(count($items) . ' items imported successfully')
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('Error importing items')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Create a "Paste from Excel" action for Supplier Quotation Items with Wizard.
     */
    public static function forSupplierQuotationItems(): Action
    {
        return Action::make('pasteFromSpreadsheet')
            ->label('Paste from Excel')
            ->icon('heroicon-o-clipboard-document-check')
            ->color('info')
            ->modalWidth('6xl')
            ->modalHeading('Import Supplier Quotation Items from Spreadsheet')
            ->visible(fn () => auth()->user()?->can('edit-supplier-quotations'))
            ->steps([
                Step::make('Paste')
                    ->label('Paste Data')
                    ->description('Paste tab-separated data from Excel')
                    ->schema([
                        Textarea::make('raw_data')
                            ->label('Paste Data')
                            ->placeholder("Product/SKU\tQty\tUnit\tUnit Cost\tMOQ\tLead Time\tSpecs\tNotes")
                            ->rows(10)
                            ->helperText('Copy rows from Excel and paste them here. Columns must be: Product/SKU | Qty | Unit | Unit Cost | MOQ | Lead Time | Specs | Notes.')
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set) {
                                if (empty($state)) return;
                                
                                $lines = explode("\n", trim($state));
                                $items = [];
                                
                                foreach ($lines as $line) {
                                    $cols = explode("\t", $line);
                                    if (count($cols) < 1 || empty(trim($cols[0]))) continue;
                                    
                                    $firstCol = strtolower(trim($cols[0]));
                                    if ($firstCol === 'product' || $firstCol === 'sku' || $firstCol === 'item') continue;

                                    $items[] = [
                                        'product_name' => trim($cols[0]),
                                        'quantity' => trim($cols[1] ?? '1'),
                                        'unit' => trim($cols[2] ?? 'pcs'),
                                        'price' => trim($cols[3] ?? '0'),
                                        'moq' => trim($cols[4] ?? '1'),
                                        'lead_time' => trim($cols[5] ?? '0'),
                                        'specs' => trim($cols[6] ?? ''),
                                        'notes' => trim($cols[7] ?? ''),
                                    ];
                                }
                                
                                $set('items', $items);
                            }),
                    ]),
                Step::make('Preview & Edit')
                    ->label('Preview & Edit')
                    ->description('Review and correct items before importing')
                    ->schema([
                        Repeater::make('items')
                            ->schema([
                                TextInput::make('product_name')->label('Product/SKU')->required()->columnSpan(2),
                                TextInput::make('quantity')->label('Qty')->numeric()->required(),
                                TextInput::make('unit')->label('Unit')->required(),
                                TextInput::make('price')->label('Unit Cost')->numeric(),
                                TextInput::make('moq')->label('MOQ')->numeric(),
                                TextInput::make('lead_time')->label('Lead Time (days)')->numeric(),
                                TextInput::make('specs')->label('Specifications')->columnSpan(2),
                                TextInput::make('notes')->label('Notes')->columnSpan(2),
                            ])
                            ->columns(4)
                            ->itemLabel(fn (array $state): ?string => $state['product_name'] ?? null)
                            ->collapsible()
                            ->defaultItems(0)
                            ->reorderable(false),
                    ]),
                Step::make('Confirm')
                    ->label('Confirm')
                    ->description('Finalize import')
                    ->schema([
                        \Filament\Forms\Components\Placeholder::make('summary')
                            ->content(fn (Get $get) => 'You are about to import ' . count($get('items') ?? []) . ' items into this supplier quotation.'),
                    ]),
            ])
            ->action(function (array $data, $livewire): void {
                $items = $data['items'] ?? [];
                $sq = $livewire->getOwnerRecord();
                
                if (empty($items)) {
                    Notification::make()->title('No items to import')->warning()->send();
                    return;
                }

                try {
                    DB::transaction(function () use ($items, $sq) {
                        $maxSort = $sq->items()->max('sort_order') ?? 0;
                        
                        foreach ($items as $item) {
                            $maxSort++;
                            $productNameOrSku = $item['product_name'];
                            
                            // Try to match an existing product
                            $product = Product::where('sku', $productNameOrSku)->first()
                                ?? Product::where('name', 'like', "%{$productNameOrSku}%")->first();

                            $unitCost = !empty($item['price']) ? Money::toMinor((float) $item['price']) : 0;
                            $quantity = (float) ($item['quantity'] ?? 1);

                            $sq->items()->create([
                                'product_id' => $product?->id,
                                'description' => $product ? $product->name : $productNameOrSku,
                                'quantity' => $quantity,
                                'unit' => $item['unit'] ?? 'pcs',
                                'unit_cost' => $unitCost,
                                'total_cost' => $quantity * $unitCost,
                                'moq' => !empty($item['moq']) ? (float) $item['moq'] : null,
                                'lead_time_days' => !empty($item['lead_time']) ? (int) $item['lead_time'] : null,
                                'specifications' => $item['specs'] ?: null,
                                'notes' => $item['notes'] ?: null,
                                'sort_order' => $maxSort,
                            ]);
                        }
                    });

                    Notification::make()
                        ->title(count($items) . ' items imported successfully')
                        ->success()
                        ->send();
                } catch (\Exception $e) {
                    Notification::make()
                        ->title('Error importing items')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
