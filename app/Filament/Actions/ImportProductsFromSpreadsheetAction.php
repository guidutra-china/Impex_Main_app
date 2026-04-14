<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductCosting;
use App\Domain\Catalog\Models\ProductPackaging;
use App\Domain\Catalog\Models\ProductSpecification;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Actions\Concerns\HasImportCache;
use App\Filament\Actions\Concerns\HasSpreadsheetImages;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportProductsFromSpreadsheetAction
{
    use HasImportCache;
    use HasSpreadsheetImages;

    protected const CACHE_PREFIX = 'product-import';

    protected static function forgetCache(): void
    {
        self::forgetCacheKeys(['rows', 'images', 'mapping', 'row_origins']);
    }

    /**
     * Parse a spreadsheet file and store rows/images/origins in cache.
     * Called from Step 1 afterValidation, and as fallback from Step 2 schema.
     */
    protected static function parseAndCacheSpreadsheet(mixed $rawFile): void
    {
        $path = self::resolveUploadPath($rawFile);
        \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: parseAndCache', ['path' => $path]);

        if (! $path) {
            return;
        }

        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $rawData = $worksheet->toArray(null, true, false, false);

        $rows = [];
        $rowOrigins = [];
        $maxRows = min(count($rawData), 5000);
        for ($r = 0; $r < $maxRows; $r++) {
            $values = array_map(fn ($v) => trim((string) ($v ?? '')), $rawData[$r]);
            if (implode('', $values) !== '') {
                $rowOrigins[count($rows)] = $r + 1;
                $rows[] = $values;
            }
        }
        unset($rawData);

        $images = [];
        try {
            $images = self::extractImagesByRow($worksheet);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('SPREADSHEET IMPORT: image extraction failed', ['error' => $e->getMessage()]);
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $worksheet);

        self::putCache('rows', $rows);
        self::putCache('row_origins', $rowOrigins);
        self::putCache('images', $images);

        \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: cached', [
            'rows' => count($rows),
            'images' => count($images),
        ]);
    }

    public static function make(): Action
    {
        return Action::make('importFromSpreadsheet')
            ->label('Import from Spreadsheet')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('info')
            ->modalWidth('7xl')
            ->modalHeading('Import Products from Spreadsheet')
            ->visible(fn () => auth()->user()?->can('edit-products'))
            ->steps([
                self::uploadStep(),
                self::mapStep(),
                self::confirmStep(),
            ])
            ->action(function (array $data): void {
                self::executeImport($data);
            });
    }

    // ── Step 1: Upload ──

    protected static function uploadStep(): Step
    {
        return Step::make('Upload')
            ->label('Upload & Companies')
            ->description('Upload spreadsheet and select companies to link')
            ->schema([
                FileUpload::make('spreadsheet')
                    ->label('Spreadsheet File')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'text/plain',
                        'text/tab-separated-values',
                    ])
                    ->required()
                    ->maxSize(51200)
                    ->disk('local')
                    ->directory('temp-imports')
                    ->helperText('Upload .xlsx, .xls, or .csv file (max 50MB).'),
                Select::make('client_company_id')
                    ->label('Link to Client')
                    ->options(fn () => Company::withRole(CompanyRole::CLIENT)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('— None —'),
                Select::make('supplier_company_id')
                    ->label('Link to Supplier')
                    ->options(fn () => Company::withRole(CompanyRole::SUPPLIER)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('— None —'),
                Select::make('currency_code')
                    ->label('Currency')
                    ->options(fn () => \App\Domain\Settings\Models\Currency::orderBy('code')->pluck('code', 'code'))
                    ->searchable()
                    ->default('USD')
                    ->required(),
                TextInput::make('custom_price_formula')
                    ->label('Custom Price Formula')
                    ->placeholder('e.g. *0.70, *1.30, +5, -2.50')
                    ->helperText('Calculate Custom Price from Unit Price. Use *0.70 for 70%, *1.30 for 130% markup, +5 to add, -2.50 to subtract.')
                    ->maxLength(20),
            ])
            ->afterValidation(function (Get $get, Set $set) {
                ini_set('memory_limit', '512M');
                \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: Step 1 afterValidation START');

                try {
                    self::parseAndCacheSpreadsheet($get('spreadsheet'));
                    $set('header_row', '1');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error('SPREADSHEET IMPORT: Step 1 FAILED', [
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);
                    Notification::make()->title('Error reading file')->body($e->getMessage())->danger()->send();
                }
            });
    }

    // ── Step 2: Map Columns (inverted) ──

    protected static function mapStep(): Step
    {
        return Step::make('Map & Configure')
            ->label('Map & Configure')
            ->description('Assign fields to columns and define import blocks')
            ->afterValidation(function (Get $get) {
                $rows = self::getCache('rows', []);
                $headerRow = (int) ($get('header_row') ?? 1);
                $rowOrigins = self::getCache('row_origins', []);
                $headerData = $rows[max(0, $headerRow - 1)] ?? [];

                // Collect inverted mapping from col_map_X fields (max 15, matching schema)
                // Each column can map to multiple fields (e.g. same column → model_number + reference_code)
                $colMapping = [];
                for ($c = 0; $c < 15; $c++) {
                    $fields = $get("col_map_{$c}");
                    if (empty($fields)) {
                        continue;
                    }
                    // $fields is now an array (multiple select)
                    if (is_array($fields)) {
                        foreach ($fields as $field) {
                            if ($field && $field !== 'skip') {
                                $colMapping[$field] = (string) $c;
                            }
                        }
                    } elseif ($fields !== 'skip') {
                        $colMapping[$fields] = (string) $c;
                    }
                }

                $blocks = array_values($get('import_blocks') ?? []);

                \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: mapping collected', [
                    'colMapping' => $colMapping,
                    'headerRow' => $headerRow,
                    'blocks' => $blocks,
                    'total_rows' => count($rows),
                ]);

                self::putCache('mapping', [
                    'columns' => $colMapping,
                    'header_row' => $headerRow,
                    'blocks' => $blocks,
                    'currency_code' => $get('currency_code') ?? 'USD',
                    'custom_price_formula' => $get('custom_price_formula'),
                    'client_company_id' => $get('client_company_id'),
                    'supplier_company_id' => $get('supplier_company_id'),
                ]);
            })
            ->schema(function (Get $get) {
                $rows = self::getCache('rows', []);

                // Fallback: if Step 1 afterValidation didn't populate cache, parse now
                if (empty($rows)) {
                    \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: Step 2 fallback — parsing spreadsheet');
                    try {
                        self::parseAndCacheSpreadsheet($get('spreadsheet'));
                        $rows = self::getCache('rows', []);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::error('SPREADSHEET IMPORT: fallback parse failed', ['error' => $e->getMessage()]);
                    }
                }

                $rowOrigins = self::getCache('row_origins', []);
                $lastRow = ! empty($rowOrigins) ? max($rowOrigins) : count($rows);

                return [
                    Placeholder::make('file_preview')
                        ->label('Spreadsheet — use row numbers for Start/End Row below')
                        ->content(function (Get $get) {
                            try {
                                $rows = self::getCache('rows', []);
                                if (empty($rows)) {
                                    return new HtmlString('<p class="text-sm text-gray-400">No data found.</p>');
                                }

                                return new HtmlString(
                                    self::buildPaginatedPreview($rows, (int) ($get('header_row') ?? 1))
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

                    // ── Category blocks first (so attributes can filter by selected categories) ──
                    Placeholder::make('blocks_divider')
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
                                ->required()
                                ->live(),
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
                        ->live()
                        ->columnSpanFull(),

                    // ── Column mapping (attributes filtered by selected categories) ──
                    Placeholder::make('column_mapping_label')
                        ->content(new HtmlString(
                            '<hr class="my-2 border-gray-200 dark:border-gray-700">'
                            . '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">'
                            . 'Assign fields to each spreadsheet column:'
                            . '</p>'
                        ))
                        ->columnSpanFull(),
                    // Always create 15 selects to keep form state stable across wizard steps
                    ...self::buildFixedColumnSelects($rows),
                ];
            })
            ->columns(3);
    }

    /**
     * Build the base field options (without attributes — those are dynamic).
     */
    protected static function baseFieldOptions(): array
    {
        return [
            'Product' => [
                'name' => 'Product Name',
                'commercial_name' => 'Commercial Name',
                'model_number' => 'Model Number',
                'product_family' => 'Product Family',
                'reference_code' => 'Reference Code',
                'hs_code' => 'HS Code',
                'origin_country' => 'Origin Country',
                'brand' => 'Brand',
                'moq' => 'MOQ',
                'moq_unit' => 'MOQ Unit',
                'lead_time_days' => 'Lead Time (days)',
                'description' => 'Description',
            ],
            'Specification' => [
                'spec_net_weight' => 'Net Weight (kg)',
                'spec_length' => 'Length (cm)',
                'spec_width' => 'Width (cm)',
                'spec_height' => 'Height (cm)',
                'spec_material' => 'Material',
                'spec_color' => 'Color',
                'spec_finish' => 'Finish',
            ],
            'Packaging' => [
                'pkg_pcs_per_carton' => 'Pcs per Carton',
                'pkg_carton_length' => 'Carton Length (cm)',
                'pkg_carton_width' => 'Carton Width (cm)',
                'pkg_carton_height' => 'Carton Height (cm)',
                'pkg_carton_weight' => 'Carton GW (kg)',
                'pkg_carton_net_weight' => 'Carton NW (kg)',
                'pkg_carton_cbm' => 'Carton CBM',
            ],
            'Costing' => [
                'cost_base_price' => 'Base Price',
            ],
            'Company Link' => [
                'external_code' => 'External Code',
                'external_name' => 'External Product Name',
                'external_description' => 'Invoice Description',
                'unit_price' => 'Unit Price',
                'custom_price' => 'Custom Price (CI Override)',
            ],
        ];
    }

    /**
     * Build field options including attributes filtered by the given category IDs.
     * Returns grouped array with category-filtered attributes.
     */
    protected static function fieldOptionsForCategories(array $categoryIds): array
    {
        $fieldOptions = self::baseFieldOptions();

        if (! empty($categoryIds)) {
            $attrOptions = [];
            $categories = Category::whereIn('id', $categoryIds)
                ->with('categoryAttributes')
                ->get();

            foreach ($categories as $cat) {
                foreach ($cat->categoryAttributes as $attr) {
                    $key = "attr_{$attr->id}";
                    if (! isset($attrOptions[$key])) {
                        $label = $attr->name . ($attr->unit ? " ({$attr->unit})" : '');
                        $attrOptions[$key] = $label;
                    }
                }
            }

            if (! empty($attrOptions)) {
                asort($attrOptions);
                $fieldOptions['Attributes'] = $attrOptions;
            }
        }

        return $fieldOptions;
    }

    /**
     * Build 15 fixed column selects. Visible ones show header labels,
     * extras are hidden. Always creates all 15 to keep form state stable.
     */
    protected static function buildFixedColumnSelects(array $rows): array
    {
        $headerData = $rows[0] ?? [];
        $displayCols = min(count($headerData), 15);

        $selects = [];
        for ($c = 0; $c < 15; $c++) {
            $letter = self::columnLetter($c);
            $headerLabel = $headerData[$c] ?? '';
            $label = "Col {$letter}" . ($headerLabel ? ": {$headerLabel}" : '');

            $select = Select::make("col_map_{$c}")
                ->options(function (Get $get) {
                    $blocks = $get('import_blocks') ?? [];
                    $categoryIds = collect($blocks)
                        ->pluck('category_id')
                        ->filter()
                        ->unique()
                        ->values()
                        ->toArray();

                    return self::fieldOptionsForCategories($categoryIds);
                })
                ->multiple()
                ->searchable()
                ->default([])
                ->placeholder('— Skip —')
                ->native(false);

            if ($c < $displayCols) {
                $select->label(mb_substr($label, 0, 40));
            } else {
                $select->hidden();
            }

            $selects[] = $select;
        }

        return $selects;
    }

    // ── Step 3: Confirm ──

    protected static function confirmStep(): Step
    {
        return Step::make('Confirm')
            ->label('Preview & Confirm')
            ->description('Review and finalize import')
            ->schema([
                Placeholder::make('import_preview')
                    ->label('')
                    ->content(function (Get $get) {
                        $rows = self::getCache('rows', []);
                        $images = self::getCache('images', []);

                        // Read mapping from form data (same as executeImport)
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
                        $blocks = array_values($get('import_blocks') ?? []);
                        $headerRow = (int) ($get('header_row') ?? 1);

                        if (empty($rows) || empty($blocks)) {
                            return new HtmlString('<p class="text-red-500">No data. Go back and configure blocks.</p>');
                        }

                        $totalProducts = 0;
                        $totalImages = 0;

                        $html = '<div class="space-y-4">';

                        // Show company links
                        $clientId = $get('client_company_id');
                        $supplierId = $get('supplier_company_id');
                        if ($clientId || $supplierId) {
                            $html .= '<div class="text-sm text-gray-600 dark:text-gray-400">';
                            if ($clientId) {
                                $html .= 'Client: <strong>' . e(Company::find($clientId)?->name ?? '—') . '</strong> ';
                            }
                            if ($supplierId) {
                                $html .= 'Supplier: <strong>' . e(Company::find($supplierId)?->name ?? '—') . '</strong>';
                            }
                            $html .= ' | Currency: <strong>' . e($get('currency_code') ?? 'USD') . '</strong>';
                            $formula = $get('custom_price_formula');
                            if ($formula) {
                                $html .= ' | Custom Price: <strong>Unit Price ' . e($formula) . '</strong>';
                            }
                            $html .= '</div>';
                        }

                        foreach ($blocks as $idx => $block) {
                            $categoryId = $block['category_id'] ?? null;
                            $startRow = (int) ($block['start_row'] ?? 1);
                            $endRow = (int) ($block['end_row'] ?? count($rows));
                            $categoryName = $categoryId ? (Category::find($categoryId)?->name ?? 'Unknown') : 'No category';
                            $blockFamily = $block['product_family'] ?? null;

                            $items = self::applyInvertedMapping($rows, $colMapping, $headerRow, $startRow, $endRow);
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

                            // Preview first 5 items
                            $mappedFields = array_keys($colMapping);
                            if (! empty($items) && ! empty($mappedFields)) {
                                $showFields = array_slice($mappedFields, 0, 6);
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
            ]);
    }

    // ── Import execution ──

    protected static function executeImport(array $data): void
    {
        $rows = self::getCache('rows', []);
        $images = self::getCache('images', []);

        // Ensure spreadsheet is parsed (fallback if cache was lost)
        if (empty($rows)) {
            self::parseAndCacheSpreadsheet($data['spreadsheet'] ?? null);
            $rows = self::getCache('rows', []);
            $images = self::getCache('images', []);
        }

        // Collect mapping directly from form data (more reliable than cache)
        $dataKeys = array_filter(array_keys($data), fn ($k) => str_starts_with($k, 'col_map_'));
        \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: form data keys', [
            'all_keys' => array_keys($data),
            'col_map_keys' => $dataKeys,
            'col_map_values' => array_intersect_key($data, array_flip($dataKeys)),
        ]);

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
        $clientCompanyId = $data['client_company_id'] ?? null;
        $supplierCompanyId = $data['supplier_company_id'] ?? null;

        \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: executeImport', [
            'colMapping' => $colMapping,
            'headerRow' => $headerRow,
            'blocks' => $blocks,
            'rows_count' => count($rows),
            'images_count' => count($images),
        ]);

        if (empty($blocks)) {
            Notification::make()->title('No import blocks defined')->warning()->send();

            return;
        }

        $skuGenerator = app(GenerateProductSkuAction::class);
        $stats = ['created' => 0, 'updated' => 0, 'images' => 0, 'errors' => []];
        $totalItems = 0;

        try {
            DB::transaction(function () use ($blocks, $rows, $colMapping, $headerRow, $skuGenerator, $images, $clientCompanyId, $supplierCompanyId, $currencyCode, $customPriceFormula, &$stats, &$totalItems) {
                foreach ($blocks as $block) {
                    $categoryId = $block['category_id'] ?? null;
                    $startRow = (int) ($block['start_row'] ?? 1);
                    $endRow = (int) ($block['end_row'] ?? count($rows));
                    $blockFamily = $block['product_family'] ?? null;

                    if (! $categoryId) {
                        continue;
                    }

                    $category = Category::findOrFail($categoryId);
                    $items = self::applyInvertedMapping($rows, $colMapping, $headerRow, $startRow, $endRow);
                    $totalItems += count($items);

                    \Illuminate\Support\Facades\Log::info('SPREADSHEET IMPORT: block processed', [
                        'category' => $category->name,
                        'startRow' => $startRow,
                        'endRow' => $endRow,
                        'items_found' => count($items),
                        'sample_item' => $items[0] ?? 'EMPTY',
                        'row_origins_sample' => array_slice(self::getCache('row_origins', []), 0, 5, true),
                    ]);

                    foreach ($items as $item) {
                        // Auto-generate name: Category + Model Number (same as product form)
                        $productName = $item['name'] ?? '';
                        if (empty($productName)) {
                            $modelNumber = $item['model_number'] ?? '';
                            $productName = $modelNumber
                                ? $category->name . ' ' . $modelNumber
                                : $category->name;
                        }

                        if (empty(trim($productName))) {
                            continue;
                        }

                        $sourceRow = $item['_source_row'] ?? null;
                        $imagePath = $sourceRow ? ($images[$sourceRow] ?? null) : null;

                        $sku = ! empty($item['reference_code'])
                            ? trim($item['reference_code'])
                            : $skuGenerator->execute($category->id);

                        // Find existing
                        $existing = Product::withTrashed()->where('sku', $sku)->first()
                            ?? (! empty($item['reference_code']) ? Product::withTrashed()->where('reference_code', trim($item['reference_code']))->first() : null)
                            ?? Product::where('name', $productName)->first();

                        if ($existing) {
                            if ($existing->trashed()) {
                                $existing->restore();
                            }
                            if ($imagePath && ! $existing->avatar) {
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
                                'hs_code' => $item['hs_code'] ?? null,
                                'origin_country' => $item['origin_country'] ?? null,
                                'brand' => $item['brand'] ?? null,
                                'moq' => ! empty($item['moq']) ? (int) $item['moq'] : null,
                                'moq_unit' => $item['moq_unit'] ?? null,
                                'lead_time_days' => ! empty($item['lead_time_days']) ? (int) $item['lead_time_days'] : null,
                                'description' => $item['description'] ?? null,
                                'avatar' => $imagePath,
                            ]);
                            $stats['created']++;
                            if ($imagePath) {
                                $stats['images']++;
                            }
                        }

                        // Upsert specification
                        self::upsertSpec($existing, $item);
                        // Upsert packaging
                        self::upsertPackaging($existing, $item);
                        // Upsert costing
                        self::upsertCosting($existing, $item);
                        // Upsert category attributes
                        self::upsertAttributes($existing, $item, $category);

                        // Link to client
                        if ($clientCompanyId) {
                            self::ensureCompanyLink($existing, (int) $clientCompanyId, 'client', $item, $currencyCode, $customPriceFormula);
                        }

                        // Link to supplier
                        if ($supplierCompanyId) {
                            self::ensureCompanyLink($existing, (int) $supplierCompanyId, 'supplier', $item, $currencyCode, $customPriceFormula);
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

            $blockSummary = count($blocks) > 1 ? ' across ' . count($blocks) . ' categories' : '';

            Notification::make()
                ->title("Import Complete — {$totalItems} products{$blockSummary}")
                ->body(implode(', ', $parts) ?: 'Done.')
                ->success()
                ->send();
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error importing products')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        self::forgetCache();
    }

    // ── Upsert helpers ──

    protected static function upsertSpec(Product $product, array $item): void
    {
        $data = array_filter([
            'net_weight' => self::toDecimal($item['spec_net_weight'] ?? null),
            'length' => self::toDecimal($item['spec_length'] ?? null),
            'width' => self::toDecimal($item['spec_width'] ?? null),
            'height' => self::toDecimal($item['spec_height'] ?? null),
            'material' => $item['spec_material'] ?? null,
            'color' => $item['spec_color'] ?? null,
            'finish' => $item['spec_finish'] ?? null,
        ], fn ($v) => $v !== null);

        if (! empty($data)) {
            ProductSpecification::updateOrCreate(['product_id' => $product->id], $data);
        }
    }

    protected static function upsertPackaging(Product $product, array $item): void
    {
        $data = array_filter([
            'pcs_per_carton' => ! empty($item['pkg_pcs_per_carton']) ? (int) $item['pkg_pcs_per_carton'] : null,
            'carton_length' => self::toDecimal($item['pkg_carton_length'] ?? null),
            'carton_width' => self::toDecimal($item['pkg_carton_width'] ?? null),
            'carton_height' => self::toDecimal($item['pkg_carton_height'] ?? null),
            'carton_weight' => self::toDecimal($item['pkg_carton_weight'] ?? null),
            'carton_net_weight' => self::toDecimal($item['pkg_carton_net_weight'] ?? null),
            'carton_cbm' => self::toDecimal($item['pkg_carton_cbm'] ?? null),
        ], fn ($v) => $v !== null);

        if (! empty($data)) {
            ProductPackaging::updateOrCreate(['product_id' => $product->id], $data);
        }
    }

    protected static function upsertCosting(Product $product, array $item): void
    {
        if (empty($item['cost_base_price'])) {
            return;
        }

        ProductCosting::updateOrCreate(
            ['product_id' => $product->id],
            ['base_price' => Money::toMinor($item['cost_base_price'])],
        );
    }

    protected static function upsertAttributes(Product $product, array $item, Category $category): void
    {
        foreach ($item as $key => $value) {
            if (! str_starts_with($key, 'attr_') || $value === null || $value === '') {
                continue;
            }

            $attrId = (int) substr($key, 5);
            ProductAttributeValue::updateOrCreate(
                ['product_id' => $product->id, 'category_attribute_id' => $attrId],
                ['value' => (string) $value],
            );
        }
    }

    protected static function ensureCompanyLink(Product $product, int $companyId, string $role, array $item, string $currencyCode, ?string $formula = null): void
    {
        $unitPrice = ! empty($item['unit_price']) ? (float) $item['unit_price'] : null;

        // Calculate custom_price: explicit value > formula > null
        $customPrice = ! empty($item['custom_price']) ? (float) $item['custom_price'] : null;
        if ($customPrice === null && $unitPrice !== null && $formula) {
            $customPrice = self::applyFormula($unitPrice, $formula);
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

        $existing = CompanyProduct::where('product_id', $product->id)
            ->where('company_id', $companyId)
            ->where('role', $role)
            ->first();

        if ($existing) {
            $existing->update($pivotData);
        } else {
            CompanyProduct::create(array_merge($pivotData, [
                'product_id' => $product->id,
                'company_id' => $companyId,
            ]));
        }
    }

    // ── Mapping ──

    protected static function applyInvertedMapping(array $rows, array $colMapping, int $headerRow, int $startRow, int $endRow): array
    {
        $rowOrigins = self::getCache('row_origins', []);
        $headerOriginalRow = $rowOrigins[$headerRow - 1] ?? $headerRow;
        $items = [];

        for ($i = 0; $i < count($rows); $i++) {
            $originalRow = $rowOrigins[$i] ?? ($i + 1);

            if ($originalRow === $headerOriginalRow) {
                continue;
            }
            if ($originalRow < $startRow || $originalRow > $endRow) {
                continue;
            }

            $row = $rows[$i];
            $item = [];
            $hasContent = false;

            foreach ($colMapping as $field => $colIndex) {
                $value = isset($row[(int) $colIndex]) ? trim($row[(int) $colIndex]) : '';
                if ($value !== '') {
                    $item[$field] = $value;
                    $hasContent = true;
                }
            }

            if ($hasContent) {
                $item['_source_row'] = $originalRow;
                $items[] = $item;
            }
        }

        return $items;
    }

    // ── Preview ──

    protected static function buildPaginatedPreview(array $rows, int $headerRowNumber): string
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

        $thead = '<thead class="sticky top-0 z-10"><tr class="bg-gray-50 dark:bg-gray-800">';
        $thead .= '<th class="px-2 py-1 text-gray-400 font-normal text-left">Row</th>';
        for ($c = 0; $c < $displayCols; $c++) {
            $thead .= '<th class="px-2 py-1 text-gray-400 font-normal text-left">' . self::columnLetter($c) . '</th>';
        }
        if ($maxCols > $displayCols) {
            $thead .= '<th class="px-2 py-1 text-gray-400 font-normal">…</th>';
        }
        $thead .= '</tr></thead>';

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
                $cls = $value === '' ? 'text-gray-300' : '';
                $tbody .= "<td class=\"px-2 py-1 whitespace-nowrap max-w-[180px] truncate {$cls}\">{$value}</td>";
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
        $html .= '<span>' . $totalRows . ' rows (Excel rows ' . $firstOriginal . '–' . $lastOriginal . ')</span>';
        $html .= '<div class="flex items-center gap-2">';
        $html .= '<button type="button" x-on:click="page = Math.max(1, page - 1); $el.closest(\'[x-data]\').querySelectorAll(\'tbody tr\').forEach(r => r.style.display = r.dataset.page == page ? \'\' : \'none\')" x-bind:disabled="page === 1" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">← Prev</button>';
        $html .= '<span x-text="`Page ${page} of ${total}`">Page 1 of ' . $totalPages . '</span>';
        $html .= '<button type="button" x-on:click="page = Math.min(total, page + 1); $el.closest(\'[x-data]\').querySelectorAll(\'tbody tr\').forEach(r => r.style.display = r.dataset.page == page ? \'\' : \'none\')" x-bind:disabled="page === total" class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 disabled:cursor-not-allowed">Next →</button>';
        $html .= '</div></div></div>';

        return $html;
    }

    // ── Helpers ──

    protected static function fieldLabel(string $field): string
    {
        $labels = [
            'name' => 'Product Name', 'commercial_name' => 'Commercial Name',
            'model_number' => 'Model Number', 'product_family' => 'Product Family',
            'reference_code' => 'Reference Code', 'hs_code' => 'HS Code',
            'origin_country' => 'Origin', 'brand' => 'Brand',
            'moq' => 'MOQ', 'moq_unit' => 'MOQ Unit', 'lead_time_days' => 'Lead Time',
            'description' => 'Description',
            'spec_net_weight' => 'Net Weight', 'spec_length' => 'Length',
            'spec_width' => 'Width', 'spec_height' => 'Height',
            'spec_material' => 'Material', 'spec_color' => 'Color', 'spec_finish' => 'Finish',
            'pkg_pcs_per_carton' => 'Pcs/Carton', 'pkg_carton_length' => 'Carton L',
            'pkg_carton_width' => 'Carton W', 'pkg_carton_height' => 'Carton H',
            'pkg_carton_weight' => 'Carton GW', 'pkg_carton_net_weight' => 'Carton NW',
            'pkg_carton_cbm' => 'CBM',
            'cost_base_price' => 'Base Price',
            'external_code' => 'Ext. Code', 'external_name' => 'Ext. Name',
            'external_description' => 'Invoice Desc', 'unit_price' => 'Unit Price',
            'custom_price' => 'Custom Price',
        ];

        if (str_starts_with($field, 'attr_')) {
            $attrId = (int) substr($field, 5);
            $attr = \App\Domain\Catalog\Models\CategoryAttribute::find($attrId);

            return $attr ? $attr->name : $field;
        }

        return $labels[$field] ?? $field;
    }

    protected static function applyFormula(float $baseValue, string $formula): ?float
    {
        $formula = trim($formula);
        if ($formula === '') {
            return null;
        }

        $operator = $formula[0];
        $operand = (float) substr($formula, 1);

        return match ($operator) {
            '*' => round($baseValue * $operand, 4),
            '+' => round($baseValue + $operand, 4),
            '-' => round($baseValue - $operand, 4),
            '/' => $operand != 0 ? round($baseValue / $operand, 4) : null,
            default => null,
        };
    }

    protected static function toDecimal(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
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
}
