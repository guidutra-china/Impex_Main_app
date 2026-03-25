<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

class FlexibleProductImportAction
{
    protected static function tempPath(string $suffix): string
    {
        return storage_path('app/private/flex-import-' . session()->getId() . '-' . $suffix . '.json');
    }

    protected static function putCache(string $suffix, mixed $data): void
    {
        $path = self::tempPath($suffix);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    protected static array $memoryCache = [];

    protected static function getCache(string $suffix, mixed $default = null): mixed
    {
        if (isset(self::$memoryCache[$suffix])) {
            return self::$memoryCache[$suffix];
        }

        $path = self::tempPath($suffix);
        if (! file_exists($path)) {
            return $default;
        }

        $data = json_decode(file_get_contents($path), true) ?? $default;
        self::$memoryCache[$suffix] = $data;

        return $data;
    }

    protected static function forgetCache(): void
    {
        @unlink(self::tempPath('rows'));
        @unlink(self::tempPath('images'));
        @unlink(self::tempPath('mapping'));
        @unlink(self::tempPath('row_origins'));
    }

    public static function make(string $role, \Closure $getCompany): Action
    {
        $isClient = $role === 'client';

        $fieldPatterns = [
            'product_name' => ['product', 'item', 'description', 'modelo', 'model', 'produto', 'name', 'nome'],
            'commercial_name' => ['commercial', 'comercial', 'client name', 'nome comercial', 'nome cliente'],
            'product_family' => ['family', 'família', 'familia', 'line', 'linha', 'series', 'série', 'serie'],
            'model_number' => ['model number', 'modelo', 'model no', 'model#', 'número modelo', 'numero modelo'],
            'reference_code' => ['ref', 'code', 'código', 'codigo', 'sku', 'reference'],
            'unit_price' => $isClient
                ? ['selling price', 'sell price', 'preço venda', 'preco venda', 'client price']
                : ['purchase price', 'buy price', 'preço compra', 'preco compra', 'supplier price', 'cost', 'custo', 'fob'],
            'cross_unit_price' => $isClient
                ? ['purchase price', 'buy price', 'preço compra', 'preco compra', 'supplier price', 'cost', 'custo', 'fob']
                : ['selling price', 'sell price', 'preço venda', 'preco venda', 'client price'],
            'custom_price' => ['custom price', 'ci price', 'ci override', 'override', 'preço custom', 'preco ci'],
            'external_code' => $isClient
                ? ['client code', 'codigo cliente', 'external code']
                : ['supplier code', 'codigo fornecedor', 'external code'],
            'external_name' => $isClient
                ? ['client name', 'nome cliente', 'client product']
                : ['supplier name', 'nome fornecedor', 'supplier product'],
            'cross_external_code' => $isClient
                ? ['supplier code', 'codigo fornecedor']
                : ['client code', 'codigo cliente'],
            'cross_external_name' => $isClient
                ? ['supplier name', 'nome fornecedor', 'supplier product']
                : ['client name', 'nome cliente', 'client product'],
            'external_description' => ['external desc', 'invoice desc', 'ci desc', 'descrição fatura', 'descricao fatura'],
            'moq' => ['moq', 'minimum', 'min order', 'pedido min', 'min qty'],
            'lead_time' => ['lead', 'delivery', 'prazo', 'entrega', 'days', 'dias'],
            'material' => ['material', 'matéria', 'materia'],
            'specs' => ['spec', 'specification', 'especifica', 'detail', 'detalhe'],
            'notes' => ['note', 'remark', 'observa', 'obs', 'comment'],
        ];

        // Add generic price patterns as fallback (only if specific ones didn't match)
        $fieldPatterns['unit_price'] = array_merge($fieldPatterns['unit_price'], ['price', 'preço', 'preco', 'valor', 'unit price', 'unit cost']);

        $fieldDefaults = [
            'product_name' => '',
            'commercial_name' => '',
            'product_family' => '',
            'model_number' => '',
            'reference_code' => '',
            'unit_price' => '',
            'cross_unit_price' => '',
            'custom_price' => '',
            'external_code' => '',
            'external_name' => '',
            'cross_external_code' => '',
            'cross_external_name' => '',
            'external_description' => '',
            'moq' => '',
            'lead_time' => '',
            'material' => '',
            'specs' => '',
            'notes' => '',
        ];

        $fieldLabels = [
            'product_name' => 'Product Name',
            'commercial_name' => 'Commercial Name',
            'product_family' => 'Product Family',
            'model_number' => 'Model Number',
            'reference_code' => 'Reference Code / SKU',
            'unit_price' => $isClient ? 'Selling Price (Client)' : 'Purchase Price (Supplier)',
            'cross_unit_price' => $isClient ? 'Purchase Price (Supplier)' : 'Selling Price (Client)',
            'custom_price' => 'Custom Price (CI Override)',
            'external_code' => $isClient ? 'Client Code' : 'Supplier Code',
            'external_name' => $isClient ? 'Client Product Name' : 'Supplier Product Name',
            'cross_external_code' => $isClient ? 'Supplier Code' : 'Client Code',
            'cross_external_name' => $isClient ? 'Supplier Product Name' : 'Client Product Name',
            'external_description' => 'Invoice Description',
            'moq' => 'MOQ',
            'lead_time' => 'Lead Time (days)',
            'material' => 'Material',
            'specs' => 'Specifications',
            'notes' => 'Notes',
        ];

        return Action::make('flexibleProductImport')
            ->label('Quick Import')
            ->icon('heroicon-o-document-magnifying-glass')
            ->color('success')
            ->modalWidth('6xl')
            ->modalHeading('Quick Product Import — Map Any Spreadsheet')
            ->visible(fn () => auth()->user()?->can('edit-companies'))
            ->steps([
                Step::make('Upload')
                    ->label('Upload')
                    ->description('Upload spreadsheet')
                    ->schema([
                        Select::make('cross_company_id')
                            ->label($role === 'client' ? 'Also link to Supplier' : 'Also link to Client')
                            ->options(function () use ($role) {
                                $crossRole = $role === 'client' ? CompanyRole::SUPPLIER : CompanyRole::CLIENT;

                                return Company::withRole($crossRole)
                                    ->orderBy('name')
                                    ->pluck('name', 'id');
                            })
                            ->searchable()
                            ->placeholder('— None —')
                            ->helperText($role === 'client'
                                ? 'Optionally link imported products to a supplier as well'
                                : 'Optionally link imported products to a client as well'),
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
                            ->helperText('Upload .xlsx or .xls file (max 50MB). Product images in the spreadsheet will be imported automatically.'),
                    ])
                    ->afterValidation(function (Get $get, Set $set) use ($fieldPatterns) {
                        ini_set('memory_limit', '512M');
                        \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: afterValidation START');

                        try {
                            $raw = $get('spreadsheet');
                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: raw', [
                                'type' => get_debug_type($raw),
                                'values' => is_array($raw) ? array_map(fn ($v) => get_debug_type($v), $raw) : 'not array',
                            ]);
                            $path = self::resolveUploadPath($raw);
                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: resolved path = ' . ($path ?? 'NULL'));

                            if (! $path) {
                                Notification::make()->title('Could not resolve file path')->danger()->send();

                                return;
                            }

                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: loading spreadsheet...');
                            $spreadsheet = IOFactory::load($path);
                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: spreadsheet loaded OK');
                            $worksheet = $spreadsheet->getActiveSheet();

                            // Use toArray() for memory efficiency and limit to actual data range
                            $highestRow = $worksheet->getHighestDataRow();
                            $highestCol = $worksheet->getHighestDataColumn();
                            \Illuminate\Support\Facades\Log::info("FLEXIBLE IMPORT: data range = A1:{$highestCol}{$highestRow}");

                            $rawData = $worksheet->toArray(null, true, false, false);
                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: toArray returned ' . count($rawData) . ' rows');

                            // Filter out completely empty rows and trim values
                            // Preserve original spreadsheet row numbers for image matching
                            $rows = [];
                            $rowOrigins = []; // maps filtered index → original 1-based row number
                            $maxRows = min(count($rawData), 5000); // Safety limit
                            for ($r = 0; $r < $maxRows; $r++) {
                                $values = array_map(fn ($v) => trim((string) ($v ?? '')), $rawData[$r]);
                                if (implode('', $values) !== '') {
                                    $rowOrigins[count($rows)] = $r + 1; // 1-based spreadsheet row
                                    $rows[] = $values;
                                }
                            }
                            unset($rawData);
                            self::putCache('row_origins', $rowOrigins);
                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: filtered to ' . count($rows) . ' non-empty rows');

                            if (empty($rows)) {
                                Notification::make()->title('No data found in file')->warning()->send();

                                return;
                            }

                            // Extract images (non-critical — catch errors separately)
                            $images = [];
                            try {
                                $images = self::extractImagesByRow($worksheet);
                                \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: extracted ' . count($images) . ' images');
                            } catch (\Throwable $e) {
                                \Illuminate\Support\Facades\Log::warning('Image extraction failed: ' . $e->getMessage());
                            }

                            // Free memory before storing in session
                            $spreadsheet->disconnectWorksheets();
                            unset($spreadsheet, $worksheet);

                            self::putCache('images', $images);
                            self::putCache('rows', $rows);
                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: stored ' . count($rows) . ' rows and ' . count($images) . ' images in cache');

                            // Auto-detect header row
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

                            if ($bestScore > 0) {
                                $headerData = $rows[$bestRow - 1];
                                $mapping = self::autoDetectMapping($headerData, $fieldPatterns);
                                // Group fields by column index for multi-select
                                $colFields = [];
                                foreach ($mapping as $field => $colIndex) {
                                    $colFields[$colIndex][] = $field;
                                }
                                foreach ($colFields as $colIndex => $fields) {
                                    $set("col_map_{$colIndex}", $fields);
                                }
                            }
                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: afterValidation COMPLETE');
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('Flexible import error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                            Notification::make()->title('Error reading file')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Step::make('Map Columns')
                    ->label('Map & Configure')
                    ->description('Assign fields to columns and define import blocks')
                    ->afterValidation(function (Get $get) {
                        $headerRow = (int) ($get('header_row') ?? 1);

                        $colMapping = [];
                        for ($c = 0; $c < 15; $c++) {
                            $fields = $get("col_map_{$c}") ?? [];
                            if (is_string($fields)) {
                                $fields = [$fields];
                            }
                            foreach ($fields as $field) {
                                if ($field && $field !== '' && $field !== 'skip') {
                                    $colMapping[$field] = (string) $c;
                                }
                            }
                        }

                        self::putCache('mapping', [
                            'columns' => $colMapping,
                            'header_row' => $headerRow,
                            'blocks' => array_values($get('import_blocks') ?? []),
                            'currency_code' => $get('currency_code') ?? 'USD',
                            'custom_price_formula' => $get('custom_price_formula'),
                        ]);
                    })
                    ->schema(function () use ($isClient) {
                        $rows = self::getCache('rows', []);
                        $headerData = $rows[0] ?? [];
                        $totalCols = count($headerData);
                        $displayCols = min($totalCols, 15);

                        $rowOrigins = self::getCache('row_origins', []);
                        $lastRow = ! empty($rowOrigins) ? max($rowOrigins) : count($rows);

                        // Build field options grouped by section
                        $fieldOptions = [
                            'skip' => '— Skip —',
                            'Product' => [
                                'commercial_name' => 'Commercial Name',
                                'model_number' => 'Model Number',
                                'product_family' => 'Product Family',
                                'reference_code' => 'Reference Code / SKU',
                                'moq' => 'MOQ',
                                'lead_time' => 'Lead Time (days)',
                                'material' => 'Material',
                                'specs' => 'Specifications',
                                'notes' => 'Notes',
                            ],
                            ($isClient ? 'Client' : 'Supplier') . ' Link' => [
                                'unit_price' => $isClient ? 'Selling Price (Client)' : 'Purchase Price (Supplier)',
                                'custom_price' => 'Custom Price (CI Override)',
                                'external_code' => $isClient ? 'Client Code' : 'Supplier Code',
                                'external_name' => $isClient ? 'Client Product Name' : 'Supplier Product Name',
                                'external_description' => 'Invoice Description',
                            ],
                            ($isClient ? 'Supplier' : 'Client') . ' Link' => [
                                'cross_unit_price' => $isClient ? 'Purchase Price (Supplier)' : 'Selling Price (Client)',
                                'cross_external_code' => $isClient ? 'Supplier Code' : 'Client Code',
                                'cross_external_name' => $isClient ? 'Supplier Product Name' : 'Client Product Name',
                            ],
                        ];

                        // Build column selects — always 15 to keep form structure stable
                        $colSelects = [];
                        for ($c = 0; $c < 15; $c++) {
                            $letter = self::columnLetter($c);
                            $headerLabel = $headerData[$c] ?? '';
                            $label = "Col {$letter}" . ($headerLabel ? ": {$headerLabel}" : '');

                            $select = Select::make("col_map_{$c}")
                                ->options($fieldOptions)
                                ->multiple()
                                ->default([])
                                ->native(false);

                            if ($c < $displayCols) {
                                $select->label(mb_substr($label, 0, 40));
                            } else {
                                $select->hidden();
                            }

                            $colSelects[] = $select;
                        }

                        return [
                            Placeholder::make('file_preview')
                                ->label('Spreadsheet — use row numbers for Start/End Row below')
                                ->content(function (Get $get) {
                                    try {
                                        $rows = self::getCachedRows();
                                        if (empty($rows)) {
                                            return new HtmlString('<p class="text-sm text-gray-400">No data found.</p>');
                                        }

                                        $images = self::getCache('images', []);
                                        $imageNote = count($images) > 0
                                            ? "<p class=\"text-sm text-green-600 mt-1\">📷 " . count($images) . " image(s) detected</p>"
                                            : '';

                                        return new HtmlString(
                                            self::buildFullPreviewTable($rows, (int) ($get('header_row') ?? 1))
                                            . $imageNote
                                        );
                                    } catch (\Throwable $e) {
                                        return new HtmlString('<p class="text-red-500">Error: ' . e($e->getMessage()) . '</p>');
                                    }
                                })
                                ->columnSpanFull(),
                            TextInput::make('header_row')
                                ->label('Header row')
                                ->numeric()
                                ->default(1)
                                ->minValue(0)
                                ->maxValue(50)
                                ->required()
                                ->live(onBlur: true),
                            Placeholder::make('mapping_help')
                                ->content(new HtmlString(
                                    '<p class="text-xs text-gray-500 dark:text-gray-400">'
                                    . 'Assign a field to each spreadsheet column:'
                                    . '</p>'
                                ))
                                ->columnSpanFull(),
                            ...$colSelects,
                            Select::make('currency_code')
                                ->label('Currency')
                                ->options(fn () => \App\Domain\Settings\Models\Currency::orderBy('code')->pluck('code', 'code'))
                                ->searchable()
                                ->default('USD')
                                ->required(),
                            TextInput::make('custom_price_formula')
                                ->label('Custom Price Formula')
                                ->placeholder('e.g. *0.70, *1.30, +5')
                                ->helperText('Calculate from Unit Price. *0.70=70%, +5=add $5'),
                            Placeholder::make('blocks_help')
                                ->content(new HtmlString(
                                    '<hr class="my-2 border-gray-200 dark:border-gray-700">'
                                    . '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">'
                                    . 'Define category blocks with row ranges:'
                                    . '</p>'
                                ))
                                ->columnSpanFull(),
                            Repeater::make('import_blocks')
                                ->label('')
                                ->schema([
                                    Select::make('category_id')
                                        ->label('Category')
                                        ->options(fn () => Category::active()->orderBy('name')->get()->mapWithKeys(fn (Category $cat) => [$cat->id => $cat->full_path]))
                                        ->searchable()
                                        ->required(),
                                    TextInput::make('product_family')
                                        ->label('Product Family')
                                        ->maxLength(255)
                                        ->placeholder('e.g. MX Series')
                                        ->datalist(fn () => Product::query()
                                            ->whereNotNull('product_family')
                                            ->distinct()
                                            ->orderBy('product_family')
                                            ->pluck('product_family')
                                            ->toArray()),
                                    TextInput::make('start_row')
                                        ->label('Start Row')
                                        ->numeric()
                                        ->required()
                                        ->minValue(1)
                                        ->maxValue($lastRow)
                                        ->placeholder('e.g. 2'),
                                    TextInput::make('end_row')
                                        ->label('End Row')
                                        ->numeric()
                                        ->required()
                                        ->minValue(1)
                                        ->maxValue($lastRow)
                                        ->placeholder("e.g. {$lastRow}"),
                                ])
                                ->columns(4)
                                ->required()
                                ->minItems(1)
                                ->defaultItems(1)
                                ->addActionLabel('Add category block')
                                ->reorderable(false)
                                ->columnSpanFull(),
                        ];
                    })
                    ->columns(3),
                Step::make('Confirm')
                    ->label('Preview & Confirm')
                    ->description('Review and finalize import')
                    ->schema([
                        Placeholder::make('import_preview')
                            ->label('')
                            ->content(function () {
                                $mapping = self::getCache('mapping', []);
                                $rows = self::getCachedRows();
                                $images = self::getCache('images', []);
                                $blocks = $mapping['blocks'] ?? [];
                                $colMapping = $mapping['columns'] ?? [];
                                $headerRow = $mapping['header_row'] ?? 1;

                                if (empty($rows) || empty($colMapping) || empty($blocks)) {
                                    return new HtmlString('<p class="text-red-500">No mapping data. Please go back and configure columns and blocks.</p>');
                                }

                                $mappedFields = array_keys($colMapping);
                                $showFields = array_slice($mappedFields, 0, 6);
                                $totalProducts = 0;
                                $totalImages = 0;
                                $currencyCode = $mapping['currency_code'] ?? 'USD';
                                $formula = $mapping['custom_price_formula'] ?? null;

                                $html = '<div class="space-y-4">';
                                $html .= '<div class="text-sm text-gray-600 dark:text-gray-400">';
                                $html .= 'Currency: <strong>' . e($currencyCode) . '</strong>';
                                if ($formula) {
                                    $html .= ' | Custom Price: <strong>Unit Price ' . e($formula) . '</strong>';
                                }
                                $html .= '</div>';

                                foreach ($blocks as $idx => $block) {
                                    $categoryId = $block['category_id'] ?? null;
                                    $startRow = (int) ($block['start_row'] ?? 1);
                                    $endRow = (int) ($block['end_row'] ?? count($rows));
                                    $categoryName = $categoryId ? (Category::find($categoryId)?->name ?? 'Unknown') : 'No category';
                                    $blockFamily = $block['product_family'] ?? null;

                                    $items = self::applyMappingWithRange($rows, $colMapping, $headerRow, null, $startRow, $endRow);
                                    $totalProducts += count($items);

                                    $blockImages = 0;
                                    foreach ($images as $imgRow => $imgPath) {
                                        if ($imgRow >= $startRow && $imgRow <= $endRow) {
                                            $blockImages++;
                                        }
                                    }
                                    $totalImages += $blockImages;

                                    $html .= '<div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">';
                                    $html .= '<p class="text-sm font-medium mb-2">';
                                    $html .= '<span class="inline-flex items-center rounded-md bg-blue-50 dark:bg-blue-900/30 px-2 py-1 text-xs font-medium text-blue-700 dark:text-blue-300 ring-1 ring-inset ring-blue-700/10 mr-2">Block ' . ($idx + 1) . '</span>';
                                    $html .= '<strong>' . e($categoryName) . '</strong>';
                                    if ($blockFamily) {
                                        $html .= ' <span class="text-xs text-gray-500">(' . e($blockFamily) . ')</span>';
                                    }
                                    $html .= ' — rows ' . $startRow . '–' . $endRow;
                                    $html .= ' (' . count($items) . ' products';
                                    if ($blockImages > 0) {
                                        $html .= ', ' . $blockImages . ' images';
                                    }
                                    $html .= ')</p>';

                                    if (! empty($items) && ! empty($showFields)) {
                                        $previewCount = min(count($items), 5);
                                        $html .= '<div class="overflow-x-auto"><table class="min-w-full text-xs">';
                                        $html .= '<thead><tr class="bg-gray-50 dark:bg-gray-800">';
                                        foreach ($showFields as $field) {
                                            $html .= '<th class="px-2 py-1 text-left">' . e(self::fieldLabel($field)) . '</th>';
                                        }
                                        $html .= '</tr></thead><tbody>';
                                        for ($i = 0; $i < $previewCount; $i++) {
                                            $html .= '<tr class="border-t border-gray-100 dark:border-gray-700">';
                                            foreach ($showFields as $field) {
                                                $val = htmlspecialchars(mb_substr($items[$i][$field] ?? '', 0, 40));
                                                $html .= '<td class="px-2 py-1">' . $val . '</td>';
                                            }
                                            $html .= '</tr>';
                                        }
                                        if (count($items) > $previewCount) {
                                            $html .= '<tr><td colspan="' . count($showFields) . '" class="px-2 py-1 text-gray-400 text-center">... and ' . (count($items) - $previewCount) . ' more</td></tr>';
                                        }
                                        $html .= '</tbody></table></div>';
                                    }
                                    $html .= '</div>';
                                }

                                $html .= '<p class="text-sm font-medium mt-2">Total: <strong>' . $totalProducts . '</strong> products across ' . count($blocks) . ' block(s).';
                                if ($totalImages > 0) {
                                    $html .= " {$totalImages} image(s) will be saved.";
                                }
                                $html .= '</p></div>';

                                return new HtmlString($html);
                            }),
                    ]),
            ])
            ->action(function (array $data) use ($role, $getCompany): void {
                $crossCompanyId = $data['cross_company_id'] ?? null;
                $rows = self::getCachedRows();
                $images = self::getCache('images', []);

                // Collect mapping from form data (more reliable than cache)
                $headerRow = (int) ($data['header_row'] ?? 1);
                $colMapping = [];
                for ($c = 0; $c < 15; $c++) {
                    $fields = $data["col_map_{$c}"] ?? [];
                    if (is_string($fields)) {
                        $fields = [$fields];
                    }
                    foreach ($fields as $field) {
                        if ($field && $field !== '' && $field !== 'skip') {
                            $colMapping[$field] = (string) $c;
                        }
                    }
                }
                $blocks = array_values($data['import_blocks'] ?? []);
                $currencyCode = $data['currency_code'] ?? 'USD';
                $customPriceFormula = $data['custom_price_formula'] ?? null;

                if (empty($blocks)) {
                    Notification::make()->title('No import blocks defined')->warning()->send();

                    return;
                }

                /** @var Company $company */
                $company = $getCompany();
                $skuGenerator = app(GenerateProductSkuAction::class);

                // Validate that model_number is mapped (required for product name generation)
                if (! isset($colMapping['model_number'])) {
                    Notification::make()
                        ->title('Missing required column mapping')
                        ->body('You must map "Model Number" to a column. Product names are generated as: Category + Model Number.')
                        ->danger()
                        ->send();

                    return;
                }

                $stats = ['created' => 0, 'updated' => 0, 'images' => 0, 'skipped' => 0, 'errors' => []];
                $totalItems = 0;

                try {
                    DB::transaction(function () use ($blocks, $rows, $colMapping, $headerRow, $company, $role, $skuGenerator, $images, $crossCompanyId, $currencyCode, $customPriceFormula, &$stats, &$totalItems) {
                        foreach ($blocks as $block) {
                            $categoryId = $block['category_id'] ?? null;
                            $startRow = (int) ($block['start_row'] ?? 1);
                            $endRow = (int) ($block['end_row'] ?? count($rows));

                            if (! $categoryId) {
                                continue;
                            }

                            $category = Category::findOrFail($categoryId);
                            $blockFamily = $block['product_family'] ?? null;
                            $items = self::applyMappingWithRange($rows, $colMapping, $headerRow, null, $startRow, $endRow);
                            $totalItems += count($items);

                            foreach ($items as $item) {
                                // Auto-generate name: Category + Model Number (same as product form)
                                $productName = $item['product_name'] ?? '';
                                if (empty($productName)) {
                                    $modelNumber = $item['model_number'] ?? '';
                                    $productName = $modelNumber
                                        ? $category->name . ' ' . $modelNumber
                                        : $category->name;
                                }

                                if (empty(trim($productName))) {
                                    $stats['skipped']++;
                                    continue;
                                }

                                $sourceRow = $item['_source_row'] ?? null;
                                $imagePath = $sourceRow ? ($images[$sourceRow] ?? null) : null;

                                $sku = ! empty($item['reference_code'])
                                    ? trim($item['reference_code'])
                                    : $skuGenerator->execute($category->id);

                                $existing = Product::withTrashed()->where('sku', $sku)->first()
                                    ?? (! empty($item['reference_code']) ? Product::withTrashed()->where('reference_code', trim($item['reference_code']))->first() : null)
                                    ?? Product::where('name', $productName)->first();

                                if ($existing) {
                                    if ($existing->trashed()) {
                                        $existing->restore();
                                    }
                                    if ($imagePath) {
                                        $existing->update(['avatar' => $imagePath]);
                                        $stats['images']++;
                                    }
                                    $stats['updated']++;
                                } else {
                                    $existing = Product::create([
                                        'name' => $productName,
                                        'commercial_name' => $item['commercial_name'] ?? null,
                                        'product_family' => $blockFamily ?: ($item['product_family'] ?? null),
                                        'model_number' => $item['model_number'] ?? null,
                                        'sku' => $sku,
                                        'reference_code' => ! empty($item['reference_code']) ? trim($item['reference_code']) : null,
                                        'category_id' => $category->id,
                                        'status' => ProductStatus::ACTIVE,
                                        'moq' => ! empty($item['moq']) ? (int) $item['moq'] : null,
                                        'lead_time_days' => ! empty($item['lead_time']) ? (int) $item['lead_time'] : null,
                                        'avatar' => $imagePath,
                                    ]);
                                    $stats['created']++;
                                    if ($imagePath) {
                                        $stats['images']++;
                                    }
                                }

                                $unitPrice = ! empty($item['unit_price']) ? (float) $item['unit_price'] : null;
                                $customPrice = ! empty($item['custom_price']) ? (float) $item['custom_price'] : null;
                                if ($customPrice === null && $unitPrice !== null && $customPriceFormula) {
                                    $customPrice = self::applyFormula($unitPrice, $customPriceFormula);
                                }

                                $pivotData = array_filter([
                                    'role' => $role,
                                    'external_code' => $item['external_code'] ?? null,
                                    'external_name' => $item['external_name'] ?? null,
                                    'external_description' => $item['external_description'] ?? null,
                                    'unit_price' => $unitPrice !== null ? Money::toMinor($unitPrice) : null,
                                    'custom_price' => $customPrice !== null ? Money::toMinor($customPrice) : null,
                                    'currency_code' => $currencyCode,
                                ], fn ($v) => $v !== null);

                                $existingLink = CompanyProduct::where('product_id', $existing->id)
                                    ->where('company_id', $company->id)
                                    ->where('role', $role)
                                    ->first();

                                if ($existingLink) {
                                    $existingLink->update($pivotData);
                                } else {
                                    CompanyProduct::create(array_merge($pivotData, [
                                        'product_id' => $existing->id,
                                        'company_id' => $company->id,
                                    ]));
                                }

                                if (! empty($crossCompanyId)) {
                                    $crossRole = $role === 'client' ? 'supplier' : 'client';
                                    $crossPivotData = array_filter([
                                        'role' => $crossRole,
                                        'external_code' => $item['cross_external_code'] ?? null,
                                        'external_name' => $item['cross_external_name'] ?? null,
                                        'unit_price' => ! empty($item['cross_unit_price']) ? Money::toMinor((float) $item['cross_unit_price']) : null,
                                        'currency_code' => $currencyCode,
                                    ], fn ($v) => $v !== null);

                                    $existingCrossLink = CompanyProduct::where('product_id', $existing->id)
                                        ->where('company_id', $crossCompanyId)
                                        ->where('role', $crossRole)
                                        ->first();

                                    if ($existingCrossLink) {
                                        $existingCrossLink->update($crossPivotData);
                                    } else {
                                        CompanyProduct::create(array_merge($crossPivotData, [
                                            'product_id' => $existing->id,
                                            'company_id' => $crossCompanyId,
                                        ]));
                                    }
                                }
                            }
                        }
                    });

                    $parts = [];
                    if ($stats['created'] > 0) {
                        $parts[] = "{$stats['created']} created";
                    }
                    if ($stats['updated'] > 0) {
                        $parts[] = "{$stats['updated']} linked/updated";
                    }
                    if ($stats['images'] > 0) {
                        $parts[] = "{$stats['images']} images";
                    }
                    if ($stats['skipped'] > 0) {
                        $parts[] = "{$stats['skipped']} skipped (no name)";
                    }

                    $blockSummary = count($blocks) > 1 ? ' across ' . count($blocks) . ' categories' : '';

                    Notification::make()
                        ->title("Import Complete — {$totalItems} products{$blockSummary}")
                        ->body(implode(', ', $parts) ?: 'Done.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('QUICK IMPORT: error', [
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                    Notification::make()
                        ->title('Error importing products')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }

                self::forgetCache();
            });
    }

    // ── Helper methods (reused from PasteItemsFromSpreadsheetAction) ──

    protected static function resolveUploadPath(mixed $filePath): ?string
    {
        if (empty($filePath)) {
            return null;
        }

        if ($filePath instanceof TemporaryUploadedFile) {
            $realPath = $filePath->getRealPath();

            return ($realPath && file_exists($realPath)) ? $realPath : null;
        }

        if (is_array($filePath)) {
            foreach ($filePath as $value) {
                if ($value instanceof TemporaryUploadedFile) {
                    $realPath = $value->getRealPath();
                    if ($realPath && file_exists($realPath)) {
                        return $realPath;
                    }
                }

                if (is_string($value) && str_starts_with($value, 'livewire-file:')) {
                    $filename = substr($value, strlen('livewire-file:'));
                    try {
                        $tmpFile = TemporaryUploadedFile::createFromLivewire($filename);
                        $realPath = $tmpFile->getRealPath();
                        if ($realPath && file_exists($realPath)) {
                            return $realPath;
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }

            $filePath = reset($filePath);
            if (! is_string($filePath)) {
                return null;
            }
        }

        if (! is_string($filePath)) {
            return null;
        }

        if (str_starts_with($filePath, 'livewire-file:')) {
            $filename = substr($filePath, strlen('livewire-file:'));
            try {
                $tmpFile = TemporaryUploadedFile::createFromLivewire($filename);
                $realPath = $tmpFile->getRealPath();
                if ($realPath && file_exists($realPath)) {
                    return $realPath;
                }
            } catch (\Throwable $e) {
            }
        }

        if (file_exists($filePath)) {
            return $filePath;
        }

        $path = storage_path('app/private/' . $filePath);
        if (file_exists($path)) {
            return $path;
        }

        $path = storage_path('app/' . $filePath);

        return file_exists($path) ? $path : null;
    }

    protected static function extractImagesByRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet): array
    {
        $imagesByRow = [];
        $hashMap = []; // md5 hash => stored filename (deduplication)

        foreach ($worksheet->getDrawingCollection() as $drawing) {
            $coordinate = $drawing->getCoordinates();

            if (empty($coordinate)) {
                \Illuminate\Support\Facades\Log::warning('FLEXIBLE IMPORT: image with no coordinates, skipping');
                continue;
            }

            // Extract row number from cell reference (e.g., "B5" → 5)
            $row = (int) preg_replace('/[A-Z]+/i', '', $coordinate);

            if ($row < 1) {
                \Illuminate\Support\Facades\Log::warning("FLEXIBLE IMPORT: invalid row from coordinate '{$coordinate}', skipping");
                continue;
            }

            if (isset($imagesByRow[$row])) {
                continue;
            }

            $imageData = null;
            $extension = 'png';

            try {
                if ($drawing instanceof MemoryDrawing) {
                    $imageResource = $drawing->getImageResource();
                    if ($imageResource) {
                        ob_start();
                        $renderFunc = match ($drawing->getRenderingFunction()) {
                            MemoryDrawing::RENDERING_JPEG => 'imagejpeg',
                            MemoryDrawing::RENDERING_GIF => 'imagegif',
                            default => 'imagepng',
                        };
                        $renderFunc($imageResource);
                        $imageData = ob_get_clean();
                        $extension = match ($drawing->getRenderingFunction()) {
                            MemoryDrawing::RENDERING_JPEG => 'jpg',
                            MemoryDrawing::RENDERING_GIF => 'gif',
                            default => 'png',
                        };
                    }
                } elseif ($drawing instanceof Drawing && $drawing->getPath()) {
                    $sourcePath = $drawing->getPath();

                    if (str_starts_with($sourcePath, 'zip://')) {
                        $imageData = @file_get_contents($sourcePath);
                    } elseif (file_exists($sourcePath)) {
                        $imageData = file_get_contents($sourcePath);
                    }

                    if ($imageData) {
                        $extension = strtolower($drawing->getExtension() ?: pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'png');
                    }
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("FLEXIBLE IMPORT: failed to extract image at {$coordinate}: {$e->getMessage()}");
                continue;
            }

            if ($imageData && strlen($imageData) > 100) { // Skip tiny/corrupt images
                $hash = md5($imageData);

                if (isset($hashMap[$hash])) {
                    // Reuse already-saved file for identical image content
                    $imagesByRow[$row] = $hashMap[$hash];
                    \Illuminate\Support\Facades\Log::info("FLEXIBLE IMPORT: image at row {$row} deduplicated → {$hashMap[$hash]}");
                } else {
                    $filename = 'products/' . uniqid('import_') . '.' . $extension;
                    Storage::disk('public')->put($filename, $imageData);
                    $hashMap[$hash] = $filename;
                    $imagesByRow[$row] = $filename;
                    \Illuminate\Support\Facades\Log::info("FLEXIBLE IMPORT: image extracted at row {$row} → {$filename}");
                }
            }
        }

        return $imagesByRow;
    }

    protected static function getCachedRows(): array
    {
        return self::getCache('rows', []);
    }

    protected static function getHeaderRow(array $rows, int $headerRowNumber): array
    {
        $index = max(0, $headerRowNumber - 1);

        return $rows[$index] ?? ($rows[0] ?? []);
    }

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

    protected static function fieldLabel(string $field): string
    {
        return [
            'product_name' => 'Product Name', 'commercial_name' => 'Commercial Name',
            'model_number' => 'Model Number', 'product_family' => 'Product Family',
            'reference_code' => 'Reference Code', 'moq' => 'MOQ',
            'lead_time' => 'Lead Time', 'material' => 'Material',
            'specs' => 'Specifications', 'notes' => 'Notes',
            'unit_price' => 'Unit Price', 'custom_price' => 'Custom Price',
            'cross_unit_price' => 'Cross Unit Price',
            'external_code' => 'Ext. Code', 'external_name' => 'Ext. Name',
            'cross_external_code' => 'Cross Ext. Code', 'cross_external_name' => 'Cross Ext. Name',
            'external_description' => 'Invoice Desc',
        ][$field] ?? $field;
    }

    protected static function applyFormula(float $baseValue, string $formula): ?float
    {
        $formula = preg_replace('/\s+/', '', trim($formula));
        if ($formula === '') {
            return null;
        }

        $operator = $formula[0];
        $operand = (float) substr($formula, 1);

        if ($operand == 0 && $operator !== '+' && $operator !== '-') {
            return null;
        }

        return match ($operator) {
            '*' => round($baseValue * $operand, 4),
            '+' => round($baseValue + $operand, 4),
            '-' => round($baseValue - $operand, 4),
            '/' => $operand != 0 ? round($baseValue / $operand, 4) : null,
            default => null,
        };
    }

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

    protected static function applyMappingWithRange(array $rows, array $colMapping, int $headerRowNumber, ?array $fieldDefaults = null, ?int $startRow = null, ?int $endRow = null): array
    {
        $rowOrigins = self::getCache('row_origins', []);
        $headerOriginalRow = $rowOrigins[$headerRowNumber - 1] ?? $headerRowNumber;
        $items = [];

        for ($i = 0; $i < count($rows); $i++) {
            $originalRow = $rowOrigins[$i] ?? ($i + 1);

            if ($originalRow === $headerOriginalRow) {
                continue;
            }
            if ($startRow === null && $originalRow <= $headerOriginalRow) {
                continue;
            }
            if ($startRow !== null && $originalRow < $startRow) {
                continue;
            }
            if ($endRow !== null && $originalRow > $endRow) {
                continue;
            }

            $row = $rows[$i];
            $item = [];
            $hasContent = false;

            // Iterate mapped fields (field => colIndex)
            foreach ($colMapping as $field => $colIndex) {
                if ($colIndex !== '' && isset($row[(int) $colIndex])) {
                    $value = trim($row[(int) $colIndex]);
                    if ($value !== '') {
                        $item[$field] = $value;
                        $hasContent = true;
                    }
                }
            }

            if ($hasContent) {
                $item['_source_row'] = $originalRow;
                $items[] = $item;
            }
        }

        return $items;
    }

    protected static function buildFullPreviewTable(array $rows, int $headerRowNumber): string
    {
        $rowOrigins = self::getCache('row_origins', []);
        $maxCols = 0;
        foreach ($rows as $row) {
            $maxCols = max($maxCols, count($row));
        }
        $displayCols = min($maxCols, 10);
        $perPage = 25;
        $totalRows = count($rows);
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $uid = 'sp_' . substr(md5(uniqid()), 0, 8);

        // Build table header
        $thead = '<thead class="sticky top-0 z-10"><tr class="bg-gray-50 dark:bg-gray-800">';
        $thead .= '<th class="px-2 py-1 text-gray-400 font-normal text-left">Row</th>';
        for ($c = 0; $c < $displayCols; $c++) {
            $thead .= '<th class="px-2 py-1 text-gray-400 font-normal text-left">' . self::columnLetter($c) . '</th>';
        }
        if ($maxCols > $displayCols) {
            $thead .= '<th class="px-2 py-1 text-gray-400 font-normal">…</th>';
        }
        $thead .= '</tr></thead>';

        // Build all rows with page data attribute
        $tbody = '<tbody>';
        for ($i = 0; $i < $totalRows; $i++) {
            $page = (int) floor($i / $perPage) + 1;
            $originalRowNum = $rowOrigins[$i] ?? ($i + 1);
            $isHeader = ($originalRowNum === $headerRowNumber);

            $bgClass = $isHeader
                ? 'bg-blue-50 dark:bg-blue-900/30 font-semibold'
                : ($i % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50 dark:bg-gray-800/30');

            $display = $page === 1 ? '' : ' style="display:none"';

            $tbody .= "<tr data-page=\"{$page}\" class=\"{$bgClass} border-t border-gray-100 dark:border-gray-700\"{$display}>";

            $badge = $isHeader
                ? ' <span class="text-[10px] bg-blue-100 dark:bg-blue-800 text-blue-700 dark:text-blue-300 px-1 rounded">HDR</span>'
                : '';

            $tbody .= "<td class=\"px-2 py-1 text-gray-400 whitespace-nowrap font-mono\">{$originalRowNum}{$badge}</td>";

            $row = $rows[$i];
            for ($c = 0; $c < $displayCols; $c++) {
                $value = htmlspecialchars(mb_substr($row[$c] ?? '', 0, 35));
                $html_class = $value === '' ? 'text-gray-300' : '';
                $tbody .= "<td class=\"px-2 py-1 whitespace-nowrap max-w-[180px] truncate {$html_class}\">{$value}</td>";
            }
            if ($maxCols > $displayCols) {
                $tbody .= '<td class="px-2 py-1 text-gray-400">…</td>';
            }

            $tbody .= '</tr>';
        }
        $tbody .= '</tbody>';

        $firstOriginal = $rowOrigins[0] ?? 1;
        $lastOriginal = $rowOrigins[$totalRows - 1] ?? $totalRows;

        $html = '<div x-data="{ page: 1, total: ' . $totalPages . ' }" class="space-y-2">';
        $html .= '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">';
        $html .= '<table class="min-w-full text-xs">' . $thead . $tbody . '</table>';
        $html .= '</div>';
        $html .= '<div class="flex items-center justify-between text-xs text-gray-500">';
        $html .= '<span>' . $totalRows . ' rows (Excel rows ' . $firstOriginal . '–' . $lastOriginal . '), ' . $maxCols . ' columns</span>';
        $html .= '<div class="flex items-center gap-2">';
        $html .= '<button type="button" x-on:click="page = Math.max(1, page - 1); $el.closest(\'[x-data]\').querySelectorAll(\'tbody tr\').forEach(r => r.style.display = r.dataset.page == page ? \'\' : \'none\')" x-bind:disabled="page === 1" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">← Prev</button>';
        $html .= '<span x-text="`Page ${page} of ${total}`">Page 1 of ' . $totalPages . '</span>';
        $html .= '<button type="button" x-on:click="page = Math.min(total, page + 1); $el.closest(\'[x-data]\').querySelectorAll(\'tbody tr\').forEach(r => r.style.display = r.dataset.page == page ? \'\' : \'none\')" x-bind:disabled="page === total" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">Next →</button>';
        $html .= '</div></div></div>';

        return $html;
    }

    protected static function buildPreviewTable(array $rows, int $headerRowNumber): string
    {
        $rowOrigins = self::getCache('row_origins', []);
        $maxPreviewRows = min(count($rows), max($headerRowNumber + 3, 8));
        $maxCols = 0;
        for ($i = 0; $i < $maxPreviewRows; $i++) {
            $maxCols = max($maxCols, count($rows[$i] ?? []));
        }

        $html = '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">';
        $html .= '<table class="min-w-full text-xs">';

        $html .= '<thead><tr class="bg-gray-50 dark:bg-gray-800">';
        $html .= '<th class="px-2 py-1 text-gray-400 font-normal">Row</th>';
        for ($c = 0; $c < $maxCols; $c++) {
            $html .= '<th class="px-2 py-1 text-gray-400 font-normal">' . self::columnLetter($c) . '</th>';
        }
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        for ($i = 0; $i < $maxPreviewRows; $i++) {
            // Show original Excel row number so users can reference it in start_row/end_row
            $originalRowNum = $rowOrigins[$i] ?? ($i + 1);
            $isHeader = ($originalRowNum === $headerRowNumber);
            $isSkipped = ($headerRowNumber > 0 && $originalRowNum < $headerRowNumber);

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

            $html .= "<td class=\"px-2 py-1 text-gray-400 whitespace-nowrap\">{$originalRowNum}{$labelBadge}</td>";

            $row = $rows[$i] ?? [];
            for ($c = 0; $c < $maxCols; $c++) {
                $value = htmlspecialchars(mb_substr($row[$c] ?? '', 0, 60));
                $html .= "<td class=\"px-2 py-1 max-w-[200px] truncate\">{$value}</td>";
            }

            $html .= '</tr>';
        }

        if (count($rows) > $maxPreviewRows) {
            $remaining = count($rows) - $maxPreviewRows;
            $lastOriginalRow = $rowOrigins[count($rows) - 1] ?? count($rows);
            $html .= "<tr><td colspan=\"" . ($maxCols + 1) . "\" class=\"px-2 py-1 text-gray-400 text-center\">... and {$remaining} more rows (last row: {$lastOriginalRow})</td></tr>";
        }

        $html .= '</tbody></table></div>';

        return $html;
    }
}
