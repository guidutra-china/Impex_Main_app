<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Import;

use App\Domain\AI\Import\PhotoItemMatcher;
use Tests\TestCase;

class PhotoItemMatcherTest extends TestCase
{
    /** Small GD-generated PNG on disk so buildContent produces a real thumbnail. */
    private function pngFile(): string
    {
        $path = sys_get_temp_dir().'/pim_'.uniqid().'.png';
        $im = imagecreatetruecolor(8, 8);
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    /** Matcher whose callModel returns a canned mapear_fotos tool call. */
    private function matcherReturning(array $mapeamentos, array $invertidas = []): PhotoItemMatcher
    {
        return new class($mapeamentos, $invertidas) extends PhotoItemMatcher
        {
            public function __construct(private readonly array $mapeamentos, private readonly array $invertidas)
            {
                // No Anthropic client: callModel is fully overridden.
            }

            protected function callModel(array $content): object
            {
                return (object) ['content' => [
                    (object) ['type' => 'tool_use', 'name' => 'mapear_fotos', 'input' => [
                        'mapeamentos' => $this->mapeamentos,
                        'imagens_invertidas' => $this->invertidas,
                    ]],
                ]];
            }
        };
    }

    /** PNG with a red top row and blue bottom row, to detect vertical flips. */
    private function topBottomPng(): string
    {
        $path = sys_get_temp_dir().'/pim_flip_'.uniqid().'.png';
        $im = imagecreatetruecolor(4, 4);
        $red = imagecolorallocate($im, 255, 0, 0);
        $blue = imagecolorallocate($im, 0, 0, 255);
        imagefilledrectangle($im, 0, 0, 3, 1, $red);
        imagefilledrectangle($im, 0, 2, 3, 3, $blue);
        imagepng($im, $path);
        imagedestroy($im);

        return $path;
    }

    private function topLeftColor(string $path): int
    {
        $im = imagecreatefromstring((string) file_get_contents($path));

        return imagecolorat($im, 0, 0);
    }

    public function test_should_run_only_when_an_item_is_missing_a_photo(): void
    {
        $matcher = $this->matcherReturning([]);
        $pool = [['id' => 0, 'path' => '/tmp/x.png', 'page' => 1]];

        $this->assertFalse($matcher->shouldRun([], [0 => null]), 'empty pool');
        $this->assertFalse($matcher->shouldRun($pool, []), 'no items');
        $this->assertFalse($matcher->shouldRun($pool, [0 => 0, 1 => 0]), 'complete assignment, all pool used');
        $this->assertTrue($matcher->shouldRun($pool, [0 => 0, 1 => null]), 'gap to fill');

        // Todos os itens têm foto mas sobrou imagem sem dono → alinhamento suspeito.
        $twoImages = [
            ['id' => 0, 'path' => '/tmp/x.png', 'page' => 1],
            ['id' => 1, 'path' => '/tmp/y.png', 'page' => 1],
        ];
        $this->assertTrue($matcher->shouldRun($twoImages, [0 => 0]), 'unused pool image');
        $this->assertFalse($matcher->shouldRun($twoImages, [0 => 0, 1 => 1]), 'aligned 1:1');
    }

    public function test_reconcile_merges_vision_mappings_over_deterministic_result(): void
    {
        $png = $this->pngFile();
        $pool = [
            ['id' => 0, 'path' => $png, 'page' => 1],
            ['id' => 1, 'path' => $png, 'page' => 1],
        ];
        $itens = [
            ['description' => 'Kettlebell — 8kg'],
            ['description' => 'Kettlebell — 10kg'],
            ['description' => 'Trampoline'],
        ];

        // Visão: imagem 0 cobre os dois kettlebells; imagem 1 é o trampolim.
        $matcher = $this->matcherReturning([
            ['imagem' => 0, 'itens' => [0, 1]],
            ['imagem' => 1, 'itens' => [2]],
        ]);

        $result = $matcher->reconcile($pool, $itens, [0 => 0, 1 => null, 2 => null]);

        $this->assertSame([0 => 0, 1 => 0, 2 => 1], $result);
        @unlink($png);
    }

    public function test_reconcile_ignores_out_of_range_ids_and_keeps_deterministic_for_unmapped(): void
    {
        $png = $this->pngFile();
        $pool = [['id' => 0, 'path' => $png, 'page' => 1]];
        $itens = [['description' => 'A'], ['description' => 'B']];

        $matcher = $this->matcherReturning([
            ['imagem' => 99, 'itens' => [0]],  // pool id inexistente → ignorado
            ['imagem' => 0, 'itens' => [7]],   // item inexistente → ignorado
        ]);

        $result = $matcher->reconcile($pool, $itens, [0 => 0, 1 => null]);

        $this->assertSame([0 => 0, 1 => null], $result);
        @unlink($png);
    }

    public function test_reconcile_failure_keeps_deterministic_result(): void
    {
        $png = $this->pngFile();
        $matcher = new class extends PhotoItemMatcher
        {
            public function __construct() {}

            protected function callModel(array $content): object
            {
                throw new \RuntimeException('API down');
            }
        };

        $result = $matcher->reconcile(
            [['id' => 0, 'path' => $png, 'page' => 1]],
            [['description' => 'A']],
            [0 => null],
        );

        $this->assertSame([0 => null], $result);
        @unlink($png);
    }

    public function test_uncovered_item_loses_photo_when_vision_assigned_it_elsewhere(): void
    {
        $png = $this->pngFile();
        $pool = [
            ['id' => 0, 'path' => $png, 'page' => 1],
            ['id' => 1, 'path' => $png, 'page' => 1],
        ];
        $itens = [
            ['description' => 'Barra 700mm'],   // foto não existe no PDF; det deu img0 por posição
            ['description' => 'Slam ball'],     // dono real da img0 segundo a visão
            ['description' => 'Trampolim'],     // não mapeado e img1 não reivindicada → mantém det
        ];

        $matcher = $this->matcherReturning([['imagem' => 0, 'itens' => [1]]]);

        $result = $matcher->reconcile($pool, $itens, [0 => 0, 1 => null, 2 => 1]);

        // Item 0 perdeu a img0 (a visão a deu ao item 1); item 2 mantém a img1 (ninguém reivindicou).
        $this->assertSame([0 => null, 1 => 0, 2 => 1], $result);
        @unlink($png);
    }

    public function test_reconcile_flips_images_flagged_as_upside_down(): void
    {
        $flipped = $this->topBottomPng();
        $upright = $this->topBottomPng();
        $pool = [
            ['id' => 0, 'path' => $flipped, 'page' => 1],
            ['id' => 1, 'path' => $upright, 'page' => 1],
        ];

        $before = $this->topLeftColor($flipped);
        $matcher = $this->matcherReturning([], invertidas: [0, 99]); // 99 fora do pool → ignorado

        $matcher->reconcile($pool, [['description' => 'A'], ['description' => 'B']], [0 => 0, 1 => 1]);

        $this->assertNotSame($before, $this->topLeftColor($flipped), 'flagged image must be flipped in place');
        $this->assertSame($before, $this->topLeftColor($upright), 'unflagged image untouched');
        @unlink($flipped);
        @unlink($upright);
    }

    public function test_reconcile_without_apply_mappings_fixes_orientation_but_keeps_assignment(): void
    {
        $png = $this->topBottomPng();
        $pool = [['id' => 0, 'path' => $png, 'page' => 1]];
        $before = $this->topLeftColor($png);

        // Visão sugere mapear a imagem para o item 1 — mas o determinístico não era
        // suspeito, então só a orientação é aplicada.
        $matcher = $this->matcherReturning([['imagem' => 0, 'itens' => [1]]], invertidas: [0]);

        $result = $matcher->reconcile($pool, [['description' => 'A'], ['description' => 'B']], [0 => 0, 1 => null], applyMappings: false);

        $this->assertSame([0 => 0, 1 => null], $result, 'mapping unchanged');
        $this->assertNotSame($before, $this->topLeftColor($png), 'orientation still fixed');
        @unlink($png);
    }

    public function test_page_renders_are_included_in_the_vision_content(): void
    {
        $png = $this->topBottomPng();
        $render = $this->topBottomPng();

        $matcher = new class extends PhotoItemMatcher
        {
            public array $captured = [];

            public function __construct() {}

            protected function callModel(array $content): object
            {
                $this->captured = $content;

                return (object) ['content' => []];
            }
        };

        $pool = [['id' => 0, 'path' => $png, 'page' => 1]];
        $itens = [['description' => 'A']];

        $matcher->reconcile($pool, $itens, [0 => null], applyMappings: true, pageRenders: []);
        $without = count($matcher->captured);

        $matcher->reconcile($pool, $itens, [0 => null], applyMappings: true, pageRenders: [['page' => 1, 'path' => $render]]);
        $with = count($matcher->captured);

        // Cada página adiciona um bloco de texto + um bloco de imagem.
        $this->assertSame($without + 2, $with);
        @unlink($png);
        @unlink($render);
    }

    public function test_reconcile_with_unreadable_images_returns_input_untouched(): void
    {
        $matcher = $this->matcherReturning([['imagem' => 0, 'itens' => [0]]]);

        $result = $matcher->reconcile(
            [['id' => 0, 'path' => '/nonexistent/file.png', 'page' => 1]],
            [['description' => 'A']],
            [0 => null],
        );

        $this->assertSame([0 => null], $result);
    }
}
