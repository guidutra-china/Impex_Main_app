<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Import;

use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\TextBlockParam;
use App\Domain\AI\Import\DocumentExtractor;
use App\Domain\AI\Import\Exceptions\UnsupportedDocumentException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DocumentExtractorTest extends TestCase
{
    public function test_xlsx_becomes_text_block_with_headers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([['Part No', 'Qty', 'Price'], ['AH223014', 6, 100.00]]);
        (new Xlsx($ss))->save($path);

        $blocks = (new DocumentExtractor)->toContentBlocks($path);

        $this->assertCount(1, $blocks);
        $this->assertInstanceOf(TextBlockParam::class, $blocks[0]);
        $this->assertStringContainsString('AH223014', $blocks[0]['text']);
        @unlink($path);
    }

    public function test_xlsx_text_numbers_data_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $ss = new Spreadsheet;
        $ss->getActiveSheet()->fromArray([['Part', 'Qty'], ['AH1', 6], ['AH2', 3]]);
        (new Xlsx($ss))->save($path);

        $blocks = (new DocumentExtractor)->toContentBlocks($path);

        $this->assertStringContainsString('Linha 2:', $blocks[0]['text']);
        $this->assertStringContainsString('Linha 3:', $blocks[0]['text']);
        @unlink($path);
    }

    public function test_xlsx_trims_bloated_used_range(): void
    {
        // Caso real (PI JGYAN-20260818): célula perdida na coluna XEG inflava o
        // used range para 16k colunas → 2,4M chars de pipes vazios → estourava o
        // limite de 1M tokens da API. Célula suja longe + linha vazia no meio.
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([['Part', 'Qty'], ['AH1', 6]]);
        // linha 3 vazia; linha 4 com item; sujeira em XFD1 (célula em branco formatada)
        $sheet->setCellValue('A4', 'AH2');
        $sheet->setCellValue('B4', 3);
        $sheet->setCellValue('XFD1', ' ');
        (new Xlsx($ss))->save($path);

        $text = (new DocumentExtractor)->toContentBlocks($path)[0]['text'];

        $this->assertLessThan(500, strlen($text), 'colunas vazias do used range inflado devem ser aparadas');
        $this->assertStringContainsString('Linha 4: AH2', $text); // numeração real preservada
        $this->assertStringNotContainsString('Linha 3:', $text);  // linha vazia é pulada
        @unlink($path);
    }

    public function test_pdf_becomes_document_block(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%fake\n");

        $blocks = (new DocumentExtractor)->toContentBlocks($path);

        $this->assertCount(1, $blocks);
        $this->assertInstanceOf(DocumentBlockParam::class, $blocks[0]);
        @unlink($path);
    }

    public function test_unsupported_extension_throws(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imp').'.txt';
        file_put_contents($path, 'hello');

        $this->expectException(UnsupportedDocumentException::class);
        (new DocumentExtractor)->toContentBlocks($path);
        @unlink($path);
    }
}
