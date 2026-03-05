<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\View;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;

class PasteItemsFromSpreadsheetAction
{
    /**
     * Create a "Paste from Excel" action for Inquiry Items.
     *
     * Expected columns (tab-separated): Product Name | Quantity | Unit | Target Price | Specifications | Notes
     * Only Product Name is required. Other columns are optional.
     */
    public static function forInquiryItems(): Action
    {
        return Action::make('pasteFromSpreadsheet')
            ->label('Paste from Excel')
            ->icon('heroicon-o-clipboard-document')
            ->color('info')
            ->visible(fn () => auth()->user()?->can('edit-inquiries'))
            ->modalHeading('Paste Items from Spreadsheet')
            ->modalDescription(
                'Copy rows from Excel or Google Sheets and paste them below. ' .
                'Expected columns (tab-separated): **Product Name | Quantity | Unit | Target Price | Specifications | Notes**. ' .
                'Only Product Name is required. Empty columns will use defaults (Qty: 1, Unit: pcs).'
            )
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Import Items')
            ->form([
                Textarea::make('pasted_data')
                    ->label('Paste your data here')
                    ->placeholder(
                        "LED Bulb 9W\t100\tpcs\t1.50\tCE certified, 6500K\tUrgent\n" .
                        "USB Cable Type-C\t500\tpcs\t0.80\t1m length\t\n" .
                        "Power Adapter 5V\t200\tpcs\t\t\tCheck voltage"
                    )
                    ->rows(12)
                    ->required()
                    ->helperText('Tip: Select rows in Excel → Ctrl+C → Click here → Ctrl+V. Headers row is auto-detected and skipped.'),
            ])
            ->action(function (array $data, $livewire) {
                $rawData = $data['pasted_data'] ?? '';

                if (empty(trim($rawData))) {
                    Notification::make()
                        ->title('No data to import')
                        ->warning()
                        ->send();

                    return;
                }

                $lines = array_filter(
                    explode("\n", $rawData),
                    fn ($line) => ! empty(trim($line))
                );

                if (empty($lines)) {
                    Notification::make()
                        ->title('No valid rows found')
                        ->warning()
                        ->send();

                    return;
                }

                // Detect and skip header row
                $firstLine = strtolower(trim($lines[0]));
                $headerKeywords = ['product', 'name', 'item', 'description', 'quantity', 'qty', 'price', 'unit'];
                $isHeader = false;
                foreach ($headerKeywords as $keyword) {
                    if (str_contains($firstLine, $keyword)) {
                        $isHeader = true;
                        break;
                    }
                }

                if ($isHeader) {
                    array_shift($lines);
                }

                $inquiry = $livewire->getOwnerRecord();
                $created = 0;
                $errors = [];
                $maxSort = $inquiry->items()->max('sort_order') ?? 0;

                foreach ($lines as $index => $line) {
                    $columns = explode("\t", $line);
                    $rowNum = $index + ($isHeader ? 2 : 1);

                    $productName = trim($columns[0] ?? '');
                    if (empty($productName)) {
                        $errors[] = "Row {$rowNum}: Empty product name, skipped.";
                        continue;
                    }

                    $quantity = (int) trim($columns[1] ?? '1');
                    if ($quantity < 1) {
                        $quantity = 1;
                    }

                    $unit = trim($columns[2] ?? 'pcs');
                    if (empty($unit)) {
                        $unit = 'pcs';
                    }

                    $targetPriceRaw = trim($columns[3] ?? '');
                    $targetPrice = null;
                    if (! empty($targetPriceRaw) && is_numeric($targetPriceRaw)) {
                        $targetPrice = Money::toMinor((float) $targetPriceRaw);
                    }

                    $specifications = trim($columns[4] ?? '');
                    $notes = trim($columns[5] ?? '');

                    // Try to match an existing product by name or SKU
                    $product = Product::where('name', 'like', "%{$productName}%")
                        ->orWhere('sku', 'like', "%{$productName}%")
                        ->first();

                    $productId = $product?->id;
                    $description = $product ? $product->name : $productName;

                    $maxSort++;

                    $inquiry->items()->create([
                        'product_id' => $productId,
                        'description' => $description,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'target_price' => $targetPrice,
                        'specifications' => $specifications ?: null,
                        'notes' => $notes ?: null,
                        'sort_order' => $maxSort,
                    ]);

                    $created++;
                }

                $message = "{$created} items imported successfully.";
                if (! empty($errors)) {
                    $message .= ' ' . count($errors) . ' rows had issues.';
                }

                Notification::make()
                    ->title($message)
                    ->body(! empty($errors) ? implode("\n", array_slice($errors, 0, 5)) : null)
                    ->success()
                    ->send();
            });
    }

