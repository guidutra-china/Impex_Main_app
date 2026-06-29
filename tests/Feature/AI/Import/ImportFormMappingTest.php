<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\Infrastructure\Support\Money;
use App\Filament\Pages\Concerns\HandlesSupplierQuotationImport;
use Tests\TestCase;

class ImportFormMappingTest extends TestCase
{
    private function subject(): object
    {
        return new class
        {
            use HandlesSupplierQuotationImport;

            public array $messages = [];

            public function buildFormPublic(array $preview, array $itemPhoto): array
            {
                return $this->buildForm($preview, $itemPhoto);
            }

            public function formToActionPreviewPublic(array $form, array $pool): array
            {
                $this->form = $form;
                $this->importImagePool = $pool;

                return $this->formToActionPreview();
            }
        };
    }

    public function test_build_form_maps_preview_to_editable_decimals(): void
    {
        $preview = [
            'fornecedor' => ['nome' => 'ACME', 'status' => 'novo', 'company_id' => null],
            'fornecedor_dados' => ['phone' => '+86 1', 'email' => 'a@b.com'],
            'cabecalho' => ['currency_code' => 'USD', 'incoterm' => 'FOB', 'lead_time_days' => null, 'moq' => null, 'valid_until' => null, 'supplier_reference' => null, 'notes' => null],
            'itens' => [[
                'status' => 'novo', 'product_id' => null, 'part_no' => 'P1', 'description' => 'Widget',
                'quantity' => 2, 'unit' => 'pcs', 'unit_cost_minor' => Money::toMinor(12.50),
                'category_id' => 7, 'specifications' => 'spec', 'moq' => null, 'lead_time_days' => null, 'notes' => null,
            ]],
        ];

        $form = $this->subject()->buildFormPublic($preview, [0 => 3]);

        $this->assertSame('ACME', $form['fornecedor']['nome']);
        $this->assertSame('+86 1', $form['fornecedor']['phone']);
        $this->assertSame(12.5, $form['itens'][0]['unit_price']);
        $this->assertSame(7, $form['itens'][0]['category_id']);
        $this->assertSame(3, $form['itens'][0]['photo_index']);
        $this->assertSame('spec', $form['itens'][0]['specifications']);
    }

    public function test_form_to_action_preview_converts_back_and_maps_photo(): void
    {
        $form = [
            'fornecedor' => ['nome' => 'ACME', 'status' => 'novo', 'company_id' => null, 'phone' => '+86 1'],
            'cabecalho' => ['currency_code' => 'USD', 'incoterm' => 'FOB'],
            'itens' => [[
                'status' => 'novo', 'product_id' => null, 'part_no' => 'P1', 'description' => 'Widget',
                'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 12.50, 'category_id' => 7,
                'specifications' => 'spec', 'moq' => null, 'lead_time_days' => null, 'notes' => null, 'photo_index' => 1,
            ]],
        ];
        $pool = [['id' => 0, 'path' => '/tmp/a.png'], ['id' => 1, 'path' => '/tmp/b.png']];

        $out = $this->subject()->formToActionPreviewPublic($form, $pool);

        $this->assertSame(Money::toMinor(12.50), $out['preview']['itens'][0]['unit_cost_minor']);
        $this->assertSame(7, $out['preview']['itens'][0]['category_id']);
        $this->assertSame('+86 1', $out['preview']['fornecedor_dados']['phone']);
        $this->assertSame('/tmp/b.png', $out['images']['P1']); // photo_index 1 -> pool[1].path, keyed by part_no
    }
}
