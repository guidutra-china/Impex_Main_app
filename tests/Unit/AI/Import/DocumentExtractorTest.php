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
