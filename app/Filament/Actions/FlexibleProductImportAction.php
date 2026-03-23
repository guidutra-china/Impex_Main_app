<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
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
            'reference_code' => ['ref', 'code', 'código', 'codigo', 'sku', 'reference'],
            'unit_price' => $isClient
                ? ['selling price', 'sell price', 'preço venda', 'preco venda', 'client price']
                : ['purchase price', 'buy price', 'preço compra', 'preco compra', 'supplier price', 'cost', 'custo', 'fob'],
            'cross_unit_price' => $isClient
                ? ['purchase price', 'buy price', 'preço compra', 'preco compra', 'supplier price', 'cost', 'custo', 'fob']
                : ['selling price', 'sell price', 'preço venda', 'preco venda', 'client price'],
            'custom_price' => ['custom price', 'ci price', 'ci override', 'override', 'preço custom', 'preco ci'],
            'currency' => ['currency', 'moeda', 'curr'],
            'external_code' => $isClient
                ? ['client code', 'codigo cliente', 'external code']
                : ['supplier code', 'codigo fornecedor', 'external code'],
            'external_name' => $isClient
                ? ['client name', 'nome cliente', 'client product']
                : ['supplier name', 'nome fornecedor', 'supplier product'],
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
            'reference_code' => '',
            'unit_price' => '',
            'cross_unit_price' => '',
            'custom_price' => '',
            'currency' => 'USD',
            'external_code' => '',
            'external_name' => '',
            'external_description' => '',
            'moq' => '',
            'lead_time' => '',
            'material' => '',
            'specs' => '',
            'notes' => '',
        ];

        $fieldLabels = [
            'product_name' => 'Product Name',
            'reference_code' => 'Reference Code / SKU',
            'unit_price' => $isClient ? 'Selling Price (Client)' : 'Purchase Price (Supplier)',
            'cross_unit_price' => $isClient ? 'Purchase Price (Supplier)' : 'Selling Price (Client)',
            'custom_price' => 'Custom Price (CI Override)',
            'currency' => 'Currency',
            'external_code' => $isClient ? 'Client Code' : 'Supplier Code',
            'external_name' => $isClient ? 'Client Product Name' : 'Supplier Product Name',
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
                    ->label('Upload & Category')
                    ->description('Upload spreadsheet and select category')
                    ->schema([
                        Select::make('category_id')
                            ->label('Product Category')
                            ->options(fn () => Category::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                        Select::make('cross_company_id')
                            ->label($role === 'client' ? 'Also link to Supplier' : 'Also link to Client')
                            ->options(fn () => Company::orderBy('name')->pluck('name', 'id'))
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
                            ->disk('local')
                            ->directory('temp-imports')
                            ->helperText('Upload .xlsx or .xls file. Product images in the spreadsheet will be imported automatically.'),
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
                                foreach ($mapping as $field => $colIndex) {
                                    $set("col_{$field}", $colIndex);
                                }
                            }
                            \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: afterValidation COMPLETE');
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::error('Flexible import error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                            Notification::make()->title('Error reading file')->body($e->getMessage())->danger()->send();
                        }
                    }),
                Step::make('Map Columns')
                    ->label('Map Columns')
                    ->description('Match spreadsheet columns to product fields')
                    ->afterValidation(function (Get $get) use ($fieldDefaults) {
                        // Store mapping in cache for the action to use
                        $currentMapping = [];
                        foreach (array_keys($fieldDefaults) as $field) {
                            $value = $get("col_{$field}");
                            if ($value !== null && $value !== '') {
                                $currentMapping[$field] = $value;
                            }
                        }

                        self::putCache('mapping', [
                            'columns' => $currentMapping,
                            'header_row' => (int) ($get('header_row') ?? 1),
                        ]);
                    })
                    ->schema(function () use ($fieldLabels, $fieldDefaults, $fieldPatterns) {
                        \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: Step 2 schema() called');
                        $columnSelects = [];
                        foreach ($fieldLabels as $field => $label) {
                            $columnSelects[] = Select::make("col_{$field}")
                                ->label($label)
                                ->options(function (Get $get) {
                                    try {
                                        $rows = self::getCachedRows();
                                        if (empty($rows)) {
                                            return [];
                                        }

                                        $headerRow = (int) ($get('header_row') ?? 1);
                                        $headerData = self::getHeaderRow($rows, $headerRow);

                                        return self::buildColumnOptions($headerData);
                                    } catch (\Throwable $e) {
                                        \Illuminate\Support\Facades\Log::error('FLEXIBLE IMPORT: Select options error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

                                        return [];
                                    }
                                })
                                ->native(false)
                                ->placeholder('-- Skip --');
                        }

                        return [
                            Placeholder::make('file_preview')
                                ->label('File Preview')
                                ->content(function (Get $get) {
                                    try {
                                        $rows = self::getCachedRows();
                                        if (empty($rows)) {
                                            return new HtmlString('<p class="text-sm text-gray-400">No data found.</p>');
                                        }

                                        $images = self::getCache('images', []);
                                        $imageNote = count($images) > 0
                                            ? "<p class=\"text-sm text-green-600 mt-2\">📷 " . count($images) . " product image(s) detected — will be imported automatically.</p>"
                                            : '';

                                        return new HtmlString(
                                            self::buildPreviewTable($rows, (int) ($get('header_row') ?? 1))
                                            . $imageNote
                                        );
                                    } catch (\Throwable $e) {
                                        \Illuminate\Support\Facades\Log::error('FLEXIBLE IMPORT: Preview error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);

                                        return new HtmlString('<p class="text-red-500">Error: ' . e($e->getMessage()) . '</p>');
                                    }
                                }),
                            TextInput::make('header_row')
                                ->label('Header row number')
                                ->helperText('Which row contains the column headers? Set to 0 if no header row.')
                                ->numeric()
                                ->default(1)
                                ->minValue(0)
                                ->maxValue(50)
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(function ($state, Set $set) use ($fieldPatterns) {
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
                                    '<p class="text-sm text-gray-500 dark:text-gray-400">'
                                    . 'Match each field to a column. Only <strong>Product Name</strong> is required.'
                                    . '</p>'
                                )),
                            ...$columnSelects,
                        ];
                    }),
                Step::make('Confirm')
                    ->label('Preview & Confirm')
                    ->description('Review and finalize import')
                    ->schema([
                        Placeholder::make('import_preview')
                            ->label('')
                            ->content(function (Get $get) use ($fieldDefaults, $fieldLabels) {
                                $mapping = self::getCache('mapping', []);
                                $rows = self::getCachedRows();
                                $images = self::getCache('images', []);

                                if (empty($rows) || empty($mapping)) {
                                    return new HtmlString('<p class="text-red-500">No data available. Please go back and re-upload.</p>');
                                }

                                $items = self::applyMapping($rows, $mapping['columns'] ?? [], $mapping['header_row'] ?? 1, $fieldDefaults);
                                $imageCount = count($images);

                                $html = '<div class="space-y-3">';
                                $html .= '<p class="text-sm font-medium">Ready to import <strong>' . count($items) . '</strong> products.';
                                if ($imageCount > 0) {
                                    $html .= " {$imageCount} image(s) will be saved.";
                                }
                                $html .= '</p>';

                                // Show first 10 items as preview table
                                $mappedFields = array_keys($mapping['columns'] ?? []);
                                if (! empty($items) && ! empty($mappedFields)) {
                                    $previewCount = min(count($items), 10);
                                    $html .= '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">';
                                    $html .= '<table class="min-w-full text-xs">';
                                    $html .= '<thead><tr class="bg-gray-50 dark:bg-gray-800">';
                                    $html .= '<th class="px-2 py-1 text-gray-400">#</th>';
                                    foreach ($mappedFields as $field) {
                                        $html .= '<th class="px-2 py-1 text-left">' . e($fieldLabels[$field] ?? $field) . '</th>';
                                    }
                                    $html .= '</tr></thead><tbody>';
                                    for ($i = 0; $i < $previewCount; $i++) {
                                        $html .= '<tr class="border-t border-gray-100 dark:border-gray-700">';
                                        $html .= '<td class="px-2 py-1 text-gray-400">' . ($i + 1) . '</td>';
                                        foreach ($mappedFields as $field) {
                                            $val = htmlspecialchars(mb_substr($items[$i][$field] ?? '', 0, 40));
                                            $html .= '<td class="px-2 py-1">' . $val . '</td>';
                                        }
                                        $html .= '</tr>';
                                    }
                                    if (count($items) > $previewCount) {
                                        $html .= '<tr><td colspan="' . (count($mappedFields) + 1) . '" class="px-2 py-1 text-gray-400 text-center">... and ' . (count($items) - $previewCount) . ' more</td></tr>';
                                    }
                                    $html .= '</tbody></table></div>';
                                }

                                $html .= '</div>';

                                return new HtmlString($html);
                            }),
                    ]),
            ])
            ->action(function (array $data) use ($role, $getCompany, $fieldDefaults): void {
                $categoryId = $data['category_id'] ?? null;
                $crossCompanyId = $data['cross_company_id'] ?? null;
                $mapping = self::getCache('mapping', []);
                $rows = self::getCachedRows();
                $images = self::getCache('images', []);
                $headerRow = $mapping['header_row'] ?? 1;
                $items = self::applyMapping($rows, $mapping['columns'] ?? [], $headerRow, $fieldDefaults);

                if (empty($items) || ! $categoryId) {
                    Notification::make()->title('No items to import')->warning()->send();

                    return;
                }

                $category = Category::findOrFail($categoryId);
                /** @var Company $company */
                $company = $getCompany();
                $skuGenerator = app(GenerateProductSkuAction::class);

                $stats = ['created' => 0, 'updated' => 0, 'images' => 0, 'errors' => []];

                \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: action starting', [
                    'items' => count($items),
                    'images_keys' => array_keys($images),
                    'sample_source_rows' => array_slice(array_column($items, '_source_row'), 0, 5),
                ]);

                try {
                    DB::transaction(function () use ($items, $category, $company, $role, $skuGenerator, $images, $crossCompanyId, &$stats) {
                        foreach ($items as $item) {
                            $productName = $item['product_name'] ?? '';
                            if (empty($productName)) {
                                continue;
                            }

                            // Use _source_row (original spreadsheet row) to find the correct image
                            $sourceRow = $item['_source_row'] ?? null;
                            $imagePath = $sourceRow ? ($images[$sourceRow] ?? null) : null;

                            if ($sourceRow && $imagePath) {
                                \Illuminate\Support\Facades\Log::info("FLEXIBLE IMPORT: matched image for '{$productName}' at row {$sourceRow} → {$imagePath}");
                            } elseif ($sourceRow && ! $imagePath && ! empty($images)) {
                                \Illuminate\Support\Facades\Log::info("FLEXIBLE IMPORT: no image match for '{$productName}' at row {$sourceRow}, available image rows: " . implode(',', array_keys($images)));
                            }

                            // Use mapped reference_code as SKU if available, otherwise auto-generate
                            $sku = ! empty($item['reference_code'])
                                ? trim($item['reference_code'])
                                : $skuGenerator->execute($category->id);

                            // Find or create product — use withTrashed to avoid unique constraint conflicts
                            $existing = Product::withTrashed()->where('sku', $sku)->first()
                                ?? (! empty($item['reference_code']) ? Product::withTrashed()->where('reference_code', trim($item['reference_code']))->first() : null)
                                ?? Product::where('name', $productName)->first();

                            if ($existing) {
                                // Restore if soft-deleted
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

                            // Ensure company link
                            $pivotData = array_filter([
                                'role' => $role,
                                'external_code' => $item['external_code'] ?: null,
                                'external_name' => $item['external_name'] ?: null,
                                'external_description' => $item['external_description'] ?: null,
                                'unit_price' => ! empty($item['unit_price']) ? Money::toMinor((float) $item['unit_price']) : null,
                                'custom_price' => ! empty($item['custom_price']) ? Money::toMinor((float) $item['custom_price']) : null,
                                'currency_code' => ! empty($item['currency']) ? strtoupper($item['currency']) : null,
                                'avatar_path' => $imagePath,
                                'avatar_disk' => $imagePath ? 'public' : null,
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

                            // Cross-company link (optional)
                            if (! empty($crossCompanyId)) {
                                $crossRole = $role === 'client' ? 'supplier' : 'client';
                                $crossPivotData = array_filter([
                                    'role' => $crossRole,
                                    'unit_price' => ! empty($item['cross_unit_price']) ? Money::toMinor((float) $item['cross_unit_price']) : null,
                                    'currency_code' => ! empty($item['currency']) ? strtoupper($item['currency']) : null,
                                ], fn ($v) => $v !== null);

                                $existingCrossLink = CompanyProduct::where('product_id', $existing->id)
                                    ->where('company_id', $crossCompanyId)
                                    ->where('role', $crossRole)
                                    ->first();

                                if (! $existingCrossLink) {
                                    CompanyProduct::create(array_merge($crossPivotData, [
                                        'product_id' => $existing->id,
                                        'company_id' => $crossCompanyId,
                                    ]));
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

                    Notification::make()
                        ->title('Import Complete — ' . count($items) . ' products')
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

                // Clean up
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
                $filename = 'products/' . uniqid('import_') . '.' . $extension;
                Storage::disk('public')->put($filename, $imageData);
                $imagesByRow[$row] = $filename;
                \Illuminate\Support\Facades\Log::info("FLEXIBLE IMPORT: image extracted at row {$row} → {$filename}");
            }
        }

        return $imagesByRow;
    }

    protected static function getCachedRows(): array
    {
        $rows = self::getCache('rows', []);
        \Illuminate\Support\Facades\Log::info('FLEXIBLE IMPORT: getCachedRows count=' . count($rows));

        return $rows;
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

    protected static function applyMapping(array $rows, array $mapping, int $headerRowNumber, array $fieldDefaults): array
    {
        $startIndex = max(0, $headerRowNumber);
        $rowOrigins = self::getCache('row_origins', []);
        $items = [];

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
                // Use original spreadsheet row number for image matching
                $item['_source_row'] = $rowOrigins[$i] ?? ($i + 1);
                $items[] = $item;
            }
        }

        return $items;
    }

    protected static function buildPreviewTable(array $rows, int $headerRowNumber): string
    {
        $maxPreviewRows = min(count($rows), max($headerRowNumber + 3, 8));
        $maxCols = 0;
        for ($i = 0; $i < $maxPreviewRows; $i++) {
            $maxCols = max($maxCols, count($rows[$i] ?? []));
        }

        $html = '<div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">';
        $html .= '<table class="min-w-full text-xs">';

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
}
