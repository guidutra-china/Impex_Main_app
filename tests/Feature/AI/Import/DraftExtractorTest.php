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

    /**
     * Extractor fake com respostas completas por chamada: cada entrada é
     * [input, stop_reason|null]. Captura o conteúdo enviado como o da fila.
     */
    private function extractorWithResponses(array $responses): DraftExtractor
    {
        return new class($responses) extends DraftExtractor
        {
            /** @var list<array<int,object>> */
            public array $captured = [];

            public function __construct(private array $responses) {}

            protected function callModel(ImportTarget $target, array $content): object
            {
                $this->captured[] = $content;
                $response = array_shift($this->responses);
                if ($response === null) {
                    throw new \RuntimeException('callModel chamado mais vezes que o esperado.');
                }
                [$input, $stopReason] = $response;

                return (object) [
                    'stopReason' => $stopReason,
                    'content' => [
                        (object) ['type' => 'tool_use', 'name' => $target->extractionToolName(), 'input' => $input],
                    ],
                ];
            }
        };
    }

    /** Planilha com poucas linhas mas células muito densas (caso Jinmu: listas de compatibilidade). */
    private function denseSpreadsheetText(int $lines, int $cellChars = 250): string
    {
        $out = ['Delivery List | | |'];
        $filler = str_repeat('Fits: Case-IH 1440 1460 ', (int) ceil($cellChars / 24));
        for ($n = 2; $n <= $lines; $n++) {
            $out[] = "Linha {$n}: {$n} | PART-{$n} | ".substr($filler, 0, $cellChars).' | 10 | 1.50';
        }

        return implode("\n", $out);
    }

    public function test_dense_spreadsheet_with_few_lines_is_chunked_by_character_volume(): void
    {
        // 40 linhas × ~300 chars ≈ 12k chars → 2 chunks mesmo abaixo do limiar de linhas.
        $extractor = $this->extractorWithQueue([
            [
                'fornecedor' => ['nome' => 'Jinmu'],
                'itens' => [['part_no' => 'A1', 'description' => 'Item A', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 5]],
            ],
            [
                'fornecedor' => ['nome' => 'Jinmu'],
                'itens' => [['part_no' => 'B1', 'description' => 'Item B', 'quantity' => 2, 'unit_price' => 2.0, 'source_row' => 30]],
            ],
        ]);

        $draft = $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with($this->denseSpreadsheetText(40))]);

        $this->assertCount(2, $extractor->captured);
        $this->assertStringContainsString('até a Linha 20', $extractor->captured[0][0]->text);
        $this->assertStringContainsString('da Linha 21 até a Linha 40', $extractor->captured[1][0]->text);
        $this->assertSame(['A1', 'B1'], array_column($draft['itens'], 'part_no'));
    }

    public function test_truncated_single_call_is_retried_in_split_ranges(): void
    {
        // Doc pequeno e leve → chamada única; resposta truncada (max_tokens) →
        // o resultado parcial é descartado e o doc é re-extraído em 2 metades.
        $extractor = $this->extractorWithResponses([
            [['fornecedor' => ['nome' => 'X'], 'itens' => []], 'max_tokens'],
            [['fornecedor' => ['nome' => 'X'], 'itens' => [['part_no' => 'A1', 'description' => 'A', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 3]]], null],
            [['fornecedor' => ['nome' => 'X'], 'itens' => [['part_no' => 'B1', 'description' => 'B', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 8]]], 'end_turn'],
        ]);

        $draft = $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with($this->spreadsheetText(10))]);

        $this->assertCount(3, $extractor->captured);
        $this->assertStringContainsString('até a Linha 5', $extractor->captured[1][0]->text);
        $this->assertStringContainsString('da Linha 6 até a Linha 10', $extractor->captured[2][0]->text);
        $this->assertSame(['A1', 'B1'], array_column($draft['itens'], 'part_no'));
    }

    public function test_truncated_chunk_is_split_recursively(): void
    {
        // 2 chunks; o segundo trunca e é dividido em duas metades re-extraídas.
        $extractor = $this->extractorWithResponses([
            [['fornecedor' => ['nome' => 'X'], 'itens' => [['part_no' => 'A1', 'description' => 'A', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 10]]], null],
            [['fornecedor' => ['nome' => 'X'], 'itens' => []], 'max_tokens'],
            [['fornecedor' => ['nome' => 'X'], 'itens' => [['part_no' => 'B1', 'description' => 'B', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 80]]], null],
            [['fornecedor' => ['nome' => 'X'], 'itens' => [['part_no' => 'C1', 'description' => 'C', 'quantity' => 1, 'unit_price' => 1.0, 'source_row' => 140]]], null],
        ]);

        $draft = $extractor->extract(new SupplierQuotationTarget, [TextBlockParam::with($this->spreadsheetText(150))]);

        $this->assertCount(4, $extractor->captured);
        $this->assertStringContainsString('da Linha 76 até a Linha 113', $extractor->captured[2][0]->text);
        $this->assertStringContainsString('da Linha 114 até a Linha 150', $extractor->captured[3][0]->text);
        $this->assertSame(['A1', 'B1', 'C1'], array_column($draft['itens'], 'part_no'));
    }

    public function test_truncated_pdf_throws_clear_density_error(): void
    {
        // PDFs vão como bloco único (sem como dividir por linhas): truncou → erro claro.
        $extractor = $this->extractorWithResponses([
            [['fornecedor' => ['nome' => 'X'], 'itens' => []], 'max_tokens'],
        ]);

        try {
            $extractor->extract(new SupplierQuotationTarget, [(object) ['type' => 'document']]);
            $this->fail('Esperava ExtractionFailedException');
        } catch (ExtractionFailedException $e) {
            $this->assertStringContainsString('denso', $e->getMessage());
        }
    }
}
