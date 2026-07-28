<?php

namespace App\Domain\Catalog\Actions\Import;

use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\ImportLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductCosting;
use App\Domain\Catalog\Models\ProductPackaging;
use App\Domain\Catalog\Models\ProductSpecification;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Settings\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

class ProductImportService
{
    private GenerateProductImportTemplate $templateGenerator;

    public function __construct()
    {
        $this->templateGenerator = new GenerateProductImportTemplate;
    }

    public function parseFile(string $filePath, Category $category, string $role = 'client'): array
    {
        ini_set('memory_limit', '512M');

        // Load spreadsheet once and reuse for both cross-column detection and parsing
        $spreadsheet = IOFactory::load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();

        $singleColumnCount = count($this->templateGenerator->buildColumnMap($category, $role, false));
        $actualColumnCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $worksheet->getHighestColumn()
        );
        $hasCross = $actualColumnCount > $singleColumnCount;

        $columnMap = $this->templateGenerator->buildColumnMap($category, $role, $hasCross);
        $columnKeys = array_keys($columnMap);

        // Extract images mapped by row number
        $imagesByRow = $this->extractImagesByRow($worksheet);

        $rows = [];

        foreach ($worksheet->getRowIterator(4) as $row) { // Data starts at row 4
            $rowIndex = $row->getRowIndex();
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);

            $values = [];
            foreach ($cellIterator as $cell) {
                $values[] = $cell->getValue();
            }

            if ($this->isEmptyRow($values)) {
                continue;
            }

            $mapped = [];
            foreach ($columnKeys as $i => $key) {
                $mapped[$key] = isset($values[$i]) ? $this->cleanValue($values[$i]) : null;
            }
            $mapped['_row'] = $rowIndex;
            $mapped['_has_cross'] = $hasCross;
            $mapped['_image_path'] = $imagesByRow[$rowIndex] ?? null;

