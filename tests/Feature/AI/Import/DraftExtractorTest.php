<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use Anthropic\Messages\TextBlockParam;
use App\Domain\AI\Import\DraftExtractor;
use App\Domain\AI\Import\Exceptions\ExtractionFailedException;
use App\Domain\AI\Import\Targets\ImportTarget;
use App\Domain\AI\Import\Targets\SupplierQuotationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DraftExtractorTest extends TestCase
{
    use RefreshDatabase;

    private function extractorReturning(array $blocks): DraftExtractor
    {
        return new class($blocks) extends DraftExtractor
        {
            public function __construct(private readonly array $blocks) {}

            protected function callModel(ImportTarget $target, array $content): object
            {
                return (object) ['content' => $this->blocks];
            }
        };
    }

    public function test_returns_draft_from_forced_tool_call(): void
    {
        $extractor = $this->extractorReturning([
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
        ]);

        $draft = $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with('planilha...')]);

        $this->assertSame('Nanjing Gencrea', $draft['fornecedor']['nome']);
        $this->assertCount(1, $draft['itens']);
    }

    public function test_throws_when_no_items(): void
    {
        $extractor = $this->extractorReturning([
            (object) ['type' => 'tool_use', 'name' => 'registrar_cotacao', 'input' => ['fornecedor' => ['nome' => 'X'], 'itens' => []]],
        ]);

        $this->expectException(ExtractionFailedException::class);
        $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with('planilha...')]);
    }

    public function test_throws_when_no_tool_call(): void
    {
        $extractor = $this->extractorReturning([(object) ['type' => 'text', 'text' => 'oi']]);

        $this->expectException(ExtractionFailedException::class);
        $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with('planilha...')]);
    }
}
