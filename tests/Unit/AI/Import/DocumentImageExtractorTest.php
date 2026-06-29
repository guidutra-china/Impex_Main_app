<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Import;

use App\Domain\AI\Import\DocumentImageExtractor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DocumentImageExtractorTest extends TestCase
{
    public function test_xlsx_drawing_extracted_with_anchor_row(): void
    {
        $png = sys_get_temp_dir().'/pix_'.uniqid().'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([['Part'], ['AH1']]);
        $drawing = new Drawing;
        $drawing->setPath($png);
        $drawing->setCoordinates('B2');
        $drawing->setWorksheet($sheet);
        $xlsx = tempnam(sys_get_temp_dir(), 'img').'.xlsx';
        (new Xlsx($ss))->save($xlsx);

        $result = (new DocumentImageExtractor)->extract($xlsx);

        $this->assertArrayHasKey(2, $result['by_row']);
        $this->assertFileExists($result['by_row'][2]);
        @unlink($png);
        @unlink($xlsx);
    }

    public function test_unsupported_returns_empty_gracefully(): void
    {
        $txt = tempnam(sys_get_temp_dir(), 'img').'.txt';
        file_put_contents($txt, 'x');

        $result = (new DocumentImageExtractor)->extract($txt);

        $this->assertSame([], $result['by_row']);
        $this->assertSame([], $result['ordered']);
        @unlink($txt);
    }
}
