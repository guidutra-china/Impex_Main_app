<?php

namespace App\Domain\Catalog\Actions\Import;

use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttributeValue;
use App\Domain\Catalog\Models\ProductCosting;
use App\Domain\Catalog\Models\ProductPackaging;
use App\Domain\Catalog\Models\ProductSpecification;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Settings\Models\Currency;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

class ProductImportService
{
    private GenerateProductImportTemplate $templateGenerator;

    public function __construct()
    {
        $this->templateGenerator = new GenerateProductImportTemplate();
    }

    public function parseFile(string $filePath, Category $category, string $role = 'client'): array
    {
        $hasCross = $this->detectCrossColumns($filePath, $category, $role);
        $columnMap = $this->templateGenerator->buildColumnMap($category, $role, $hasCross);
        $columnKeys = array_keys($columnMap);

        $reader = new Reader();
        $reader->open($filePath);

        $rows = [];
        $rowIndex = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowIndex++;
                if ($rowIndex <= 3) {
                    continue;
                }

                $cells = $row->getCells();
                $values = array_map(fn ($cell) => $cell->getValue(), $cells);

                if ($this->isEmptyRow($values)) {
                    continue;
                }

                $mapped = [];
                foreach ($columnKeys as $i => $key) {
                    $mapped[$key] = isset($values[$i]) ? $this->cleanValue($values[$i]) : null;
                }
                $mapped['_row'] = $rowIndex;
                $mapped['_has_cross'] = $hasCross;

                $rows[] = $mapped;
            }
            break;
        }

        $reader->close();

        return $rows;
    }

    public function validate(array $rows, Category $category, Company $company, string $role): array
    {
        $errors = [];
        $hasCross = ! empty($rows[0]['_has_cross']);

        $refCodes = [];
        foreach ($rows as $row) {
            if (! empty($row['reference_code'])) {
                $refCodes[] = $row['reference_code'];
            }
        }

        foreach ($rows as $i => $row) {
            $rowNum = $row['_row'];

            if (empty($row['name'])) {
                $errors[] = "Row {$rowNum}: Product Name is required.";
            }

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
                    $errors[] = "Row {$rowNum}: Invalid packaging type '{$row['pkg_packaging_type']}'. Valid: " . implode(', ', $valid);
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
                $errors[] = "Row {$rowNum}: Invalid {$label} '{$row[$incotermKey]}'. Valid: " . implode(', ', $valid);
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
            if (! empty($row['parent_ref'])) {
                continue;
            }

            $existingByName = Product::where('name', $row['name'])
                ->whereNotNull('name')
                ->first();

            if ($existingByName) {
                $alreadyLinked = CompanyProduct::where('product_id', $existingByName->id)
                    ->where('company_id', $company->id)
                    ->where('role', $role)
                    ->exists();

                $conflicts[] = [
                    'row' => $row['_row'],
                    'name' => $row['name'],
                    'existing_sku' => $existingByName->sku,
                    'existing_id' => $existingByName->id,
                    'already_linked' => $alreadyLinked,
                ];
            }
        }

        return $conflicts;
    }

    public function import(
        array $rows,
        Category $category,
        Company $company,
        string $role,
        array $conflictResolutions = [],
        ?Company $crossCompany = null,
        ?string $crossRole = null,
    ): array {
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'linked' => 0, 'errors' => []];
        $skuGenerator = app(GenerateProductSkuAction::class);
        $hasCross = ! empty($rows[0]['_has_cross']);

        // Build a map of reference_code → row index for quick lookup
        $refCodeMap = [];

        return DB::transaction(function () use ($rows, $category, $company, $role, $conflictResolutions, &$stats, $skuGenerator, &$refCodeMap, $crossCompany, $crossRole, $hasCross) {
            // First pass: base products (no parent_ref)
            foreach ($rows as $row) {
                if (! empty($row['parent_ref'])) {
                    continue;
                }

                try {
                    $result = $this->importRow($row, $category, $company, $role, $conflictResolutions, $skuGenerator, null);
                    $stats[$result['action']]++;

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
            $existing = Product::where('name', $row['name'])->first();
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

        $product = Product::create([
            'name' => $row['name'],
            'sku' => $skuGenerator->execute($category->id),
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
            'base_price' => $this->toCents($row['cost_base_price'] ?? null),
            'bom_material_cost' => $this->toCents($row['cost_bom_material_cost'] ?? null),
            'direct_labor_cost' => $this->toCents($row['cost_direct_labor_cost'] ?? null),
            'direct_overhead_cost' => $this->toCents($row['cost_direct_overhead_cost'] ?? null),
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
            'unit_price' => $this->toCents($row["{$prefix}_unit_price"] ?? null),
            'custom_price' => $this->toCents($row["{$prefix}_custom_price"] ?? null),
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

    private function detectCrossColumns(string $filePath, Category $category, string $role): bool
    {
        $reader = new Reader();
        $reader->open($filePath);

        $singleColumnCount = count($this->templateGenerator->buildColumnMap($category, $role, false));

        $actualColumnCount = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->getCells();
                $actualColumnCount = count($cells);
                break;
            }
            break;
        }

        $reader->close();

        return $actualColumnCount > $singleColumnCount;
    }

    private function cleanValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function toDecimal(?string $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function toCents(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round((float) $value * 100);
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
