<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

/**
 * Extracts embedded product images from a quotation file into a temp dir. Best-effort
 * and fully graceful: any failure (unsupported format, missing binary, parse error)
 * yields empty results — never throws.
 *
 * Returns ['by_row' => array<int,string>, 'ordered' => list<string>] of absolute paths.
 * 'by_row' (xlsx) keys by the drawing anchor row; 'ordered' (pdf) is document order.
 */
class DocumentImageExtractor
{
    /**
     * @return array{by_row:array<int,string>,ordered:list<string>}
     */
    public function extract(string $filePath): array
    {
        $dir = dirname($filePath).'/images';

        try {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            return match ($ext) {
                'xlsx', 'xls' => ['by_row' => $this->fromSpreadsheet($filePath, $dir), 'ordered' => []],
                'pdf' => ['by_row' => [], 'ordered' => $this->fromPdf($filePath, $dir)],
                default => ['by_row' => [], 'ordered' => []],
            };
        } catch (\Throwable $e) {
            report($e);

            return ['by_row' => [], 'ordered' => []];
        }
    }

    /**
     * @return array<int,string>
     */
    private function fromSpreadsheet(string $filePath, string $dir): array
    {
        @mkdir($dir, 0775, true);
        $sheet = IOFactory::load($filePath)->getActiveSheet();
        $images = [];

        foreach ($sheet->getDrawingCollection() as $drawing) {
            if (! $drawing instanceof Drawing) {
                continue;
            }

            $row = (int) preg_replace('/\D/', '', $drawing->getCoordinates());
            if ($row <= 1) {
                continue;
            }

            $bytes = $this->drawingBytes($drawing);
            if ($bytes === null || $bytes === '') {
                continue;
            }

            $ext = $drawing->getExtension() ?: 'png';
            $out = $dir.'/'.Str::uuid()->toString().'.'.$ext;
            file_put_contents($out, $bytes);
            $images[$row] = $out;
        }

        return $images;
    }

    /**
     * Reads the raw bytes of an embedded drawing. PhpSpreadsheet exposes the image
     * through a zip:// stream wrapper (getPath), so a plain file_get_contents works
     * for both real filesystem paths and embedded zip entries.
     */
    private function drawingBytes(Drawing $drawing): ?string
    {
        $path = $drawing->getPath();
        if ($path === '') {
            return null;
        }

        $bytes = @file_get_contents($path);

        return $bytes === false ? null : $bytes;
    }

    /**
     * @return list<string>
     */
    private function fromPdf(string $filePath, string $dir): array
    {
        $binary = $this->pdfimagesBinary();
        if ($binary === null) {
            return []; // poppler-utils not installed on this host
        }

        @mkdir($dir, 0775, true);
        $prefix = $dir.'/pdf';

        // Which image objects are real photos vs transparency masks. pdfimages emits
        // an image and its soft-mask (smask) as separate objects; keeping both would
        // double-count and misalign the order matching.
        $keep = $this->realImageObjectNums($binary, $filePath);

        $result = Process::run([$binary, '-png', $filePath, $prefix]);
        if (! $result->successful()) {
            return [];
        }

        // Files are named "<prefix>-<objNum>.png" (zero-padded); sorted index == objNum.
        $all = glob($prefix.'-*.png') ?: [];
        sort($all);

        if ($keep === null) {
            return array_values($all); // couldn't list types → fall back to all
        }

        $images = [];
        foreach ($keep as $num) {
            if (isset($all[$num])) {
                $images[] = $all[$num];
            }
        }

        return array_values($images);
    }

    /**
     * Object numbers whose type is a real "image" (not smask/stencil/mask), in order,
     * via `pdfimages -list`. Returns null if the listing can't be parsed.
     *
     * @return list<int>|null
     */
    private function realImageObjectNums(string $binary, string $filePath): ?array
    {
        $list = Process::run([$binary, '-list', $filePath]);
        if (! $list->successful()) {
            return null;
        }

        $nums = [];
        foreach (preg_split('/\r?\n/', $list->output()) as $line) {
            // columns: page num type width ...
            if (preg_match('/^\s*\d+\s+(\d+)\s+(\S+)\s/', $line, $m) && $m[2] === 'image') {
                $nums[] = (int) $m[1];
            }
        }

        return $nums;
    }

    /**
     * Locate the `pdfimages` binary. PHP-FPM often runs with a minimal PATH that
     * excludes Homebrew (/opt/homebrew/bin), so check common absolute locations
     * first, then fall back to a PATH lookup.
     */
    private function pdfimagesBinary(): ?string
    {
        foreach (['/opt/homebrew/bin/pdfimages', '/usr/local/bin/pdfimages', '/usr/bin/pdfimages'] as $abs) {
            if (is_executable($abs)) {
                return $abs;
            }
        }

        return (new \Symfony\Component\Process\ExecutableFinder)->find('pdfimages') ?: null;
    }
}