            $rows[] = $mapped;
        }

        return $rows;
    }

    public function validate(array $rows, Category $category, Company $company, string $role): array
    {
        $errors = [];
        $hasCross = ! empty($rows[0]['_has_cross']);

        $refCodes = [];
        $refCodeRows = [];
        foreach ($rows as $row) {
            if (! empty($row['reference_code'])) {
                $code = trim($row['reference_code']);
                $refCodes[] = $code;
                $refCodeRows[$code][] = $row['_row'];
            }
        }

        // reference_code is a UNIQUE idempotency key: flag duplicates within the
        // batch up front instead of letting them hit the DB constraint mid-import.
        foreach ($refCodeRows as $code => $rowNums) {
            if (count($rowNums) > 1) {
                $errors[] = "Reference Code '{$code}' repetido nas linhas ".implode(', ', $rowNums).'. Cada produto deve ter um código único.';
            }
        }

        foreach ($rows as $i => $row) {
            $rowNum = $row['_row'];

            if (! empty($row['parent_ref'])) {
                $parentRef = trim($row['parent_ref']);
                if (! in_array($parentRef, $refCodes)) {
                    $errors[] = "Row {$rowNum}: Parent Reference '{$parentRef}' not found in any row's Reference Code. Base product must be in the same file.";
                }
            }

            if (! empty($row['moq']) && ! is_numeric($row['moq'])) {
                $errors[] = "Row {$rowNum}: MOQ must be a number.";
            }

            if (! empty($row['lead_time_days']) && ! is_numeric($row['lead_time_days'])) {
                $errors[] = "Row {$rowNum}: Lead Time must be a number.";
            }

            if (! empty($row['pkg_packaging_type'])) {
                $valid = array_column(PackagingType::cases(), 'value');
                if (! in_array(strtolower($row['pkg_packaging_type']), $valid)) {
                    $errors[] = "Row {$rowNum}: Invalid packaging type '{$row['pkg_packaging_type']}'. Valid: ".implode(', ', $valid);
                }
            }

            $this->validateCompanyLinkFields($row, $rowNum, 'company', $errors);

            if ($hasCross) {
                $this->validateCompanyLinkFields($row, $rowNum, 'cross', $errors);
            }

            $numericFields = [
                'spec_net_weight', 'spec_length', 'spec_width', 'spec_height',
                'pkg_pcs_per_carton', 'pkg_carton_length', 'pkg_carton_width', 'pkg_carton_height',
                'pkg_carton_weight', 'pkg_carton_net_weight', 'pkg_carton_cbm',
                'cost_base_price', 'cost_bom_material_cost', 'cost_direct_labor_cost',
                'cost_direct_overhead_cost', 'cost_markup_percentage',
                'company_unit_price', 'company_custom_price',
            ];

            if ($hasCross) {
                $numericFields[] = 'cross_unit_price';
                $numericFields[] = 'cross_custom_price';
            }

            foreach ($numericFields as $field) {
                if (! empty($row[$field]) && ! is_numeric($row[$field])) {
                    $errors[] = "Row {$rowNum}: '{$field}' must be numeric, got '{$row[$field]}'.";
                }
            }
        }

        return $errors;
    }

    private function validateCompanyLinkFields(array $row, int $rowNum, string $prefix, array &$errors): void
    {
        $incotermKey = "{$prefix}_incoterm";
        $currencyKey = "{$prefix}_currency_code";

        if (! empty($row[$incotermKey])) {
            $valid = array_column(Incoterm::cases(), 'value');
            if (! in_array(strtoupper($row[$incotermKey]), $valid)) {
                $label = $prefix === 'cross' ? 'Cross-company incoterm' : 'Incoterm';
                $errors[] = "Row {$rowNum}: Invalid {$label} '{$row[$incotermKey]}'. Valid: ".implode(', ', $valid);
            }
        }

        if (! empty($row[$currencyKey])) {
            if (! Currency::where('code', strtoupper($row[$currencyKey]))->exists()) {
                $label = $prefix === 'cross' ? 'Cross-company currency' : 'Currency';
                $errors[] = "Row {$rowNum}: {$label} '{$row[$currencyKey]}' not found in system.";
            }
        }
    }

    public function detectConflicts(array $rows, Company $company, string $role): array
    {
        $conflicts = [];

        foreach ($rows as $row) {
            // Variants (rows with parent_ref) must also be checked for conflicts.
            // Previously they were skipped here, causing them to always default
            // to 'create' in importRow() even when the user selected 'update'.
            //
            // Look up by reference_code first (survives name changes),
            // then fall back to name-based matching.
            $existing = null;
            if (! empty($row['reference_code'])) {
                $existing = Product::where('reference_code', trim($row['reference_code']))->first();
            }
            if (! $existing) {
                $existing = Product::where('name', $row['name'])
                    ->whereNotNull('name')
                    ->first();
            }

            if ($existing) {
                $alreadyLinked = CompanyProduct::where('product_id', $existing->id)
                    ->where('company_id', $company->id)
                    ->where('role', $role)
                    ->exists();
                $conflicts[] = [
                    'row' => $row['_row'],
                    'name' => $row['name'],
                    'existing_sku' => $existing->sku,
                    'existing_id' => $existing->id,
                    'already_linked' => $alreadyLinked,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * Simulate an import without persisting any data.
     * Returns a preview of what would happen: creates, updates, skips, and potential issues.
     */
    public function dryRun(
        array $rows,
        Category $category,
        Company $company,
        string $role,
    ): array {
        $preview = ['would_create' => [], 'would_update' => [], 'would_skip' => [], 'errors' => []];

        foreach ($rows as $row) {
            $rowNum = $row['_row'] ?? '?';
            $productName = $row['name'] ?? '';
            $refCode = $row['reference_code'] ?? '';

            if (empty($productName) && empty($refCode)) {
                $preview['would_skip'][] = [
                    'row' => $rowNum,
                    'reason' => 'Sem nome e sem código de referência',
                ];

                continue;
            }

            // Check for existing product
            $existing = null;
            if (! empty($refCode)) {
                $existing = Product::where('reference_code', trim($refCode))->first();
            }
            if (! $existing && ! empty($productName)) {
                $existing = Product::where('name', $productName)->first();
            }

            if ($existing) {
                $alreadyLinked = CompanyProduct::where('product_id', $existing->id)
                    ->where('company_id', $company->id)
                    ->where('role', $role)
                    ->exists();

                $preview['would_update'][] = [
                    'row' => $rowNum,
                    'name' => $productName ?: $existing->name,
                    'existing_sku' => $existing->sku,
                    'already_linked' => $alreadyLinked,
                ];
            } else {
                // Mesma regra do import: fallback de nome é só o Model Number.
                $fallbackName = $productName ?: trim((string) ($row['model_number'] ?? ''));

                if ($fallbackName === '') {
                    $preview['would_skip'][] = [
                        'row' => $rowNum,
                        'reason' => 'Sem nome e sem número de modelo',
                    ];

                    continue;
                }

                $preview['would_create'][] = [
                    'row' => $rowNum,
                    'name' => $fallbackName,
                    'reference_code' => $refCode,
                ];
            }
        }

        $preview['summary'] = [
            'total' => count($rows),
            'create' => count($preview['would_create']),
            'update' => count($preview['would_update']),
            'skip' => count($preview['would_skip']),
        ];

        return $preview;
    }

    public function import(
        array $rows,
        Category $category,
        Company $company,
        string $role,
        array $conflictResolutions = [],
        ?Company $crossCompany = null,
        ?string $crossRole = null,
        ?string $fileName = null,
    ): array {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'linked' => 0, 'images' => 0, 'errors' => []];
        $skuGenerator = app(GenerateProductSkuAction::class);
        $hasCross = ! empty($rows[0]['_has_cross']);

        // Audit log
        $importLog = ImportLog::start(
            importType: 'product_template',
            fileName: $fileName ?? 'unknown',
            rowCount: count($rows),
            companyId: $company->id,
            metadata: [
                'category' => $category->name,
                'role' => $role,
                'has_cross' => $hasCross,
                'cross_company_id' => $crossCompany?->id,
            ],
        );

        // Build a map of reference_code → row index for quick lookup
        $refCodeMap = [];

        return DB::transaction(function () use ($rows, $category, $company, $role, $conflictResolutions, &$stats, $skuGenerator, &$refCodeMap, $crossCompany, $crossRole, $hasCross, $importLog) {
            // First pass: base products (no parent_ref)
            foreach ($rows as $row) {
                if (! empty($row['parent_ref'])) {
                    continue;
                }

                try {
                    $result = $this->importRow($row, $category, $company, $role, $conflictResolutions, $skuGenerator, null);
                    $stats[$result['action']]++;
                    if (! empty($row['_image_path'])) {
                        $stats['images']++;
                    }

                    if (isset($result['product']) && ! empty($row['reference_code'])) {
                        $refCodeMap[trim($row['reference_code'])] = $result['product'];
                    }

                    if (isset($result['product']) && $crossCompany && $crossRole) {
                        $this->ensureCrossCompanyLink($result['product'], $crossCompany, $crossRole, $row, $hasCross);
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Row {$row['_row']}: {$e->getMessage()}";
                }
            }

            // Second pass: variants (with parent_ref)
            foreach ($rows as $row) {
                if (empty($row['parent_ref'])) {
                    continue;
                }

                try {
                    $parentRef = trim($row['parent_ref']);
                    $parent = $refCodeMap[$parentRef] ?? null;

                    if (! $parent) {
                        $stats['errors'][] = "Row {$row['_row']}: Parent Reference '{$parentRef}' not found. Ensure the base product has Reference Code '{$parentRef}' in this file.";

                        continue;
                    }

                    $result = $this->importRow($row, $category, $company, $role, $conflictResolutions, $skuGenerator, $parent);
                    $stats[$result['action']]++;
                    if (! empty($row['_image_path'])) {
                        $stats['images']++;
                    }

                    if (isset($result['product']) && ! empty($row['reference_code'])) {
                        $refCodeMap[trim($row['reference_code'])] = $result['product'];
                    }

                    if (isset($result['product']) && $crossCompany && $crossRole) {
                        $this->ensureCrossCompanyLink($result['product'], $crossCompany, $crossRole, $row, $hasCross);
                    }
                } catch (\Throwable $e) {
                    $stats['errors'][] = "Row {$row['_row']}: {$e->getMessage()}";
                }
            }

            $importLog->markCompleted($stats);

            return $stats;
        });
    }

    private function importRow(
        array $row,
        Category $category,
        Company $company,
        string $role,
        array $conflictResolutions,
        GenerateProductSkuAction $skuGenerator,
        ?Product $parent,
    ): array {
        $rowNum = $row['_row'];
        $resolution = $conflictResolutions[$rowNum] ?? 'create';

        $existing = null;
        if ($resolution !== 'create') {
            // Look up by reference_code first (most reliable, survives name changes),
            // then fall back to name-based matching for products without a reference_code.
            if (! empty($row['reference_code'])) {
                $existing = Product::where('reference_code', trim($row['reference_code']))->first();
            }
            if (! $existing) {
                $existing = Product::where('name', $row['name'])->first();
            }
        }

        if ($existing && $resolution === 'skip') {
            $this->ensurePrimaryCompanyLink($existing, $company, $role, $row);

            return ['action' => 'skipped', 'product' => $existing];
        }

        if ($existing && $resolution === 'update') {
            $this->updateProduct($existing, $row, $category, $parent);
            $this->upsertSpecification($existing, $row);
            $this->upsertPackaging($existing, $row);
            $this->upsertCosting($existing, $row);
            $this->upsertAttributes($existing, $row, $category);
            $this->ensurePrimaryCompanyLink($existing, $company, $role, $row);

            return ['action' => 'updated', 'product' => $existing];
        }

        // Fallback de nome: só o Model Number (mesma regra do form de produto) —
        // o nome nunca inclui a categoria. Sem nome e sem modelo, a linha é pulada.
        $productName = trim((string) ($row['name'] ?? ''));
        if ($productName === '') {
            $productName = trim((string) ($row['model_number'] ?? ''));
        }

        if ($productName === '') {
            return ['action' => 'skipped'];
        }

        $product = Product::create([
            'name' => $productName,
            'product_family' => $row['product_family'] ?? null,
            'sku' => $skuGenerator->execute($category->id),
            'reference_code' => ! empty($row['reference_code']) ? trim($row['reference_code']) : null,
            'category_id' => $category->id,
            'parent_id' => $parent?->id,
            'status' => ProductStatus::ACTIVE,
            'hs_code' => $row['hs_code'] ?? null,
            'origin_country' => $row['origin_country'] ?? null,
            'brand' => $row['brand'] ?? null,
            'model_number' => $row['model_number'] ?? null,
            'moq' => ! empty($row['moq']) ? (int) $row['moq'] : null,
            'moq_unit' => $row['moq_unit'] ?? null,
            'lead_time_days' => ! empty($row['lead_time_days']) ? (int) $row['lead_time_days'] : null,
            'avatar' => $row['_image_path'] ?? null,
        ]);

        $this->upsertSpecification($product, $row);
        $this->upsertPackaging($product, $row);
        $this->upsertCosting($product, $row);
        $this->upsertAttributes($product, $row, $category);
        $this->ensurePrimaryCompanyLink($product, $company, $role, $row);

        return ['action' => 'created', 'product' => $product];
    }

    private function updateProduct(Product $product, array $row, Category $category, ?Product $parent): void
    {
        $data = array_filter([
            'name' => $row['name'] ?? null,
            'product_family' => $row['product_family'] ?? $product->product_family,
            'reference_code' => ! empty($row['reference_code']) ? trim($row['reference_code']) : $product->reference_code,
            'category_id' => $category->id,
            'parent_id' => $parent?->id ?? $product->parent_id,
            'hs_code' => $row['hs_code'] ?? $product->hs_code,
            'origin_country' => $row['origin_country'] ?? $product->origin_country,
            'brand' => $row['brand'] ?? $product->brand,
            'model_number' => $row['model_number'] ?? $product->model_number,
            'moq' => ! empty($row['moq']) ? (int) $row['moq'] : $product->moq,
            'moq_unit' => $row['moq_unit'] ?? $product->moq_unit,
            'lead_time_days' => ! empty($row['lead_time_days']) ? (int) $row['lead_time_days'] : $product->lead_time_days,
        ], fn ($v) => $v !== null);

        // Update avatar if a new image was found in the spreadsheet
        if (! empty($row['_image_path'])) {
            $data['avatar'] = $row['_image_path'];
        }

        $product->update($data);
    }

    private function upsertSpecification(Product $product, array $row): void
    {
        $data = array_filter([
            'net_weight' => $this->toDecimal($row['spec_net_weight'] ?? null),
            'length' => $this->toDecimal($row['spec_length'] ?? null),
            'width' => $this->toDecimal($row['spec_width'] ?? null),
            'height' => $this->toDecimal($row['spec_height'] ?? null),
            'material' => $row['spec_material'] ?? null,
            'color' => $row['spec_color'] ?? null,
            'finish' => $row['spec_finish'] ?? null,
        ], fn ($v) => $v !== null);

        if (empty($data)) {
            return;
        }

        ProductSpecification::updateOrCreate(
            ['product_id' => $product->id],
            $data,
        );
    }

    private function upsertPackaging(Product $product, array $row): void
    {
        $data = array_filter([
            'packaging_type' => ! empty($row['pkg_packaging_type']) ? strtolower($row['pkg_packaging_type']) : null,
            'pcs_per_carton' => ! empty($row['pkg_pcs_per_carton']) ? (int) $row['pkg_pcs_per_carton'] : null,
            'carton_length' => $this->toDecimal($row['pkg_carton_length'] ?? null),
            'carton_width' => $this->toDecimal($row['pkg_carton_width'] ?? null),
            'carton_height' => $this->toDecimal($row['pkg_carton_height'] ?? null),
            'carton_weight' => $this->toDecimal($row['pkg_carton_weight'] ?? null),
            'carton_net_weight' => $this->toDecimal($row['pkg_carton_net_weight'] ?? null),
            'carton_cbm' => $this->toDecimal($row['pkg_carton_cbm'] ?? null),
        ], fn ($v) => $v !== null);

        if (empty($data)) {
            return;
        }

        ProductPackaging::updateOrCreate(
            ['product_id' => $product->id],
            $data,
        );
    }

    private function upsertCosting(Product $product, array $row): void
    {
        $data = array_filter([
            'base_price' => filled($row['cost_base_price'] ?? null) ? Money::toMinor($row['cost_base_price']) : null,
            'bom_material_cost' => filled($row['cost_bom_material_cost'] ?? null) ? Money::toMinor($row['cost_bom_material_cost']) : null,
            'direct_labor_cost' => filled($row['cost_direct_labor_cost'] ?? null) ? Money::toMinor($row['cost_direct_labor_cost']) : null,
            'direct_overhead_cost' => filled($row['cost_direct_overhead_cost'] ?? null) ? Money::toMinor($row['cost_direct_overhead_cost']) : null,
            'markup_percentage' => $this->toDecimal($row['cost_markup_percentage'] ?? null),
        ], fn ($v) => $v !== null);

        if (empty($data)) {
            return;
        }

        if (isset($data['bom_material_cost']) || isset($data['direct_labor_cost']) || isset($data['direct_overhead_cost'])) {
            $bom = $data['bom_material_cost'] ?? 0;
            $labor = $data['direct_labor_cost'] ?? 0;
            $overhead = $data['direct_overhead_cost'] ?? 0;
            $data['total_manufacturing_cost'] = $bom + $labor + $overhead;
        }

        if (isset($data['base_price']) && isset($data['markup_percentage'])) {
            $data['calculated_selling_price'] = (int) round($data['base_price'] * (1 + ($data['markup_percentage'] / 100)));
        }

        ProductCosting::updateOrCreate(
            ['product_id' => $product->id],
            $data,
        );
    }

    private function upsertAttributes(Product $product, array $row, Category $category): void
    {
        $attributes = $category->getAllAttributes();

        foreach ($attributes as $attr) {
            $key = "attr_{$attr->id}";
            $value = $row[$key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            ProductAttributeValue::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'category_attribute_id' => $attr->id,
                ],
                ['value' => (string) $value],
            );
        }
    }

    private function ensurePrimaryCompanyLink(Product $product, Company $company, string $role, array $row): void
    {
        $this->upsertCompanyLink($product, $company, $role, $row, 'company');
    }

    private function ensureCrossCompanyLink(Product $product, Company $crossCompany, string $crossRole, array $row, bool $hasCrossColumns): void
    {
        $prefix = $hasCrossColumns ? 'cross' : 'company';
        $this->upsertCompanyLink($product, $crossCompany, $crossRole, $row, $prefix);
    }

    private function upsertCompanyLink(Product $product, Company $company, string $role, array $row, string $prefix): void
    {
        $pivotData = [
            'role' => $role,
            'external_code' => $row["{$prefix}_external_code"] ?? null,
            'external_name' => $row["{$prefix}_external_name"] ?? null,
            'external_description' => $row["{$prefix}_external_description"] ?? null,
            'unit_price' => filled($row["{$prefix}_unit_price"] ?? null) ? Money::toMinor($row["{$prefix}_unit_price"]) : null,
            'custom_price' => filled($row["{$prefix}_custom_price"] ?? null) ? Money::toMinor($row["{$prefix}_custom_price"]) : null,
            'currency_code' => ! empty($row["{$prefix}_currency_code"]) ? strtoupper($row["{$prefix}_currency_code"]) : null,
            'incoterm' => ! empty($row["{$prefix}_incoterm"]) ? strtoupper($row["{$prefix}_incoterm"]) : null,
            'is_preferred' => $this->toBool($row["{$prefix}_is_preferred"] ?? null),
        ];

        $pivotData = array_filter($pivotData, fn ($v) => $v !== null);

        $existing = CompanyProduct::where('product_id', $product->id)
            ->where('company_id', $company->id)
            ->where('role', $role)
            ->first();

        if ($existing) {
            $existing->update($pivotData);
        } else {
            CompanyProduct::create(array_merge($pivotData, [
                'product_id' => $product->id,
                'company_id' => $company->id,
            ]));
        }
    }

    /**
     * Extract images from worksheet and map them to row numbers.
     * Saves each image to storage and returns [rowNumber => storedPath].
     */
    private function extractImagesByRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet): array
    {
        $imagesByRow = [];
        $hashMap = []; // md5 hash => stored filename (deduplication)

        foreach ($worksheet->getDrawingCollection() as $drawing) {
            $coordinate = $drawing->getCoordinates();
            $row = (int) preg_replace('/[A-Z]+/', '', $coordinate);

            if ($row < 4) {
                continue; // Skip header rows
            }

            if (isset($imagesByRow[$row])) {
                continue;
            }

            $imageData = null;
            $extension = 'png';

            if ($drawing instanceof MemoryDrawing) {
                ob_start();
                $renderFunc = match ($drawing->getRenderingFunction()) {
                    MemoryDrawing::RENDERING_JPEG => 'imagejpeg',
                    MemoryDrawing::RENDERING_GIF => 'imagegif',
                    default => 'imagepng',
                };
                $renderFunc($drawing->getImageResource());
                $imageData = ob_get_clean();
                $extension = match ($drawing->getRenderingFunction()) {
                    MemoryDrawing::RENDERING_JPEG => 'jpg',
                    MemoryDrawing::RENDERING_GIF => 'gif',
                    default => 'png',
                };
            } elseif ($drawing instanceof Drawing && $drawing->getPath()) {
                $sourcePath = $drawing->getPath();

                // Handle zip:// paths (images embedded in xlsx files)
                if (str_starts_with($sourcePath, 'zip://')) {
                    $imageData = @file_get_contents($sourcePath);
                } elseif (file_exists($sourcePath)) {
                    $imageData = file_get_contents($sourcePath);
                }

                if ($imageData) {
                    $extension = strtolower($drawing->getExtension() ?: pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'png');
                }
            }

            if ($imageData && strlen($imageData) > 100) {
                // Validate extension whitelist
                $allowedExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
                if (! in_array($extension, $allowedExtensions)) {
                    continue;
                }

                // Validate image magic bytes to prevent malicious file uploads
                $magicBytes = substr($imageData, 0, 8);
                $isValidImage = str_starts_with($magicBytes, "\xFF\xD8\xFF") // JPEG
                    || str_starts_with($magicBytes, "\x89PNG") // PNG
                    || str_starts_with($magicBytes, 'GIF87a') || str_starts_with($magicBytes, 'GIF89a') // GIF
                    || str_starts_with($magicBytes, 'RIFF'); // WebP (RIFF container)

                if (! $isValidImage) {
                    continue;
                }

                // Strip EXIF data by reprocessing through GD (prevents EXIF-based payloads)
                $gdImage = @imagecreatefromstring($imageData);
                if ($gdImage === false) {
                    continue;
                }

                ob_start();
                match ($extension) {
                    'jpg', 'jpeg' => imagejpeg($gdImage, null, 90),
                    'gif' => imagegif($gdImage),
                    'webp' => imagewebp($gdImage, null, 90),
                    default => imagepng($gdImage, null, 6),
                };
                $cleanImageData = ob_get_clean();
                imagedestroy($gdImage);

                if (empty($cleanImageData)) {
                    continue;
                }

                $hash = md5($cleanImageData);

                if (isset($hashMap[$hash])) {
                    $imagesByRow[$row] = $hashMap[$hash];
                } else {
                    $filename = 'products/'.uniqid('import_').'.'.$extension;
                    Storage::disk('public')->put($filename, $cleanImageData);
                    $hashMap[$hash] = $filename;
                    $imagesByRow[$row] = $filename;
                }
            }
        }

        return $imagesByRow;
    }

    private function cleanValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        // Strip BOM (Byte Order Mark) - common in files from Chinese suppliers
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);

        // Strip invisible/zero-width characters
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = preg_replace('/[\x{200B}\x{200C}\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $value);

        // Normalize encoding: detect and convert to UTF-8 if needed
        if (! mb_check_encoding($value, 'UTF-8')) {
            $detected = mb_detect_encoding($value, ['UTF-8', 'GB2312', 'GBK', 'GB18030', 'ISO-8859-1', 'Windows-1252'], true);
            if ($detected && $detected !== 'UTF-8') {
                $value = mb_convert_encoding($value, 'UTF-8', $detected);
            }
        }

        // Collapse multiple spaces into one, then trim
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value) ?: null;
    }

    private function toDecimal(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function toBool(?string $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array(strtolower($value), ['yes', 'true', '1', 'sim']);
    }

    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $v) {
            if ($v !== null && $v !== '') {
                return false;
            }
        }

        return true;
    }
}
