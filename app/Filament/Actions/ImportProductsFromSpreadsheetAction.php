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
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

class ImportProductsFromSpreadsheetAction
{
    protected static function tempPath(string $suffix): string
    {
        return storage_path('app/private/product-import-' . session()->getId() . '-' . $suffix . '.json');
    }

    protected static function putCache(string $suffix, mixed $data): void
    {
        $dir = dirname(self::tempPath($suffix));
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(self::tempPath($suffix), json_encode($data, JSON_UNESCAPED_UNICODE));
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
                    ->label('Excel File')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ])
                    ->required()
                    ->maxSize(51200)
                    ->disk('local')
                    ->directory('temp-imports')
                    ->helperText('Upload .xlsx or .xls file (max 50MB).'),
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

                try {
                    $path = self::resolveUploadPath($get('spreadsheet'));
                    if (! $path) {
                        Notification::make()->title('Could not resolve file path')->danger()->send();

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
                    }

                    $spreadsheet->disconnectWorksheets();
                    unset($spreadsheet, $worksheet);

                    self::putCache('rows', $rows);
                    self::putCache('row_origins', $rowOrigins);
                    self::putCache('images', $images);

                    $set('header_row', '1');
                } catch (\Throwable $e) {
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
                $colMapping = [];
                for ($c = 0; $c < 15; $c++) {
                    $field = $get("col_map_{$c}");
                    if ($field && $field !== '' && $field !== 'skip') {
                        $colMapping[$field] = (string) $c;
                    }
                }

                self::putCache('mapping', [
                    'columns' => $colMapping,
                    'header_row' => $headerRow,
                    'blocks' => array_values($get('import_blocks') ?? []),
                    'currency_code' => $get('currency_code') ?? 'USD',
                    'custom_price_formula' => $get('custom_price_formula'),
                    'client_company_id' => $get('client_company_id'),
                    'supplier_company_id' => $get('supplier_company_id'),
                ]);
            })
            ->schema(function () {
                $rows = self::getCache('rows', []);
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
                    Placeholder::make('column_mapping_label')
                        ->content(new HtmlString(
                            '<p class="text-sm font-medium text-gray-700 dark:text-gray-300">'
                            . 'Assign a field to each spreadsheet column:'
                            . '</p>'
                        ))
                        ->columnSpanFull(),
                    ...self::buildInvertedColumnSelects(),
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
                                ->options(fn () => Category::active()->orderBy('name')->pluck('name', 'id'))
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
            ->columns(3);
    }

    protected static function buildInvertedColumnSelects(): array
    {
        $rows = self::getCache('rows', []);
        if (empty($rows)) {
            return [];
        }

        $headerData = $rows[0] ?? [];
        $displayCols = min(count($headerData), 15);

        // Base product fields
        $fieldOptions = [
            'skip' => '— Skip —',
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

        // Add category attributes from all active categories
        $attrOptions = [];
        $categories = Category::active()->with('categoryAttributes')->get();
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
            $fieldOptions['Attributes'] = $attrOptions;
        }

        $selects = [];
        for ($c = 0; $c < $displayCols; $c++) {
            $letter = self::columnLetter($c);
            $headerLabel = $headerData[$c] ?? '';
            $label = "Col {$letter}" . ($headerLabel ? ": {$headerLabel}" : '');

            $selects[] = Select::make("col_map_{$c}")
                ->label(mb_substr($label, 0, 40))
                ->options($fieldOptions)
                ->default('skip')
                ->native(false);
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
                    ->content(function () {
                        $mapping = self::getCache('mapping', []);
                        $rows = self::getCache('rows', []);
                        $images = self::getCache('images', []);
                        $blocks = $mapping['blocks'] ?? [];

                        if (empty($rows) || empty($blocks)) {
                            return new HtmlString('<p class="text-red-500">No data. Go back and configure blocks.</p>');
                        }

                        $headerRow = $mapping['header_row'] ?? 1;
                        $colMapping = $mapping['columns'] ?? [];
                        $totalProducts = 0;
                        $totalImages = 0;

                        // Get mapped field labels for preview
                        $previewFields = array_intersect_key(
                            array_flip($colMapping),
                            array_flip(array_values($colMapping))
                        );

                        $html = '<div class="space-y-4">';

                        // Show company links
                        $clientId = $mapping['client_company_id'] ?? null;
                        $supplierId = $mapping['supplier_company_id'] ?? null;
                        if ($clientId || $supplierId) {
                            $html .= '<div class="text-sm text-gray-600 dark:text-gray-400">';
                            if ($clientId) {
                                $html .= 'Client: <strong>' . e(Company::find($clientId)?->name ?? '—') . '</strong> ';
                            }
                            if ($supplierId) {
                                $html .= 'Supplier: <strong>' . e(Company::find($supplierId)?->name ?? '—') . '</strong>';
                            }
                            $html .= ' | Currency: <strong>' . e($mapping['currency_code'] ?? 'USD') . '</strong>';
                            $formula = $mapping['custom_price_formula'] ?? null;
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
        $mapping = self::getCache('mapping', []);
        $rows = self::getCache('rows', []);
        $images = self::getCache('images', []);
        $headerRow = $mapping['header_row'] ?? 1;
        $blocks = $mapping['blocks'] ?? [];
        $currencyCode = $mapping['currency_code'] ?? 'USD';
        $customPriceFormula = $mapping['custom_price_formula'] ?? null;
        $clientCompanyId = $mapping['client_company_id'] ?? null;
        $supplierCompanyId = $mapping['supplier_company_id'] ?? null;
        $colMapping = $mapping['columns'] ?? [];

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
                    try {
                        $tmpFile = TemporaryUploadedFile::createFromLivewire(substr($value, strlen('livewire-file:')));
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
            try {
                $tmpFile = TemporaryUploadedFile::createFromLivewire(substr($filePath, strlen('livewire-file:')));
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

        foreach ($worksheet->getDrawingCollection() as $drawing) {
            $coordinate = $drawing->getCoordinates();
            if (empty($coordinate)) {
                continue;
            }

            $row = (int) preg_replace('/[A-Z]+/i', '', $coordinate);
            if ($row < 1 || isset($imagesByRow[$row])) {
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
                    $imageData = str_starts_with($sourcePath, 'zip://')
                        ? @file_get_contents($sourcePath)
                        : (file_exists($sourcePath) ? file_get_contents($sourcePath) : null);
                    if ($imageData) {
                        $extension = strtolower($drawing->getExtension() ?: pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'png');
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }

            if ($imageData && strlen($imageData) > 100) {
                $filename = 'products/' . uniqid('import_') . '.' . $extension;
                Storage::disk('public')->put($filename, $imageData);
                $imagesByRow[$row] = $filename;
            }
        }

        return $imagesByRow;
    }
}