    /**
     * Create a "Paste from Excel" action for Supplier Quotation Items.
     *
     * Expected columns (tab-separated): Product Name/SKU | Quantity | Unit | Unit Cost | MOQ | Lead Time (days) | Specifications | Notes
     * Only Product Name/SKU is required. Other columns are optional.
     */
    public static function forSupplierQuotationItems(): Action
    {
        return Action::make('pasteFromSpreadsheet')
            ->label('Paste from Excel')
            ->icon('heroicon-o-clipboard-document')
            ->color('info')
            ->visible(fn () => auth()->user()?->can('edit-supplier-quotations'))
            ->modalHeading('Paste Items from Spreadsheet')
            ->modalDescription(
                'Copy rows from Excel or Google Sheets and paste them below. ' .
                'Expected columns (tab-separated): **Product Name/SKU | Quantity | Unit | Unit Cost | MOQ | Lead Time (days) | Specifications | Notes**. ' .
                'Only Product Name/SKU is required. Empty columns will use defaults (Qty: 1, Unit: pcs, Cost: 0).'
            )
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Import Items')
            ->form([
                Textarea::make('pasted_data')
                    ->label('Paste your data here')
                    ->placeholder(
                        "LED-001\t100\tpcs\t1.50\t500\t30\tCE certified\tFOB Shenzhen\n" .
                        "USB Cable Type-C\t500\tpcs\t0.80\t1000\t15\t1m length\t\n" .
                        "PWR-5V-2A\t200\tpcs\t2.30\t\t\t\tCheck voltage"
                    )
                    ->rows(12)
                    ->required()
                    ->helperText('Tip: Select rows in Excel → Ctrl+C → Click here → Ctrl+V. Headers row is auto-detected and skipped.'),
            ])
            ->action(function (array $data, $livewire) {
                $rawData = $data['pasted_data'] ?? '';

                if (empty(trim($rawData))) {
                    Notification::make()
                        ->title('No data to import')
                        ->warning()
                        ->send();

                    return;
                }

                $lines = array_filter(
                    explode("\n", $rawData),
                    fn ($line) => ! empty(trim($line))
                );

                if (empty($lines)) {
                    Notification::make()
                        ->title('No valid rows found')
                        ->warning()
                        ->send();

                    return;
                }

                // Detect and skip header row
                $firstLine = strtolower(trim($lines[0]));
                $headerKeywords = ['product', 'name', 'item', 'sku', 'description', 'quantity', 'qty', 'price', 'cost', 'unit'];
                $isHeader = false;
                foreach ($headerKeywords as $keyword) {
                    if (str_contains($firstLine, $keyword)) {
                        $isHeader = true;
                        break;
                    }
                }

                if ($isHeader) {
                    array_shift($lines);
                }

                $supplierQuotation = $livewire->getOwnerRecord();
                $created = 0;
                $errors = [];
                $maxSort = $supplierQuotation->items()->max('sort_order') ?? 0;

                foreach ($lines as $index => $line) {
                    $columns = explode("\t", $line);
                    $rowNum = $index + ($isHeader ? 2 : 1);

                    $productNameOrSku = trim($columns[0] ?? '');
                    if (empty($productNameOrSku)) {
                        $errors[] = "Row {$rowNum}: Empty product name/SKU, skipped.";
                        continue;
                    }

                    $quantity = (int) trim($columns[1] ?? '1');
                    if ($quantity < 1) {
                        $quantity = 1;
                    }

                    $unit = trim($columns[2] ?? 'pcs');
                    if (empty($unit)) {
                        $unit = 'pcs';
                    }

                    $unitCostRaw = trim($columns[3] ?? '0');
                    $unitCost = is_numeric($unitCostRaw) ? Money::toMinor((float) $unitCostRaw) : 0;

                    $moqRaw = trim($columns[4] ?? '');
                    $moq = (! empty($moqRaw) && is_numeric($moqRaw)) ? (int) $moqRaw : null;

                    $leadTimeRaw = trim($columns[5] ?? '');
                    $leadTimeDays = (! empty($leadTimeRaw) && is_numeric($leadTimeRaw)) ? (int) $leadTimeRaw : null;

                    $specifications = trim($columns[6] ?? '');
                    $notes = trim($columns[7] ?? '');

                    // Try to match an existing product by SKU first, then by name
                    $product = Product::where('sku', $productNameOrSku)->first()
                        ?? Product::where('name', 'like', "%{$productNameOrSku}%")->first();

                    $productId = $product?->id;
                    $description = $product ? $product->name : $productNameOrSku;

                    $totalCost = $quantity * $unitCost;
                    $maxSort++;

                    $supplierQuotation->items()->create([
                        'product_id' => $productId,
                        'description' => $description,
                        'quantity' => $quantity,
                        'unit' => $unit,
                        'unit_cost' => $unitCost,
                        'total_cost' => $totalCost,
                        'moq' => $moq,
                        'lead_time_days' => $leadTimeDays,
                        'specifications' => $specifications ?: null,
                        'notes' => $notes ?: null,
                        'sort_order' => $maxSort,
                    ]);

                    $created++;
                }

                $message = "{$created} items imported successfully.";
                if (! empty($errors)) {
                    $message .= ' ' . count($errors) . ' rows had issues.';
                }

                Notification::make()
                    ->title($message)
                    ->body(! empty($errors) ? implode("\n", array_slice($errors, 0, 5)) : null)
                    ->success()
                    ->send();
            });
    }
}
