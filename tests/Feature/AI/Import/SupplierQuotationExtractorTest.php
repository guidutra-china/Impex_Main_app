<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use Anthropic\Messages\TextBlockParam;
use App\Domain\AI\Import\SupplierQuotationExtractor;
use Tests\TestCase;

class SupplierQuotationExtractorTest extends TestCase
{
    public function test_returns_draft_from_forced_tool_call(): void
    {
        $extractor = new class extends SupplierQuotationExtractor
        {
            protected function callModel(array $content): object
            {
                return (object) [
                    'content' => [
                        (object) [
                            'type' => 'tool_use',
                            'name' => 'registrar_cotacao',
                            'input' => [
                                'fornecedor' => ['nome' => 'Nanjing Gencrea'],
                                'itens' => [
                                    ['part_no' => 'AH223014', 'description' => 'Chaffer arm', 'quantity' => 6, 'unit_price' => 100.0],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        };

        $draft = $extractor->extract([TextBlockParam::with('planilha...')]);

        $this->assertSame('Nanjing Gencrea', $draft['fornecedor']['nome']);
        $this->assertCount(1, $draft['itens']);
        $this->assertSame('AH223014', $draft['itens'][0]['part_no']);
        $this->assertSame(100.0, $draft['itens'][0]['unit_price']);
    }
}
