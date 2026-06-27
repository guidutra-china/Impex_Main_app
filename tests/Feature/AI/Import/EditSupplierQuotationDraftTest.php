<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\EditSupplierQuotationDraft;
use Tests\TestCase;

class EditSupplierQuotationDraftTest extends TestCase
{
    public function test_returns_updated_draft_and_reply(): void
    {
        $editor = new class extends EditSupplierQuotationDraft
        {
            protected function callModel(array $content): object
            {
                return (object) [
                    'content' => [
                        (object) [
                            'type' => 'tool_use',
                            'name' => 'atualizar_cotacao',
                            'input' => [
                                'reply' => 'Set the unit to pcs for all items.',
                                'fornecedor' => ['nome' => 'ACME'],
                                'itens' => [
                                    ['description' => 'Widget', 'quantity' => 2, 'unit_price' => 5.0, 'unit' => 'pcs'],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $result = $editor->edit(
            ['fornecedor' => ['nome' => 'ACME'], 'itens' => [['description' => 'Widget', 'quantity' => 2, 'unit_price' => 5.0]]],
            'set the unit to pcs for all',
        );

        $this->assertSame('Set the unit to pcs for all items.', $result['reply']);
        $this->assertSame('pcs', $result['draft']['itens'][0]['unit']);
        // The reply must not leak into the draft passed to the resolver.
        $this->assertArrayNotHasKey('reply', $result['draft']);
    }
}
