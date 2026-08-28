<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Import;

use App\Domain\AI\Import\DocumentImageExtractor;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
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

    public function test_xls_memory_drawing_extracted_with_anchor_row(): void
    {
        // Fotos de .xls (BIFF) chegam do reader como MemoryDrawing (imagem em
        // memória), não Drawing — caso real PI2026JG-0068: 172 fotos ignoradas
        // em silêncio pelo gate de instanceof.
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->fromArray([['Part'], ['AH1']]);
        $img = imagecreatetruecolor(40, 30);
        imagefilledrectangle($img, 0, 0, 39, 29, imagecolorallocate($img, 200, 30, 30));
        $drawing = new MemoryDrawing;
        $drawing->setImageResource($img);
        $drawing->setRenderingFunction(MemoryDrawing::RENDERING_PNG);
        $drawing->setMimeType(MemoryDrawing::MIMETYPE_PNG);
        $drawing->setCoordinates('B2');
        $drawing->setWorksheet($sheet);
        $xls = tempnam(sys_get_temp_dir(), 'img').'.xls';
        (new Xls($ss))->save($xls);

        $result = (new DocumentImageExtractor)->extract($xls);

        $this->assertArrayHasKey(2, $result['by_row']);
        $this->assertFileExists($result['by_row'][2]);
        $this->assertNotFalse(@imagecreatefromstring((string) file_get_contents($result['by_row'][2])), 'bytes gravados devem ser uma imagem válida');
        @unlink($xls);
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

    public function test_parse_image_list_extracts_real_images_and_dedupes_repeated_draws(): void
    {
        // num 3 draws the same XObject (object 12) again — spreadsheet-exported PDFs
        // do this for every photo; only the first draw may survive.
        $output = <<<'TXT'
        page   num  type   width height color comp bpc  enc interp  object ID x-ppi y-ppi size ratio
        --------------------------------------------------------------------------------------------
           1     0 image     400   300  rgb     3   8  jpeg   no        12  0   150   150 12.3K 3.5%
           1     1 smask     400   300  gray    1   8  image  no        12  0   150   150 4096B 3.4%
           2     2 image     250   250  rgb     3   8  image  no        18  0   150   150 88.9K  61%
           2     3 image     400   300  rgb     3   8  jpeg   no        12  0   150   150 12.3K 3.5%
        TXT;

        $meta = $this->exposed()->parseList($output);

        $this->assertSame([
            0 => ['page' => 1, 'width' => 400, 'height' => 300],
            2 => ['page' => 2, 'width' => 250, 'height' => 250],
        ], $meta);
    }

    public function test_parse_image_list_returns_null_on_unparseable_output(): void
    {
        $this->assertNull($this->exposed()->parseList("something went wrong\nno data here"));
    }

    public function test_noise_filter_drops_tiny_and_extreme_aspect_keeps_photos_and_unknown(): void
    {
        $e = $this->exposed();

        $this->assertTrue($e->noise(32, 32), 'tiny logo');
        $this->assertTrue($e->noise(368, 1), 'cell border hairline');
        $this->assertTrue($e->noise(1000, 80), '12.5:1 rule/banner');
        $this->assertTrue($e->noise(220, 109), 'small near-square header logo');
        $this->assertTrue($e->noise(109, 78), 'small icon/QR');
        $this->assertFalse($e->noise(200, 200), 'square product photo');
        $this->assertFalse($e->noise(454, 70), 'barbell bar photo (~6.5:1) is a real product');
        $this->assertFalse($e->noise(0, 0), 'unknown dimensions never filtered');
    }

    /** Anonymous subclass exposing the protected parsing/filter seams. */
    private function exposed(): object
    {
        return new class extends DocumentImageExtractor
        {
            public function parseList(string $output): ?array
            {
                return $this->parseImageList($output);
            }

            public function noise(int $w, int $h): bool
            {
                return $this->isNoise($w, $h);
            }
        };
    }
}
