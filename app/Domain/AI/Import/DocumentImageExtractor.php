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
 * Returns ['by_row' => array<int,string>, 'ordered' => list<array{path:string,page:int,width:int,height:int}>].
 * 'by_row' (xlsx) keys by the drawing anchor row; 'ordered' (pdf) is document order with
 * the page each image appears on (page 0 = unknown, when `pdfimages -list` can't be parsed).
 */
class DocumentImageExtractor
{
    /**
     * @return array{by_row:array<int,string>,ordered:list<array{path:string,page:int,width:int,height:int}>}
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
     * @return list<array{path:string,page:int,width:int,height:int}>
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
        // double-count and misalign the order matching. The listing also carries the
        // page and pixel dimensions used for page-aware matching and noise filtering.
        $list = Process::run([$binary, '-list', $filePath]);
        $meta = $list->successful() ? $this->parseImageList($list->output()) : null;

        $result = Process::run([$binary, '-png', $filePath, $prefix]);
        if (! $result->successful()) {
            return [];
        }

        // Files are named "<prefix>-<objNum>.png" (zero-padded); sorted index == objNum.
        $all = glob($prefix.'-*.png') ?: [];
        sort($all);

        if ($meta === null) {
            // Couldn't list types → keep all files; page unknown, dimensions from the file.
            $images = [];
            $seenHashes = [];
            foreach (array_values($all) as $path) {
                [$w, $h] = $this->pngDimensions($path);
                if ($this->isNoise($w, $h) || $this->isDuplicate($path, $seenHashes)) {
                    continue;
                }
                $images[] = ['path' => $path, 'page' => 0, 'width' => $w, 'height' => $h];
            }

            return $images;
        }

        $images = [];
        $seenHashes = [];
        foreach ($meta as $num => $info) {
            if (! isset($all[$num]) || $this->isNoise($info['width'], $info['height'])) {
                continue;
            }
            if ($this->isDuplicate($all[$num], $seenHashes)) {
                continue;
            }
            $images[] = ['path' => $all[$num], 'page' => $info['page'], 'width' => $info['width'], 'height' => $info['height']];
        }

        return $images;
    }

    /**
     * A mesma foto re-embutida como um NOVO objeto (bytes idênticos, object ID
     * diferente — caso real: slam ball e trampolim 2x na mesma página) passa pelo
     * dedup de object ID; o hash do conteúdo extraído pega. Duplicatas só inflam
     * o pool e confundem o pareamento (determinístico e por visão).
     *
     * @param  array<string,bool>  $seenHashes
     */
    private function isDuplicate(string $path, array &$seenHashes): bool
    {
        $hash = @md5_file($path);
        if ($hash === false) {
            return false;
        }

        if (isset($seenHashes[$hash])) {
            return true;
        }

        $seenHashes[$hash] = true;

        return false;
    }

    /**
     * Parses `pdfimages -list` output into page/dimensions per real "image" object
     * (not smask/stencil/mask), keyed by instance number. Returns null when no data
     * line can be parsed (unexpected format).
     *
     * Spreadsheet-exported PDFs often DRAW the same image XObject several times
     * (real case: every product photo appeared twice per page); each draw is one
     * `-list` line, so instances are deduplicated by the `object` column and only
     * the first draw survives — otherwise the pool doubles and the page zip shifts.
     *
     * @return array<int,array{page:int,width:int,height:int}>|null
     */
    protected function parseImageList(string $output): ?array
    {
        $meta = [];
        $parsedAny = false;
        $seenObjects = [];

        foreach (preg_split('/\r?\n/', $output) as $line) {
            // columns: page num type width height color comp bpc enc interp object ID ...
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            if (count($parts) < 5 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])
                || ! ctype_digit($parts[3]) || ! ctype_digit($parts[4])) {
                continue;
            }
            $parsedAny = true;
            if ($parts[2] !== 'image') {
                continue;
            }

            // Inline images have no object number ("[inline]") — never deduped.
            $object = $parts[10] ?? null;
            if ($object !== null && ctype_digit($object) && $object !== '0') {
                if (isset($seenObjects[$object])) {
                    continue; // repeated draw of the same picture
                }
                $seenObjects[$object] = true;
            }

            $meta[(int) $parts[1]] = ['page' => (int) $parts[0], 'width' => (int) $parts[3], 'height' => (int) $parts[4]];
        }

        return $parsedAny ? $meta : null;
    }

    /**
     * Cell borders, rules, header logos and other decorations are not product
     * photos. Rules (medidas do caso real Hebei Yangrun):
     * - min side < 64px: hairlines and icons;
     * - min side < 128px with near-square aspect (< 3:1): header logos/QR codes
     *   (220x109, 109x78) — these entered the pool and shifted the page zip;
     * - aspect > 8:1: rules/banners. The limit is generous because elongated
     *   product photos are real — barbell bars are ~6.5:1 (433x66) and MUST pass.
     * Unknown dimensions (0) are never filtered — better a spurious photo than a
     * silently missing one.
     */
    protected function isNoise(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $min = min($width, $height);
        $aspect = max($width, $height) / $min;

        return $min < 64 || ($min < 128 && $aspect < 3) || $aspect > 8;
    }

    /** @return array{0:int,1:int} width/height of a PNG on disk, [0,0] when unreadable. */
    private function pngDimensions(string $path): array
    {
        $size = @getimagesize($path);

        return $size === false ? [0, 0] : [(int) $size[0], (int) $size[1]];
    }

    /**
     * Renderiza as páginas do PDF como PNG (pdftoppm, mesmo pacote poppler) para
     * dar contexto de layout ao passe de visão — sem ver a página inteira, o
     * modelo não consegue saber qual foto pertence a qual linha da tabela.
     * Best-effort: sem binário ou em falha, retorna [].
     *
     * @return list<array{page:int,path:string}>
     */
    public function renderPages(string $filePath): array
    {
        $binary = $this->popplerBinary('pdftoppm');
        if ($binary === null) {
            return [];
        }

        $dir = dirname($filePath).'/images';
        @mkdir($dir, 0775, true);
        $prefix = $dir.'/pagerender';

        // 80 dpi ≈ 950px de largura por página A4 — suficiente para ler a tabela.
        $result = Process::run([$binary, '-png', '-r', '80', $filePath, $prefix]);
        if (! $result->successful()) {
            return [];
        }

        $pages = [];
        foreach (glob($prefix.'-*.png') ?: [] as $file) {
            if (preg_match('/-0*(\d+)\.png$/', $file, $m)) {
                $pages[] = ['page' => (int) $m[1], 'path' => $file];
            }
        }
        usort($pages, fn (array $a, array $b) => $a['page'] <=> $b['page']);

        return $pages;
    }

    private function pdfimagesBinary(): ?string
    {
        return $this->popplerBinary('pdfimages');
    }

    /**
     * Locate a poppler binary. PHP-FPM often runs with a minimal PATH that
     * excludes Homebrew (/opt/homebrew/bin), so check common absolute locations
     * first, then fall back to a PATH lookup.
     */
    private function popplerBinary(string $name): ?string
    {
        foreach (["/opt/homebrew/bin/{$name}", "/usr/local/bin/{$name}", "/usr/bin/{$name}"] as $abs) {
            if (is_executable($abs)) {
                return $abs;
            }
        }

        return (new \Symfony\Component\Process\ExecutableFinder)->find($name) ?: null;
    }
}
