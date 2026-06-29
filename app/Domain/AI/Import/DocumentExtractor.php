<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Anthropic\Messages\Base64PDFSource;
use Anthropic\Messages\DocumentBlockParam;
use Anthropic\Messages\TextBlockParam;
use App\Domain\AI\Import\Exceptions\UnsupportedDocumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Turns an uploaded supplier-quotation file into Anthropic content blocks.
 * PDFs go as native document blocks; spreadsheets are flattened to text.
 * No LLM call happens here.
 */
class DocumentExtractor
{
    /**
     * @return list<TextBlockParam|DocumentBlockParam>
     */
    public function toContentBlocks(string $filePath): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => [
                DocumentBlockParam::with(
                    source: Base64PDFSource::with(base64_encode((string) file_get_contents($filePath))),
                ),
            ],
            'xlsx', 'xls' => [
                TextBlockParam::with($this->spreadsheetToText($filePath)),
            ],
            default => throw new UnsupportedDocumentException("Formato não suportado: .{$ext}"),
        };
    }

    private function spreadsheetToText(string $filePath): string
    {
        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $lines = [];
        $rowNumber = 0;

        foreach ($sheet->toArray() as $row) {
            $rowNumber++;
            $cells = array_map(
                fn ($cell) => $cell === null ? '' : (string) $cell,
                $row,
            );
            $prefix = $rowNumber === 1 ? '' : "Linha {$rowNumber}: ";
            $lines[] = $prefix.implode(' | ', $cells);
        }

        return implode("\n", $lines);
    }
}
