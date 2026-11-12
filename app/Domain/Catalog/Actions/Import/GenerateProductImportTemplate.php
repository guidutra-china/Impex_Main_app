<?php

namespace App\Domain\Catalog\Actions\Import;

use App\Domain\Catalog\Models\Category;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateProductImportTemplate
{
    private const PRODUCT_COLUMNS = [
        'name' => ['label' => 'Product Name', 'required' => true, 'hint' => 'e.g. Ceramic Mug 300ml'],
        'product_family' => ['label' => 'Product Family', 'required' => false, 'hint' => 'Group name (e.g. MX Series, Slim Line)'],
        'reference_code' => ['label' => 'Reference Code', 'required' => false, 'hint' => 'Your code for this product (e.g. LED-001). Used to link variants to base products.'],
        'parent_ref' => ['label' => 'Parent Reference', 'required' => false, 'hint' => 'Leave EMPTY for base products. For variants, enter the Reference Code of the base product.'],
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

    private const COMPANY_LINK_COLUMNS = [
        'external_code' => ['label' => 'External Code', 'required' => false, 'hint' => "Company's internal code for this product"],
        'external_name' => ['label' => 'External Product Name', 'required' => false, 'hint' => 'Name used by this company'],
        'external_description' => ['label' => 'External Description', 'required' => false, 'hint' => 'Description for invoices'],
        'unit_price' => ['label' => 'Unit Price', 'required' => false, 'hint' => 'Decimal (e.g. 12.50, NOT in cents)'],
        'custom_price' => ['label' => 'Custom Price (CI Override)', 'required' => false, 'hint' => 'Decimal. Overrides PI price on Commercial Invoice.'],
        'currency_code' => ['label' => 'Currency', 'required' => false, 'hint' => 'e.g. USD, EUR, CNY'],
        'incoterm' => ['label' => 'Incoterm', 'required' => false, 'hint' => 'EXW, FOB, CIF, etc.'],
        'is_preferred' => ['label' => 'Is Preferred', 'required' => false, 'hint' => 'yes or no'],
    ];

    public function buildColumnMap(Category $category, ?string $role = null, bool $includeCrossRole = false): array
    {
        $columns = [];
        $roleLabel = ucfirst($role ?? 'company');
        $crossRole = ($role === 'client') ? 'supplier' : 'client';
        $crossRoleLabel = ucfirst($crossRole);

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

        // Primary company link
        $sectionName = strtoupper($roleLabel).' LINK';
        foreach (self::COMPANY_LINK_COLUMNS as $key => $col) {
            $columns["company_{$key}"] = array_merge($col, [
                'section' => $sectionName,
                'key' => $key,
                'group' => 'company',
                'link_role' => $role,
            ]);
        }

        // Cross-company link (optional)
        if ($includeCrossRole) {
            $crossSectionName = strtoupper($crossRoleLabel).' LINK';
            foreach (self::COMPANY_LINK_COLUMNS as $key => $col) {
                $columns["cross_{$key}"] = array_merge($col, [
                    'section' => $crossSectionName,
                    'key' => $key,
                    'group' => 'cross_company',
                    'link_role' => $crossRole,
                ]);
            }
        }

        // Category attributes
        $categoryAttributes = $category->getAllAttributes();
        foreach ($categoryAttributes as $attr) {
            $hint = '';
            if ($attr->type === \App\Domain\Catalog\Enums\AttributeType::SELECT && ! empty($attr->options)) {
                $hint = 'Options: '.implode(', ', $attr->options);
            } elseif ($attr->type === \App\Domain\Catalog\Enums\AttributeType::BOOLEAN) {
                $hint = 'yes or no';
            } elseif ($attr->type === \App\Domain\Catalog\Enums\AttributeType::NUMBER) {
                $hint = 'Numeric value';
            }
            if ($attr->unit) {
                $hint .= ($hint ? '. ' : '')."Unit: {$attr->unit}";
            }

            $columns["attr_{$attr->id}"] = [
                'label' => $attr->name.($attr->unit ? " ({$attr->unit})" : ''),
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

    /**
     * Cabeçalhos do modelo em branco do Quick Import (produtos do cliente ou do
     * fornecedor). Cada nome é reconhecido pelo mapeamento automático do
     * FlexibleProductImportAction e o total cabe nas 15 colunas mapeáveis.
     * Evite "Custom Price": o padrão "custo" confundiria com preço de compra.
     *
     * @return list<string>
     */
    public function quickImportHeaders(string $role, bool $includeCrossRole = false): array
    {
        $common = ['Product Name', 'Product Family', 'Model Number', 'Reference Code'];

        if ($role === 'client') {
            $headers = [...$common, 'Client Code', 'Client Product Name', 'Invoice Description',
                'Selling Price', 'CI Price', 'MOQ', 'Lead Time (days)', 'Remarks'];

            return $includeCrossRole
                ? [...$headers, 'Supplier Code', 'Supplier Product Name', 'Purchase Price']
                : $headers;
        }

        $headers = [...$common, 'Supplier Code', 'Supplier Product Name', 'Purchase Price',
            'MOQ', 'Lead Time (days)', 'Material', 'Specifications', 'Remarks'];

        return $includeCrossRole
            ? [...$headers, 'Client Code', 'Client Product Name', 'Selling Price']
            : $headers;
    }

    /**
     * Gera o modelo em branco do Quick Import: aba de dados com só o cabeçalho
     * (linha 1) e uma aba de instruções depois dela — o import lê só a aba ativa.
     */
    public function quickImport(string $role, bool $includeCrossRole = false): string
    {
        $suffix = $includeCrossRole ? "{$role}_and_".($role === 'client' ? 'supplier' : 'client') : $role;
        $path = storage_path("app/temp/product_quick_import_{$suffix}.xlsx");

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Products');

        foreach ($this->quickImportHeaders($role, $includeCrossRole) as $index => $label) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($column.'1', $label);
            $sheet->getColumnDimension($column)->setWidth(max(14, mb_strlen($label) + 4));
        }

        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '4472C4']],
        ]);
        $sheet->freezePane('A2');

        $help = $spreadsheet->createSheet();
        $help->setTitle('Instructions');
        $help->getColumnDimension('A')->setWidth(110);
        foreach ($this->quickImportInstructions($role) as $row => $line) {
            $help->setCellValue('A'.($row + 1), $line);
        }

        $spreadsheet->setActiveSheetIndex(0);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @return list<string>
     */
    private function quickImportInstructions(string $role): array
    {
        $priceLine = $role === 'client'
            ? 'Selling Price = client selling price. CI Price = price used only on the Commercial Invoice (leave blank if not used).'
            : 'Purchase Price = this supplier\'s purchase price.';

        return [
            'Quick Import — '.($role === 'client' ? 'client' : 'supplier').' products',
            'Fill one product per row on the "Products" sheet, starting at row 2. Keep the header names: the import maps columns by name.',
            'Prices as plain decimals (12.50), never in cents. The currency is chosen on the import screen.',
            'Reference Code = our product code. '.($role === 'client' ? 'Client' : 'Supplier').' Code = the code this company uses.',
            $priceLine,
            'Only the first 15 columns (A–O) can be mapped. Pictures pasted on a product row are imported with it.',
            'Import: Companies → open the company → products tab → Quick Import.',
        ];
    }

    public function getColumnKeys(Category $category, ?string $role = null, bool $includeCrossRole = false): array
    {
        return array_keys($this->buildColumnMap($category, $role, $includeCrossRole));
    }

    public function hasCrossCompanyColumns(Category $category, string $role, array $headerRow): bool
    {
        $singleCount = count($this->buildColumnMap($category, $role, false));

        return count($headerRow) > $singleCount;
    }
}
