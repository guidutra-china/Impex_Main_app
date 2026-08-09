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

    /**
     * Extractor fake que devolve um input de tool_use por chamada (fila) e captura
     * o conteúdo enviado em cada uma, para inspecionar prompts de chunk.
     */
    private function extractorWithQueue(array $inputs): DraftExtractor
    {
        return new class($inputs) extends DraftExtractor
        {
            /** @var list<array<int,object>> */
            public array $captured = [];

            public function __construct(private array $inputs) {}

            protected function callModel(ImportTarget $target, array $content): object
            {
                $this->captured[] = $content;
                $input = array_shift($this->inputs);
                if ($input === null) {
                    throw new \RuntimeException('callModel chamado mais vezes que o esperado.');
                }

                return (object) ['content' => [
                    (object) ['type' => 'tool_use', 'name' => $target->extractionToolName(), 'input' => $input],
                ]];
            }
        };
    }

    /** Planilha achatada com $lines linhas no formato do DocumentExtractor. */
    private function spreadsheetText(int $lines): string
    {
        $out = ['Sales Contract | | |'];
        for ($n = 2; $n <= $lines; $n++) {
            $out[] = "Linha {$n}: {$n} | PART-{$n} | 10 | 1.50 | 15.00";
        }

        return implode("\n", $out);
    }

    public function test_small_document_is_extracted_in_a_single_call_without_chunk_instruction(): void
    {
        $extractor = $this->extractorWithQueue([
            ['fornecedor' => ['nome' => 'X'], 'itens' => [['description' => 'A', 'quantity' => 1, 'unit_price' => 2.0]]],
        ]);

        $target = new SupplierQuotationTarget;
        $extractor->extract($target, [TextBlockParam::with($this->spreadsheetText(50))]);

        $this->assertCount(1, $extractor->captured);
        $this->assertSame($target->extractionUserPrompt(), $extractor->captured[0][0]->text);
    }

    public function test_large_spreadsheet_is_extracted_in_chunks_and_items_are_merged_in_order(): void
    {
        // 250 linhas → 3 chunks de 84 (distribuição uniforme): 1-84, 85-168, 169-250.
        $extractor = $this->extractorWithQueue([
            [
                'fornecedor' => ['nome' => 'Nanjing Gencrea', 'currency_code' => 'USD'],
                'itens' => [['part_no' => 'A1', 'description' => 'Item A', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 10]],
            ],
            [
                'fornecedor' => ['nome' => 'Nanjing Gencrea'],
                'itens' => [['part_no' => 'B1', 'description' => 'Item B', 'quantity' => 2, 'unit_price' => 2.0, 'source_row' => 90]],
                'documento_total' => 1234.5,
            ],
            [
                'fornecedor' => ['nome' => 'Nanjing Gencrea'],
                'itens' => [['part_no' => 'C1', 'description' => 'Item C', 'quantity' => 3, 'unit_price' => 3.0, 'source_row' => 200]],
            ],
        ]);

        $draft = $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with($this->spreadsheetText(250))]);

        $this->assertCount(3, $extractor->captured);

        // O prompt de cada chunk delimita o intervalo de linhas.
        $this->assertStringContainsString('até a Linha 84', $extractor->captured[0][0]->text);
        $this->assertStringContainsString('da Linha 85 até a Linha 168', $extractor->captured[1][0]->text);
        $this->assertStringContainsString('da Linha 169 até a Linha 250', $extractor->captured[2][0]->text);

        // Cabeçalho do primeiro chunk; itens concatenados na ordem dos chunks.
        $this->assertSame('USD', $draft['fornecedor']['currency_code']);
        $this->assertSame(['A1', 'B1', 'C1'], array_column($draft['itens'], 'part_no'));
        $this->assertSame(1234.5, $draft['documento_total']);
    }

    public function test_chunked_extraction_deduplicates_items_by_source_row(): void
    {
        // 150 linhas → 2 chunks de 75: 1-75, 76-150. O item da linha 75 volta nos dois.
        $extractor = $this->extractorWithQueue([
            [
                'fornecedor' => ['nome' => 'X'],
                'itens' => [
                    ['part_no' => 'A1', 'description' => 'Item A', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 75],
                ],
            ],
            [
                'fornecedor' => ['nome' => 'X'],
                'itens' => [
                    ['part_no' => 'A1-dup', 'description' => 'Item A repetido', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 75],
                    ['part_no' => 'B1', 'description' => 'Item B', 'quantity' => 2, 'unit_price' => 2.0, 'source_row' => 90],
                ],
            ],
        ]);

        $draft = $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with($this->spreadsheetText(150))]);

        $this->assertSame(['A1', 'B1'], array_column($draft['itens'], 'part_no'));
    }

    public function test_chunked_extraction_tolerates_an_empty_chunk(): void
    {
        $extractor = $this->extractorWithQueue([
            [
                'fornecedor' => ['nome' => 'X'],
                'itens' => [['part_no' => 'A1', 'description' => 'Item A', 'quantity' => 1, 'unit_price' => 1.0]],
            ],
            ['fornecedor' => ['nome' => 'X'], 'itens' => []],
        ]);

        $draft = $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with($this->spreadsheetText(150))]);

        $this->assertSame(['A1'], array_column($draft['itens'], 'part_no'));
    }

    public function test_chunked_extraction_throws_when_all_chunks_are_empty(): void
    {
        $extractor = $this->extractorWithQueue([
            ['fornecedor' => ['nome' => 'X'], 'itens' => []],
            ['fornecedor' => ['nome' => 'X'], 'itens' => []],
        ]);

        $this->expectException(ExtractionFailedException::class);
        $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with($this->spreadsheetText(150))]);
    }
}
