<?php

namespace App\Domain\Catalog\Actions\Import;

use App\Domain\Catalog\Models\Category;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class GenerateProductImportTemplate
{
    private const PRODUCT_COLUMNS = [
        'name' => ['label' => 'Product Name', 'required' => true, 'hint' => 'e.g. Ceramic Mug 300ml'],
        'parent_sku' => ['label' => 'Parent SKU', 'required' => false, 'hint' => 'Leave empty for base products. Fill with parent SKU for variants.'],
        'hs_code' => ['label' => 'HS Code', 'required' => false, 'hint' => 'e.g. 6912.00'],
        'origin_country' => ['label' => 'Origin Country', 'required' => false, 'hint' => 'e.g. CN, BR, US'],
        'brand' => ['label' => 'Brand', 'required' => false, 'hint' => ''],
        'model_number' => ['label' => 'Model Number', 'required' => false, 'hint' => ''],
        'moq' => ['label' => 'MOQ', 'required' => false, 'hint' => 'Minimum order quantity (integer)'],
        'moq_unit' => ['label' => 'MOQ Unit', 'required' => false, 'hint' => 'e.g. pcs, kg, set'],
        'lead_time_days' => ['label' => 'Lead Time (days)', 'required' => false, 'hint' => 'Integer'],
    ];

    private const SPEC_COLUMNS = [
        'net_weight' => ['label' => 'Net Weight (kg)', 'required' => false, 'hint' => 'Decimal, e.g. 0.350'],
        'length' => ['label' => 'Length (cm)', 'required' => false, 'hint' => 'Decimal'],
        'width' => ['label' => 'Width (cm)', 'required' => false, 'hint' => 'Decimal'],
        'height' => ['label' => 'Height (cm)', 'required' => false, 'hint' => 'Decimal'],
        'material' => ['label' => 'Material', 'required' => false, 'hint' => 'e.g. Ceramic, Stainless Steel'],
        'color' => ['label' => 'Color', 'required' => false, 'hint' => ''],
        'finish' => ['label' => 'Finish', 'required' => false, 'hint' => 'e.g. Matte, Glossy'],
    ];

    private const PACKAGING_COLUMNS = [
        'packaging_type' => ['label' => 'Packaging Type', 'required' => false, 'hint' => 'carton, bag, drum, wood_box, bulk'],
        'pcs_per_carton' => ['label' => 'Pcs per Carton', 'required' => false, 'hint' => 'Integer'],
        'carton_length' => ['label' => 'Carton Length (cm)', 'required' => false, 'hint' => 'Decimal'],
        'carton_width' => ['label' => 'Carton Width (cm)', 'required' => false, 'hint' => 'Decimal'],
        'carton_height' => ['label' => 'Carton Height (cm)', 'required' => false, 'hint' => 'Decimal'],
        'carton_weight' => ['label' => 'Carton Gross Weight (kg)', 'required' => false, 'hint' => 'Decimal'],
        'carton_net_weight' => ['label' => 'Carton Net Weight (kg)', 'required' => false, 'hint' => 'Decimal'],
        'carton_cbm' => ['label' => 'Carton CBM', 'required' => false, 'hint' => 'Decimal, e.g. 0.0450'],
    ];

    private const COSTING_COLUMNS = [
        'base_price' => ['label' => 'Base Price', 'required' => false, 'hint' => 'Decimal (e.g. 12.50, NOT in cents)'],
        'bom_material_cost' => ['label' => 'BOM Material Cost', 'required' => false, 'hint' => 'Decimal'],
        'direct_labor_cost' => ['label' => 'Direct Labor Cost', 'required' => false, 'hint' => 'Decimal'],
        'direct_overhead_cost' => ['label' => 'Direct Overhead Cost', 'required' => false, 'hint' => 'Decimal'],
        'markup_percentage' => ['label' => 'Markup %', 'required' => false, 'hint' => 'e.g. 30 for 30%'],
    ];

    private const COMPANY_COLUMNS = [
        'external_code' => ['label' => 'External Code', 'required' => false, 'hint' => "Client/Supplier's internal code"],
        'external_name' => ['label' => 'External Product Name', 'required' => false, 'hint' => 'Name used by client/supplier'],
        'external_description' => ['label' => 'External Description', 'required' => false, 'hint' => 'Description for invoices'],
        'unit_price' => ['label' => 'Unit Price', 'required' => false, 'hint' => 'Decimal (e.g. 12.50, NOT in cents)'],
        'custom_price' => ['label' => 'Custom Price (CI Override)', 'required' => false, 'hint' => 'Decimal. Overrides PI price on Commercial Invoice.'],
        'currency_code' => ['label' => 'Currency', 'required' => false, 'hint' => 'e.g. USD, EUR, CNY'],
        'incoterm' => ['label' => 'Incoterm', 'required' => false, 'hint' => 'EXW, FOB, CIF, etc.'],
        'is_preferred' => ['label' => 'Is Preferred', 'required' => false, 'hint' => 'yes or no'],
    ];

    public function execute(Category $category, string $role): string
    {
        $filename = 'product_import_template_' . str($category->name)->slug() . '_' . $role . '.xlsx';
        $path = storage_path('app/temp/' . $filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $writer = new Writer();
        $writer->openToFile($path);

        $allColumns = $this->buildColumnMap($category);

        $headerStyle = (new Style())
            ->setFontBold()
            ->setFontSize(11)
            ->setBackgroundColor('4472C4')
            ->setFontColor(Color::WHITE);

        $sectionStyle = (new Style())
            ->setFontBold()
            ->setFontSize(10)
            ->setBackgroundColor('D9E2F3')
            ->setFontColor('1F3864');

        $hintStyle = (new Style())
            ->setFontItalic()
            ->setFontSize(9)
            ->setFontColor('808080');

        // Row 1: Section headers
        $sectionCells = [];
        foreach ($allColumns as $col) {
            $sectionCells[] = $col['section'];
        }
        $writer->addRow(new Row(
            array_map(fn ($v) => \OpenSpout\Common\Entity\Cell::fromValue($v), $sectionCells),
            $sectionStyle
        ));

        // Row 2: Column headers
        $headerCells = [];
        foreach ($allColumns as $col) {
            $label = $col['label'];
            if ($col['required']) {
                $label .= ' *';
            }
            $headerCells[] = $label;
        }
        $writer->addRow(new Row(
            array_map(fn ($v) => \OpenSpout\Common\Entity\Cell::fromValue($v), $headerCells),
            $headerStyle
        ));

        // Row 3: Hints
        $hintCells = [];
        foreach ($allColumns as $col) {
            $hintCells[] = $col['hint'];
        }
        $writer->addRow(new Row(
            array_map(fn ($v) => \OpenSpout\Common\Entity\Cell::fromValue($v), $hintCells),
            $hintStyle
        ));

        // Row 4: Example base product
        $exampleBase = $this->buildExampleRow($allColumns, isVariant: false);
        $writer->addRow(new Row(
            array_map(fn ($v) => \OpenSpout\Common\Entity\Cell::fromValue($v), $exampleBase)
        ));

        // Row 5: Example variant
        $exampleVariant = $this->buildExampleRow($allColumns, isVariant: true);
        $writer->addRow(new Row(
            array_map(fn ($v) => \OpenSpout\Common\Entity\Cell::fromValue($v), $exampleVariant)
        ));

        $writer->close();

        return $path;
    }

    public function buildColumnMap(Category $category): array
    {
        $columns = [];

        foreach (self::PRODUCT_COLUMNS as $key => $col) {
            $columns[$key] = array_merge($col, ['section' => 'PRODUCT', 'key' => $key, 'group' => 'product']);
        }

        foreach (self::SPEC_COLUMNS as $key => $col) {
            $columns["spec_{$key}"] = array_merge($col, ['section' => 'SPECIFICATION', 'key' => $key, 'group' => 'spec']);
        }

        foreach (self::PACKAGING_COLUMNS as $key => $col) {
            $columns["pkg_{$key}"] = array_merge($col, ['section' => 'PACKAGING', 'key' => $key, 'group' => 'packaging']);
        }

        foreach (self::COSTING_COLUMNS as $key => $col) {
            $columns["cost_{$key}"] = array_merge($col, ['section' => 'COSTING', 'key' => $key, 'group' => 'costing']);
        }

        foreach (self::COMPANY_COLUMNS as $key => $col) {
            $columns["company_{$key}"] = array_merge($col, ['section' => 'COMPANY LINK', 'key' => $key, 'group' => 'company']);
        }

        $categoryAttributes = $category->getAllAttributes();
        foreach ($categoryAttributes as $attr) {
            $hint = '';
            if ($attr->type === \App\Domain\Catalog\Enums\AttributeType::SELECT && ! empty($attr->options)) {
                $hint = 'Options: ' . implode(', ', $attr->options);
            } elseif ($attr->type === \App\Domain\Catalog\Enums\AttributeType::BOOLEAN) {
                $hint = 'yes or no';
            } elseif ($attr->type === \App\Domain\Catalog\Enums\AttributeType::NUMBER) {
                $hint = 'Numeric value';
            }
            if ($attr->unit) {
                $hint .= ($hint ? '. ' : '') . "Unit: {$attr->unit}";
            }

            $columns["attr_{$attr->id}"] = [
                'label' => $attr->name . ($attr->unit ? " ({$attr->unit})" : ''),
                'required' => $attr->is_required,
                'hint' => $hint ?: ($attr->default_value ? "Default: {$attr->default_value}" : ''),
                'section' => 'ATTRIBUTES',
                'key' => $attr->id,
                'group' => 'attribute',
                'attribute_type' => $attr->type,
            ];
        }

        return $columns;
    }

    private function buildExampleRow(array $columns, bool $isVariant): array
    {
        $row = [];
        foreach ($columns as $colKey => $col) {
            $row[] = match ($colKey) {
                'name' => $isVariant ? 'Example Product - Blue' : 'Example Product',
                'parent_sku' => $isVariant ? '(SKU from row above)' : '',
                'hs_code' => '6912.00',
                'origin_country' => 'CN',
                'brand' => 'BrandX',
                'model_number' => $isVariant ? 'MX-100-BL' : 'MX-100',
                'moq' => '500',
                'moq_unit' => 'pcs',
                'lead_time_days' => '30',
                'spec_net_weight' => '0.350',
                'spec_color' => $isVariant ? 'Blue' : 'White',
                'spec_material' => 'Ceramic',
                'company_external_code' => $isVariant ? 'CLI-MX100-BL' : 'CLI-MX100',
                'company_unit_price' => '12.50',
                'company_currency_code' => 'USD',
                'company_incoterm' => 'FOB',
                'company_is_preferred' => $isVariant ? 'no' : 'yes',
                'pkg_packaging_type' => 'carton',
                'pkg_pcs_per_carton' => '24',
                'cost_base_price' => '8.00',
                'cost_markup_percentage' => '30',
                default => '',
            };
        }

        return $row;
    }

    public function getColumnKeys(Category $category): array
    {
        return array_keys($this->buildColumnMap($category));
    }
}
