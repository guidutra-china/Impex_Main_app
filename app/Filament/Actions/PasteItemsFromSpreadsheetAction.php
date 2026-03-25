<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;

class PasteItemsFromSpreadsheetAction
{
    /**
     * Read a spreadsheet file (.xlsx or .xls) and return all rows as arrays.
     */
    protected static function readSpreadsheet(string $path): array
    {
        ini_set('memory_limit', '512M');

        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        $rawData = $worksheet->toArray(null, true, false, false);

        // Free memory immediately
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $worksheet);

        $rows = [];
        $maxRows = min(count($rawData), 5000);
        for ($r = 0; $r < $maxRows; $r++) {
            $values = array_map(fn ($v) => trim((string) ($v ?? '')), $rawData[$r]);

            if (implode('', $values) === '') {
                continue;
            }

            $rows[] = $values;
        }

        unset($rawData);

        return $rows;
    }

    /**
     * Resolve the uploaded file to an absolute path.
     * Handles Livewire TemporaryUploadedFile objects, serialized file references, and stored file paths.
     */
    protected static function resolveUploadPath(mixed $filePath): ?string
    {
        if (empty($filePath)) {
            return null;
        }

        // Handle TemporaryUploadedFile object directly
        if ($filePath instanceof TemporaryUploadedFile) {
            $realPath = $filePath->getRealPath();
            if ($realPath && file_exists($realPath)) {
                return $realPath;
            }

            return null;
        }

        // Handle array state from Filament FileUpload: {uuid: TemporaryUploadedFile} or {uuid: string}
        if (is_array($filePath)) {
            foreach ($filePath as $value) {
                // Value may be a TemporaryUploadedFile object (during Livewire request lifecycle)
                if ($value instanceof TemporaryUploadedFile) {
                    $realPath = $value->getRealPath();
                    if ($realPath && file_exists($realPath)) {
                        return $realPath;
                    }
                }

                // Value may be a serialized Livewire file reference string
                if (is_string($value) && str_starts_with($value, 'livewire-file:')) {
                    $filename = substr($value, strlen('livewire-file:'));
                    try {
                        $tmpFile = TemporaryUploadedFile::createFromLivewire($filename);
                        $realPath = $tmpFile->getRealPath();
                        if ($realPath && file_exists($realPath)) {
                            return $realPath;
                        }
                    } catch (\Throwable $e) {
                        // Invalid file reference
                    }
                }
            }

            // Fallback: treat as simple array with file path string
            $filePath = reset($filePath);
            if (! is_string($filePath)) {
                return null;
            }
        }

        if (! is_string($filePath)) {
            return null;
        }

        // Serialized Livewire file reference
        if (str_starts_with($filePath, 'livewire-file:')) {
            $filename = substr($filePath, strlen('livewire-file:'));
            try {
                $tmpFile = TemporaryUploadedFile::createFromLivewire($filename);
                $realPath = $tmpFile->getRealPath();
                if ($realPath && file_exists($realPath)) {
                    return $realPath;
                }
            } catch (\Throwable $e) {
                // Invalid file reference
            }
        }

        // Direct absolute path
        if (file_exists($filePath)) {
            return $filePath;
        }

        // Relative to storage
        $path = storage_path('app/private/' . $filePath);
        if (file_exists($path)) {
            return $path;
        }

        $path = storage_path('app/' . $filePath);

        return file_exists($path) ? $path : null;
    }

    /**
     * Build column options from the header row for Select dropdowns.
     * Shows "Column A: Header Value" format using Excel-style letters.
     */
    protected static function buildColumnOptions(array $headerRow): array
    {
        $options = [];

        foreach ($headerRow as $index => $value) {
            $letter = self::columnLetter($index);
            $label = $value !== '' ? $value : '(empty)';
            $options[(string) $index] = "Column {$letter}: " . mb_substr($label, 0, 50);
        }

        return $options;
    }

    /**
     * Convert a 0-based column index to Excel-style letter (0=A, 1=B, ..., 25=Z, 26=AA).
     */
    protected static function columnLetter(int $index): string
    {
        $letter = '';
        $index++;
        while ($index > 0) {
            $index--;
            $letter = chr(65 + ($index % 26)) . $letter;
            $index = intdiv($index, 26);
        }

        return $letter;
    }

    /**
     * Get the header row from parsed rows based on the header_row setting.
     * Returns the widest row (most columns) up to the header row if no clear header.
     */
    protected static function getHeaderRow(array $rows, int $headerRowNumber): array
    {
        $index = max(0, $headerRowNumber - 1);

        return $rows[$index] ?? ($rows[0] ?? []);
    }

    /**
     * Auto-detect column mapping by matching header names against known patterns.
     */
    protected static function autoDetectMapping(array $firstRow, array $fieldPatterns): array
    {
        $mapping = [];

        foreach ($fieldPatterns as $field => $patterns) {
            foreach ($firstRow as $index => $value) {
                $normalized = strtolower(trim($value));
                foreach ($patterns as $pattern) {
                    if (str_contains($normalized, $pattern)) {
                        $mapping[$field] = (string) $index;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * Apply column mapping to spreadsheet rows to produce structured items.
     * $headerRowNumber is 1-based (0 = no header, data starts at row 1).
     */
    protected static function applyMapping(array $rows, array $mapping, int $headerRowNumber, array $fieldDefaults): array
    {
        $startIndex = max(0, $headerRowNumber);
        $rawItems = [];

        for ($i = $startIndex; $i < count($rows); $i++) {
            $row = $rows[$i];
            $item = [];
            $hasContent = false;

            foreach ($fieldDefaults as $field => $default) {
                $colIndex = $mapping[$field] ?? '';
                if ($colIndex !== '' && isset($row[(int) $colIndex])) {
                    $value = trim($row[(int) $colIndex]);
                    $item[$field] = $value !== '' ? $value : $default;
                    if ($value !== '') {
                        $hasContent = true;
                    }
                } else {
                    $item[$field] = $default;
                }
            }

            if ($hasContent) {
                $rawItems[] = $item;
            }
        }

        // Consolidate duplicates: same product_name → sum quantities, keep first row's data
        $consolidated = [];
        foreach ($rawItems as $item) {
            $key = strtolower(trim($item['product_name'] ?? ''));
            if ($key === '') {
                $consolidated[] = $item;
                continue;
            }

            if (isset($consolidated[$key])) {
                $existingQty = (float) ($consolidated[$key]['quantity'] ?? 0);
                $newQty = (float) ($item['quantity'] ?? 0);
                $consolidated[$key]['quantity'] = (string) ($existingQty + $newQty);
            } else {
                $consolidated[$key] = $item;
            }
        }

        return array_values($consolidated);
    }

    /**
     * Create an "Import from Excel" action for Inquiry Items with column mapping.
     */
    public static function forInquiryItems(): Action
    {
        $fieldPatterns = [
            'product_name' => ['product', 'item', 'description', 'modelo', 'model', 'produto', 'name', 'nome'],
            'quantity' => ['qty', 'quantity', 'quantidade', 'qtd', 'quant'],
            'unit' => ['unit', 'unidade', 'uom', 'un'],
            'price' => ['price', 'target', 'preço', 'preco', 'valor', 'cost', 'custo'],
            'specs' => ['spec', 'specification', 'especifica', 'detail', 'detalhe'],
            'notes' => ['note', 'remark', 'observa', 'obs', 'comment'],
        ];

        $fieldDefaults = [
            'product_name' => '',
            'quantity' => '1',
            'unit' => 'pcs',
            'price' => '0',
            'specs' => '',
            'notes' => '',
        ];

        $fieldLabels = [
            'product_name' => 'Product Name',
            'quantity' => 'Quantity',
            'unit' => 'Unit',
            'price' => 'Target Price',
            'specs' => 'Specifications',
            'notes' => 'Notes',
        ];

        return self::buildAction(
            name: 'importFromSpreadsheet',
            label: 'Import from Excel',
            modalHeading: 'Import Inquiry Items from Spreadsheet',
            permission: 'edit-inquiries',
            fieldPatterns: $fieldPatterns,
            fieldDefaults: $fieldDefaults,
            fieldLabels: $fieldLabels,
            previewSchema: [
                TextInput::make('product_name')->label('Product Name')->required()->columnSpan(2),
                TextInput::make('quantity')->label('Qty')->numeric()->required(),
                TextInput::make('unit')->label('Unit')->required(),
                TextInput::make('price')->label('Target Price')->numeric(),
                TextInput::make('specs')->label('Specifications')->columnSpan(2),
                TextInput::make('notes')->label('Notes')->columnSpan(2),
            ],
            importCallback: function (array $items, $ownerRecord) {
                DB::transaction(function () use ($items, $ownerRecord) {
                    $maxSort = $ownerRecord->items()->max('sort_order') ?? 0;

                    foreach ($items as $item) {
                        $maxSort++;
                        $productName = $item['product_name'];

                        $product = Product::where('model_number', $productName)->first()
                            ?? Product::where('reference_code', $productName)->first()
                            ?? Product::where('sku', $productName)->first()
                            ?? Product::where('name', 'like', "%{$productName}%")->first();

                        $ownerRecord->items()->create([
                            'product_id' => $product?->id,
                            'description' => $product ? $product->name : $productName,
                            'quantity' => (float) ($item['quantity'] ?? 1),
                            'unit' => $item['unit'] ?? 'pcs',
                            'target_price' => ! empty($item['price']) ? Money::toMinor((float) $item['price']) : null,
                            'specifications' => $item['specs'] ?: null,
                            'notes' => $item['notes'] ?: null,
                            'sort_order' => $maxSort,
                        ]);
                    }
                });
            },
        );
    }

    /**
     * Create an "Import from Excel" action for Supplier Quotation Items with column mapping.
     */
    public static function forSupplierQuotationItems(): Action
    {
        $fieldPatterns = [
            'product_name' => ['product', 'item', 'description', 'modelo', 'model', 'sku', 'produto', 'name', 'nome'],
            'quantity' => ['qty', 'quantity', 'quantidade', 'qtd', 'quant'],
            'unit' => ['unit', 'unidade', 'uom', 'un'],
            'price' => ['price', 'cost', 'unit cost', 'preço', 'preco', 'valor', 'custo'],
            'moq' => ['moq', 'minimum', 'min order', 'pedido min'],
            'lead_time' => ['lead', 'delivery', 'prazo', 'entrega', 'days'],
            'specs' => ['spec', 'specification', 'especifica', 'detail', 'detalhe'],
            'notes' => ['note', 'remark', 'observa', 'obs', 'comment'],
        ];

        $fieldDefaults = [
            'product_name' => '',
            'quantity' => '1',
            'unit' => 'pcs',
            'price' => '0',
            'moq' => '1',
            'lead_time' => '0',
            'specs' => '',
            'notes' => '',
        ];

        $fieldLabels = [
            'product_name' => 'Product / SKU',
            'quantity' => 'Quantity',
            'unit' => 'Unit',
            'price' => 'Unit Cost',
            'moq' => 'MOQ',
            'lead_time' => 'Lead Time (days)',
            'specs' => 'Specifications',
            'notes' => 'Notes',
        ];

        return self::buildAction(
            name: 'importFromSpreadsheet',
            label: 'Import from Excel',
            modalHeading: 'Import Supplier Quotation Items from Spreadsheet',
            permission: 'edit-supplier-quotations',
            fieldPatterns: $fieldPatterns,
            fieldDefaults: $fieldDefaults,
            fieldLabels: $fieldLabels,
            previewSchema: [
                TextInput::make('product_name')->label('Product/SKU')->required()->columnSpan(2),
                TextInput::make('quantity')->label('Qty')->numeric()->required(),
                TextInput::make('unit')->label('Unit')->required(),
                TextInput::make('price')->label('Unit Cost')->numeric(),
                TextInput::make('moq')->label('MOQ')->numeric(),
                TextInput::make('lead_time')->label('Lead Time (days)')->numeric(),
                TextInput::make('specs')->label('Specifications')->columnSpan(2),
                TextInput::make('notes')->label('Notes')->columnSpan(2),
            ],
            importCallback: function (array $items, $ownerRecord) {
                DB::transaction(function () use ($items, $ownerRecord) {
                    $maxSort = $ownerRecord->items()->max('sort_order') ?? 0;
                    $stats = ['created' => 0, 'updated' => 0];

                    foreach ($items as $item) {
                        $productNameOrSku = $item['product_name'];

                        $product = Product::where('sku', $productNameOrSku)->first()
                            ?? Product::where('model_number', $productNameOrSku)->first()
                            ?? Product::where('reference_code', $productNameOrSku)->first()
                            ?? Product::where('name', 'like', "%{$productNameOrSku}%")->first();

                        $unitCost = ! empty($item['price']) ? Money::toMinor((float) $item['price']) : 0;
                        $quantity = (float) ($item['quantity'] ?? 1);

                        // Try to find existing item by product_id or description
                        $existing = null;
                        if ($product) {
                            $existing = $ownerRecord->items()->where('product_id', $product->id)->first();
                        }
                        if (! $existing) {
                            $existing = $ownerRecord->items()->where('description', $productNameOrSku)->first();
                        }

                        $data = [
                            'product_id' => $product?->id,
                            'description' => $product ? $product->name : $productNameOrSku,
                            'quantity' => $quantity,
                            'unit' => $item['unit'] ?? 'pcs',
                            'unit_cost' => $unitCost,
                            'moq' => ! empty($item['moq']) ? (float) $item['moq'] : null,
                            'lead_time_days' => ! empty($item['lead_time']) ? (int) $item['lead_time'] : null,
                            'specifications' => $item['specs'] ?: null,
                            'notes' => $item['notes'] ?: null,
                        ];

                        if ($existing) {
                            $existing->update($data);
                            $stats['updated']++;
                        } else {
                            $maxSort++;
                            $ownerRecord->items()->create(array_merge($data, [
                                'sort_order' => $maxSort,
                            ]));
                            $stats['created']++;
                        }
                    }

                    Notification::make()
                        ->title("Import complete: {$stats['created']} created, {$stats['updated']} updated")
                        ->success()
                        ->send();
                });
            },
        );
    }

    protected static string $sessionKey = '_spreadsheet_import_rows';

    protected static function tempPath(): string
    {
        return storage_path('app/private/spreadsheet-import-' . session()->getId() . '.json');
    }

    protected static function putCache(array $data): void
    {
        $dir = dirname(self::tempPath());
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(self::tempPath(), json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    protected static function forgetCache(): void
    {
        @unlink(self::tempPath());
    }

    /**
     * Read rows from file cache.
     */
    protected static function getCachedRows(): array
    {
        $path = self::tempPath();
        if (! file_exists($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?? [];
    }

    /**
     * Build an HTML preview table showing the first rows of the spreadsheet.
     * Highlights the header row and shows where data starts.
     */
    protected static function buildPreviewTable(array $rows, int $headerRowNumber): string
    {
        $maxPreviewRows = min(count($rows), max($headerRowNumber + 3, 8));
        $maxCols = 0;
        for ($i = 0; $i < $maxPreviewRows; $i++) {
            $maxCols = max($maxCols, count($rows[$i] ?? []));
        }

        $html = '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">';
        $html .= '<table class="min-w-full text-xs">';

        // Column letter headers
        $html .= '<thead><tr class="bg-gray-50 dark:bg-gray-800">';
        $html .= '<th class="px-2 py-1 text-gray-400 font-normal">#</th>';
        for ($c = 0; $c < $maxCols; $c++) {
            $html .= '<th class="px-2 py-1 text-gray-400 font-normal">' . self::columnLetter($c) . '</th>';
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        for ($i = 0; $i < $maxPreviewRows; $i++) {
            $rowNum = $i + 1;
            $isHeader = ($rowNum === $headerRowNumber);
            $isSkipped = ($headerRowNumber > 0 && $rowNum < $headerRowNumber);

            $rowClass = '';
            if ($isHeader) {
                $rowClass = 'bg-blue-50 dark:bg-blue-900/30 font-semibold';
            } elseif ($isSkipped) {
                $rowClass = 'bg-gray-50 dark:bg-gray-800/50 text-gray-400 line-through';
            }

            $html .= "<tr class=\"{$rowClass} border-t border-gray-100 dark:border-gray-700\">";

            $labelBadge = '';
            if ($isHeader) {
                $labelBadge = ' <span class="ml-1 text-[10px] bg-blue-100 dark:bg-blue-800 text-blue-700 dark:text-blue-300 px-1 rounded">HEADER</span>';
            } elseif ($isSkipped) {
                $labelBadge = ' <span class="ml-1 text-[10px] bg-gray-200 dark:bg-gray-700 text-gray-500 px-1 rounded">SKIP</span>';
            }

            $html .= "<td class=\"px-2 py-1 text-gray-400 whitespace-nowrap\">{$rowNum}{$labelBadge}</td>";

            $row = $rows[$i] ?? [];
            for ($c = 0; $c < $maxCols; $c++) {
                $value = htmlspecialchars(mb_substr($row[$c] ?? '', 0, 60));
                $html .= "<td class=\"px-2 py-1 max-w-[200px] truncate\">{$value}</td>";
            }

            $html .= '</tr>';
        }

        if (count($rows) > $maxPreviewRows) {
            $remaining = count($rows) - $maxPreviewRows;
            $html .= "<tr><td colspan=\"" . ($maxCols + 1) . "\" class=\"px-2 py-1 text-gray-400 text-center\">... and {$remaining} more rows</td></tr>";
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /**
     * Build the generic import action with file upload, column mapping, preview, and confirm steps.
     */
    protected static function buildAction(
        string $name,
        string $label,
        string $modalHeading,
        string $permission,
        array $fieldPatterns,
        array $fieldDefaults,
        array $fieldLabels,
        array $previewSchema,
        \Closure $importCallback,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-up-tray')
            ->color('info')
            ->modalWidth('6xl')
            ->modalHeading($modalHeading)
            ->visible(fn () => auth()->user()?->can($permission))
            ->steps([
                Step::make('Upload')
                    ->label('Upload File')
                    ->description('Upload an Excel spreadsheet (.xlsx or .xls)')
                    ->schema([
                        FileUpload::make('spreadsheet')
                            ->label('Excel File')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ])
                            ->required()
                            ->maxSize(51200)
                            ->disk('local')
                            ->directory('temp-imports')
                            ->helperText('Upload an .xlsx or .xls file (max 50MB).'),
                    ])
                    ->afterValidation(function (Get $get, Set $set) use ($fieldPatterns) {
                        $raw = $get('spreadsheet');
                        $path = self::resolveUploadPath($raw);

                        \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT DEBUG', [
                            'raw_type' => get_debug_type($raw),
                            'raw_state' => is_array($raw)
                                ? array_map(fn ($v) => get_debug_type($v) . ': ' . (is_object($v) ? get_class($v) : (is_string($v) ? $v : json_encode($v))), $raw)
                                : (is_string($raw) ? $raw : get_debug_type($raw)),
                            'resolved_path' => $path,
                            'path_exists' => $path ? file_exists($path) : false,
                        ]);

                        if (! $path) {
                            return;
                        }

                        $rows = self::readSpreadsheet($path);
                        if (empty($rows)) {
                            return;
                        }

                        self::putCache($rows);
                        \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: cached ' . count($rows) . ' rows in session');

                        // Auto-detect header row: scan first rows for one that looks like headers
                        $bestRow = 1;
                        $bestScore = 0;
                        $limit = min(count($rows), 10);
                        for ($i = 0; $i < $limit; $i++) {
                            $mapping = self::autoDetectMapping($rows[$i], $fieldPatterns);
                            if (count($mapping) > $bestScore) {
                                $bestScore = count($mapping);
                                $bestRow = $i + 1;
                            }
                        }

                        $set('header_row', (string) $bestRow);

                        // Auto-detect column mapping from the best header row
                        if ($bestScore > 0) {
                            $headerData = $rows[$bestRow - 1];
                            $mapping = self::autoDetectMapping($headerData, $fieldPatterns);
                            foreach ($mapping as $field => $colIndex) {
                                $set("col_{$field}", $colIndex);
                            }
                        }
                    }),
                Step::make('Map Columns')
                    ->label('Map Columns')
                    ->description('Choose header row and match columns to fields')
                    ->afterValidation(function (Get $get, Set $set) use ($fieldDefaults) {
                        $rows = self::getCachedRows();
                        if (empty($rows)) {
                            return;
                        }

                        $currentMapping = [];
                        foreach (array_keys($fieldDefaults) as $field) {
                            $value = $get("col_{$field}");
                            if ($value !== null && $value !== '') {
                                $currentMapping[$field] = $value;
                            }
                        }

                        $headerRow = (int) ($get('header_row') ?? 1);
                        $items = self::applyMapping($rows, $currentMapping, $headerRow, $fieldDefaults);
                        $set('items', $items);
                    })
                    ->schema(function () use ($fieldLabels, $fieldDefaults, $fieldPatterns) {
                        $columnSelects = [];
                        foreach ($fieldLabels as $field => $label) {
                            $columnSelects[] = Select::make("col_{$field}")
                                ->label($label)
                                ->options(function (Get $get) {
                                    $rows = self::getCachedRows();
                                    if (empty($rows)) {
                                        return [];
                                    }

                                    $headerRow = (int) ($get('header_row') ?? 1);
                                    $headerData = self::getHeaderRow($rows, $headerRow);

                                    return self::buildColumnOptions($headerData);
                                })
                                ->native(false)
                                ->placeholder('-- Skip --');
                        }

                        return [
                            Placeholder::make('file_preview')
                                ->label('File Preview')
                                ->content(function (Get $get) {
                                    $rows = self::getCachedRows();
                                    if (empty($rows)) {
                                        return new HtmlString('<p class="text-sm text-gray-400">No data found in file.</p>');
                                    }

                                    return new HtmlString(self::buildPreviewTable($rows, (int) ($get('header_row') ?? 1)));
                                })
                                ->columnSpanFull(),
                            TextInput::make('header_row')
                                ->label('Header row')
                                ->numeric()
                                ->default(1)
                                ->minValue(0)
                                ->maxValue(50)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($fieldPatterns) {
                                    $rows = self::getCachedRows();
                                    if (empty($rows)) {
                                        return;
                                    }

                                    $headerRow = (int) ($state ?? 1);
                                    if ($headerRow < 1) {
                                        return;
                                    }

                                    $headerData = self::getHeaderRow($rows, $headerRow);
                                    $mapping = self::autoDetectMapping($headerData, $fieldPatterns);
                                    foreach ($mapping as $field => $colIndex) {
                                        $set("col_{$field}", $colIndex);
                                    }
                                }),
                            Placeholder::make('mapping_help')
                                ->content(new HtmlString(
                                    '<p class="text-xs text-gray-500 dark:text-gray-400">'
                                    . 'Match each field to a spreadsheet column. Use "Skip" for columns you don\'t need.'
                                    . '</p>'
                                ))
                                ->columnSpanFull(),
                            ...$columnSelects,
                        ];
                    })
                    ->columns(3),
                Step::make('Preview & Edit')
                    ->label('Preview & Edit')
                    ->description('Review items before importing')
                    ->schema([
                        Repeater::make('items')
                            ->schema($previewSchema)
                            ->columns(6)
                            ->itemLabel(fn (array $state): ?string => ($state['product_name'] ?? '') . (! empty($state['quantity']) ? ' (qty: ' . $state['quantity'] . ')' : ''))
                            ->collapsible()
                            ->collapsed()
                            ->defaultItems(0)
                            ->reorderable(false),
                    ]),
                Step::make('Confirm')
                    ->label('Confirm')
                    ->description('Finalize import')
                    ->schema([
                        Placeholder::make('summary')
                            ->content(fn (Get $get) => 'You are about to import ' . count($get('items') ?? []) . ' items.'),
                    ]),
            ])
            ->action(function (array $data, $livewire) use ($importCallback): void {
                $items = $data['items'] ?? [];
                $ownerRecord = $livewire->getOwnerRecord();

                if (empty($items)) {
                    Notification::make()->title('No items to import')->warning()->send();
                    return;
                }

                try {
                    $importCallback($items, $ownerRecord);

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

                // Clean up
                self::forgetCache();
                $path = self::resolveUploadPath($data['spreadsheet'] ?? null);
                if ($path) {
                    @unlink($path);
                }
            });
    }
}
