<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use Anthropic\Messages\TextBlockParam;
use App\Domain\AI\Import\DocumentClassifier;
use App\Domain\AI\Import\Targets\SupplierQuotationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function classifierReturning(array $blocks): DocumentClassifier
    {
        return new class($blocks) extends DocumentClassifier
        {
            public function __construct(private readonly array $blocks) {}

            protected function callModel(array $content, array $keys, string $hints): object
            {
                return (object) ['content' => $this->blocks];
            }
        };
    }

    public function test_returns_suggestion_from_tool_call(): void
    {
        $classifier = $this->classifierReturning([
            (object) [
                'type' => 'tool_use',
                'name' => 'classificar_documento',
                'input' => ['tipo' => 'supplier_quotation', 'confianca' => 'alta', 'motivo' => 'Preços de fornecedor com MOQ.'],
            ],
        ]);

        $result = $classifier->classify(
            [TextBlockParam::with('doc...')],
            ['supplier_quotation' => new SupplierQuotationTarget],
        );

        $this->assertSame('supplier_quotation', $result['tipo']);
        $this->assertSame('alta', $result['confianca']);
    }

    public function test_unknown_type_from_model_is_normalized_to_desconhecido(): void
    {
        $classifier = $this->classifierReturning([
            (object) [
                'type' => 'tool_use',
                'name' => 'classificar_documento',
                'input' => ['tipo' => 'invoice', 'confianca' => 'alta'],
            ],
        ]);

        $result = $classifier->classify([TextBlockParam::with('doc...')], ['supplier_quotation' => new SupplierQuotationTarget]);

        $this->assertSame('desconhecido', $result['tipo']);
    }

    public function test_missing_tool_call_falls_back_to_desconhecido(): void
    {
        $classifier = $this->classifierReturning([(object) ['type' => 'text', 'text' => 'hm']]);

        $result = $classifier->classify([TextBlockParam::with('doc...')], ['supplier_quotation' => new SupplierQuotationTarget]);

        $this->assertSame('desconhecido', $result['tipo']);
        $this->assertSame('baixa', $result['confianca']);
    }
}
