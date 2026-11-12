<?php

namespace Tests\Unit\Catalog;

use App\Filament\Actions\FlexibleProductImportAction;
use Tests\TestCase;

/**
 * "Custom Price" contém "custo" (padrão de preço de compra). A coluna de preço
 * da CI não pode virar preço de venda nem de compra no mapeamento automático.
 */
class FlexibleProductImportDetectMappingTest extends TestCase
{
    public function test_custom_price_column_is_not_taken_as_purchase_price_for_client(): void
    {
        $mapping = FlexibleProductImportAction::detectMapping(
            ['Product Name', 'Reference Code', 'Selling Price', 'Custom Price (CI)'],
            'client',
        );

        $this->assertSame('2', $mapping['unit_price']);
        $this->assertSame('3', $mapping['custom_price']);
        $this->assertArrayNotHasKey('cross_unit_price', $mapping);
    }

    public function test_custom_price_before_selling_price_does_not_steal_unit_price(): void
    {
        $mapping = FlexibleProductImportAction::detectMapping(
            ['Product Name', 'Custom Price', 'Selling Price'],
            'client',
        );

        $this->assertSame('2', $mapping['unit_price']);
        $this->assertSame('1', $mapping['custom_price']);
    }

    public function test_custom_price_column_is_not_taken_as_purchase_price_for_supplier(): void
    {
        $mapping = FlexibleProductImportAction::detectMapping(
            ['Product Name', 'Custom Price (CI)', 'Purchase Price'],
            'supplier',
        );

        $this->assertSame('2', $mapping['unit_price']);
        $this->assertSame('1', $mapping['custom_price']);
    }

    public function test_plain_custo_header_still_maps_to_purchase_price(): void
    {
        $mapping = FlexibleProductImportAction::detectMapping(
            ['Produto', 'Preço de Custo'],
            'supplier',
        );

        $this->assertSame('1', $mapping['unit_price']);
    }
}
